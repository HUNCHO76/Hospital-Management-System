<?php

/**
 * NhifAuthorizationServiceTest
 *
 * Tests the generic NhifAuthorizationService and the lab/medication
 * bridge services that delegate to it.
 *
 * Ensures NHIF patients get pre-approval sessions, cash patients bypass
 * NHIF entirely, ineligible items are blocked, and idempotency is upheld.
 */

use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Service;
use App\Models\Staff;
use App\Services\NHIF\NhifAuthorizationService;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifLabAuthorizationService;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function nhifAuthContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'AUTH-FAC-01']);
    $service = Service::factory()->create(['code' => 'LAB-FBC', 'category' => 'laboratory']);

    $mapping = NhifServiceMapping::create([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'nhif_item_code' => '3001',
        'nhif_item_name' => 'Full Blood Count',
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'is_active' => true,
        'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
        'created_by' => 1,
        'updated_by' => 1,
    ]);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-NHIF-LAB-001',
        'nhif_scheme_id' => null,
        'is_primary' => true,
    ]);

    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $labTest = LaboratoryTest::factory()->create(['status' => 'active']);

    $order = LaboratoryOrder::factory()->create([
        'clinical_encounter_id' => $encounter->id,
        'status' => LaboratoryOrder::STATUS_ORDERED,
    ]);

    $item = LaboratoryOrderItem::factory()->create([
        'laboratory_order_id' => $order->id,
        'laboratory_test_id' => $labTest->id,
        'test_name' => 'Full Blood Count',
        'status' => LaboratoryOrderItem::STATUS_ORDERED,
    ]);

    Charge::factory()->create([
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'status' => Charge::STATUS_POSTED,
        'unit_price' => 3500,
        'net_amount' => 3500,
    ]);

    $user = userWithRole('doctor');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    return compact('hospital', 'service', 'mapping', 'patient', 'insurance', 'encounter', 'order', 'item', 'user');
}

function nhifLabAcceptedResponse(string $itemCode = '3001'): array
{
    return [
        'success' => true,
        'data' => [
            'requestNo' => 'REQ-LAB-001',
            'services' => [[
                'requestedServiceID' => 'svc-lab-1',
                'requestID' => 'req-lab-1',
                'itemCode' => $itemCode,
                'status' => 'Accepted',
                'requiresApproval' => false,
                'percentCovered' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

// ---------------------------------------------------------------------------
// 1. Lab order for NHIF patient
// ---------------------------------------------------------------------------

test('lab order for NHIF patient creates and submits a pre-approval session item', function () {
    $ctx = nhifAuthContext();

    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldReceive('requestPreauthorizedServices')->once()->andReturn(nhifLabAcceptedResponse())
    );

    $results = app(NhifLabAuthorizationService::class)
        ->authorizeOrder($ctx['order'], $ctx['encounter'], requestAs($ctx['user']));

    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('authorized');

    expect(NhifPreApprovalSession::where('patient_id', $ctx['patient']->id)->count())->toBe(1);
    expect(NhifPreApprovalSessionItem::where('source_type', NhifPreApprovalSessionItem::SOURCE_LAB)
        ->where('source_id', $ctx['item']->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Cash patient bypasses NHIF entirely
// ---------------------------------------------------------------------------

test('lab order for cash patient bypasses NHIF authorization entirely', function () {
    $ctx = nhifAuthContext();

    // Override insurance to have no authorization number (simulating cash patient).
    $ctx['insurance']->update([
        'nhif_authorization_no' => null,
        'verification_status' => PatientInsurance::VERIFICATION_PENDING,
    ]);

    // NhifHttpClient must NOT be called.
    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldNotReceive('requestPreauthorizedServices')
    );

    $results = app(NhifLabAuthorizationService::class)
        ->authorizeOrder($ctx['order'], $ctx['encounter'], requestAs($ctx['user']));

    expect($results)->toBeEmpty();
    expect(NhifPreApprovalSession::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 3. Ineligible item (scheme services check)
// ---------------------------------------------------------------------------

test('lab order for NHIF patient with scheme_id and ineligible item returns not_eligible', function () {
    $ctx = nhifAuthContext();

    // Set nhif_scheme_id on insurance but do NOT create a matching NhifSchemeService row.
    $ctx['insurance']->update(['nhif_scheme_id' => 8001]);

    // NhifHttpClient must NOT be called — blocked before it gets to NHIF.
    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldNotReceive('requestPreauthorizedServices')
    );

    $results = app(NhifLabAuthorizationService::class)
        ->authorizeOrder($ctx['order'], $ctx['encounter'], requestAs($ctx['user']));

    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('not_eligible');
});

// ---------------------------------------------------------------------------
// 4. Idempotency — duplicate call reuses existing session item
// ---------------------------------------------------------------------------

test('duplicate lab authorization request is idempotent and reuses existing session item', function () {
    $ctx = nhifAuthContext();

    // Create a pre-existing session + item for this lab order item.
    $existingSession = NhifPreApprovalSession::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'status' => NhifPreApprovalSession::STATUS_SUBMITTED,
        'nhif_authorization_no' => $ctx['insurance']->nhif_authorization_no,
    ]);
    NhifPreApprovalSessionItem::factory()->create([
        'nhif_pre_approval_session_id' => $existingSession->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
        'source_id' => $ctx['item']->id,
        'nhif_item_code' => '3001',
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED,
        'nhif_status' => 'Accepted',
    ]);

    // NhifHttpClient must NOT be called — idempotency check short-circuits.
    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldNotReceive('requestPreauthorizedServices')
    );

    $result = app(NhifAuthorizationService::class)->authorize(
        $ctx['insurance'],
        NhifPreApprovalSessionItem::SOURCE_LAB,
        $ctx['item']->id,
        '3001',
        'Full Blood Count',
        3500.0,
        1,
        $ctx['encounter'],
        requestAs($ctx['user']),
    );

    expect($result['status'])->toBe('authorized');
    // Only one session should exist — no new one created.
    expect(NhifPreApprovalSession::where('patient_id', $ctx['patient']->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 5. IDOR: cannot authorize against another patient's insurance
// ---------------------------------------------------------------------------

test('authorize() returns not_covered when insurance has no authorization number', function () {
    $ctx = nhifAuthContext();

    // Insurance with no authorization number — mimics another patient's unverified record.
    $otherInsurance = PatientInsurance::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_PENDING,
        'nhif_authorization_no' => null,
    ]);

    $this->mock(NhifHttpClient::class, fn ($mock) => $mock->shouldNotReceive('requestPreauthorizedServices')
    );

    $result = app(NhifAuthorizationService::class)->authorize(
        $otherInsurance,
        NhifPreApprovalSessionItem::SOURCE_LAB,
        $ctx['item']->id,
        '3001',
        'Full Blood Count',
        3500.0,
        1,
        $ctx['encounter'],
        requestAs($ctx['user']),
    );

    expect($result['status'])->toBe('not_covered');
    expect(NhifPreApprovalSession::where('patient_id', $otherInsurance->patient_id)->count())->toBe(0);
});
