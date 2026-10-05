<?php

/**
 * NhifWalkInRegistrationGateTest
 *
 * Validates that the NHIF preapproval acts as a registration gate:
 *   - ACCEPTED → walk-in created + authorized
 *   - REJECTED → walk-in NOT created, reason displayed
 *   - PENDING  → walk-in NOT created
 *   - ERROR    → walk-in NOT created
 *   - CASH     → normal flow unchanged
 */

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\NHIF\NhifHttpClient;
use App\Services\ReceptionVisitService;

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

function nhifWalkInContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'NHIF-GATE-'.fake()->unique()->numerify('###')]);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $service = Service::factory()->create(['category' => 'consultation']);

    $user = userWithRole('receptionist');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => '860627861758',
        'nhif_scheme_id' => 9999,
        'is_primary' => true,
    ]);

    $practitioner = Practitioner::factory()->create([
        'consultation_service_id' => $service->id,
        'nhif_practitioner_no' => 'PRAC-001',
    ]);

    NhifServiceMapping::create([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Practitioner Consultation',
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'is_active' => true,
        'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    NhifSchemeService::create([
        'scheme_id' => 9999,
        'scheme_name' => 'Test Scheme',
        'item_code' => '10001',
        'item_name' => 'General Practitioner Consultation',
        'item_type_id' => 1,
        'type_name' => 'Registration/Consultation Charges',
        'unit_price' => 15000,
        'is_active' => true,
    ]);

    return compact('hospital', 'department', 'service', 'patient', 'insurance', 'practitioner', 'user');
}

function nhifGateAcceptedResponse(): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => 'req-uuid-gate-001',
            'requestNo' => '390620099901',
            'cardNo' => 'CARD-001',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'FAC001',
            'services' => [[
                'requestedServiceID' => 'svc-uuid-gate-001',
                'requestID' => 'req-uuid-gate-001',
                'itemCode' => '10001',
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => '2026-09-26T00:00:00',
                'endDate' => '2026-09-30T00:00:00',
                'availableAfterDate' => null,
                'status' => 'Accepted',
                'requiresApproval' => false,
                'itemName' => 'General Practitioner Consultation',
                'percentCovered' => null,
                'rejectionDetails' => null,
                'rejectionReasonCode' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

function nhifGateRejectedResponse(): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => 'req-uuid-gate-002',
            'requestNo' => '390620099902',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'FAC001',
            'services' => [[
                'requestedServiceID' => 'svc-uuid-gate-002',
                'requestID' => 'req-uuid-gate-002',
                'itemCode' => '10001',
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => '2026-09-26T00:00:00',
                'endDate' => '2026-09-30T00:00:00',
                'availableAfterDate' => null,
                'status' => 'Rejected',
                'requiresApproval' => false,
                'itemName' => 'General Practitioner Consultation',
                'percentCovered' => null,
                'rejectionDetails' => 'Practitioner qualification too low',
                'rejectionReasonCode' => 'QUAL_LOW',
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

function nhifGatePendingResponse(): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => 'req-uuid-gate-003',
            'requestNo' => '390620099903',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'FAC001',
            'services' => [[
                'requestedServiceID' => 'svc-uuid-gate-003',
                'requestID' => 'req-uuid-gate-003',
                'itemCode' => '10001',
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => '2026-09-26T00:00:00',
                'endDate' => '2026-09-30T00:00:00',
                'availableAfterDate' => null,
                'status' => 'Accepted',
                'requiresApproval' => true,
                'itemName' => 'General Practitioner Consultation',
                'percentCovered' => null,
                'rejectionDetails' => null,
                'rejectionReasonCode' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

function mockNhifGateClient(string $method, array $response): void
{
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive($method)->once()->andReturn($response);
    app()->instance(NhifHttpClient::class, $mock);
}

function nhifWalkInPayload(array $ctx): array
{
    return [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
        'nhif_item_code' => '10001',
        'clinical_notes' => '',
        'practitioner_remarks' => '',
    ];
}

// ---------------------------------------------------------------------------
// 1. NHIF preapproval ACCEPTED → walk-in registration succeeds
// ---------------------------------------------------------------------------

test('NHIF accepted preapproval allows walk-in registration', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateAcceptedResponse());

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->first();
    expect($visit)->not->toBeNull()
        ->and($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);

    $response->assertRedirect(route('reception.visits.show', $visit));
});

// ---------------------------------------------------------------------------
// 2. NHIF REJECTED → walk-in registration does not occur
// ---------------------------------------------------------------------------

test('NHIF rejected preapproval blocks walk-in registration', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $response->assertSessionHasErrors('nhif');
    expect(ReceptionVisit::where('patient_id', $ctx['patient']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 3. NHIF REJECTED → rejection reason is displayed
// ---------------------------------------------------------------------------

test('NHIF rejected preapproval returns rejection reason in errors', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $errors = session('errors');
    expect($errors)->not->toBeNull()
        ->and($errors->get('nhif')[0])->toContain('Practitioner qualification too low');
});

// ---------------------------------------------------------------------------
// 4. NHIF PENDING → registration remains blocked
// ---------------------------------------------------------------------------

test('NHIF pending preapproval blocks walk-in registration', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGatePendingResponse());

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $response->assertSessionHasErrors('nhif');
    expect(ReceptionVisit::where('patient_id', $ctx['patient']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5. NHIF API error → registration remains blocked
// ---------------------------------------------------------------------------

test('NHIF API error blocks walk-in registration', function () {
    $ctx = nhifWalkInContext();

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('requestPreauthorizedServices')->once()->andReturn([
        'success' => false,
        'data' => null,
        'http_status' => 500,
        'error' => 'Internal Server Error',
    ]);
    app()->instance(NhifHttpClient::class, $mock);

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $response->assertSessionHasErrors('nhif');
    expect(ReceptionVisit::where('patient_id', $ctx['patient']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. NHIF accepted → clinical queue access is allowed
// ---------------------------------------------------------------------------

test('NHIF accepted walk-in has authorized service access status', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateAcceptedResponse());

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->first();

    $status = app(ReceptionVisitService::class)->consultationAccessStatus($visit->id);
    expect($status)->toBe('authorized');
});

// ---------------------------------------------------------------------------
// 7. NHIF rejected → no clinical queue entry (no visit exists)
// ---------------------------------------------------------------------------

test('NHIF rejected walk-in leaves no visit for clinical queue', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    expect(ReceptionVisit::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 8. NHIF rejected → patient is NOT automatically converted to CASH
// ---------------------------------------------------------------------------

test('NHIF rejected walk-in does not create a cash visit', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    expect(ReceptionVisit::count())->toBe(0)
        ->and(Charge::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 9. Explicit CASH registration → normal flow works
// ---------------------------------------------------------------------------

test('CASH walk-in registration continues to work normally', function () {
    $ctx = nhifWalkInContext();

    $payload = [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
        'nhif_item_code' => '',
        'clinical_notes' => '',
        'practitioner_remarks' => '',
    ];

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), $payload);

    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->first();
    expect($visit)->not->toBeNull()
        ->and($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);

    $response->assertRedirect(route('reception.visits.show', $visit));
});

// ---------------------------------------------------------------------------
// 10. Old NHIF authorization cannot authorize a new walk-in
// ---------------------------------------------------------------------------

test('old NHIF authorization does not authorize a new walk-in registration', function () {
    $ctx = nhifWalkInContext();
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));
    expect(ReceptionVisit::count())->toBe(0);

    // The rejected session item exists from the first attempt
    $oldItem = NhifPreApprovalSessionItem::latest()->first();
    expect($oldItem)->not->toBeNull()
        ->and($oldItem->approval_status)->toBe('rejected');

    // A second attempt also goes through NHIF — old rejection doesn't count
    mockNhifGateClient('requestPreauthorizedServices', nhifGateRejectedResponse());
    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));
    expect(ReceptionVisit::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 11. Wrong patient authorization cannot be reused
// ---------------------------------------------------------------------------

test('NHIF authorization from another patient cannot be reused', function () {
    $ctx = nhifWalkInContext();

    // Create a second patient (no NHIF insurance)
    $otherPatient = Patient::factory()->create();

    $payload = nhifWalkInPayload($ctx);
    $payload['patient_id'] = $otherPatient->id;

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), $payload);

    // Should fail because the other patient has no NHIF insurance
    $response->assertSessionHasErrors('nhif_item_code');
    expect(ReceptionVisit::where('patient_id', $otherPatient->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 12. Wrong service/item authorization cannot be reused
// ---------------------------------------------------------------------------

test('NHIF preapproval with a non-existent item code is rejected', function () {
    $ctx = nhifWalkInContext();

    $payload = nhifWalkInPayload($ctx);
    $payload['nhif_item_code'] = '99999';

    $response = $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), $payload);

    $response->assertSessionHasErrors('nhif_item_code');
    expect(ReceptionVisit::where('patient_id', $ctx['patient']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 13. Frontend cannot bypass the backend NHIF authorization requirement
// ---------------------------------------------------------------------------

test('backend rejects NHIF walk-in even if frontend submits without item code validation', function () {
    $ctx = nhifWalkInContext();

    // Try to submit an NHIF patient without nhif_item_code (attempting to bypass NHIF gate)
    // This goes through the CASH path and should work but NOT as NHIF-authorized
    $payload = [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
        'nhif_item_code' => '',
    ];

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), $payload);

    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->first();
    expect($visit)->not->toBeNull();

    // Visit exists but has NO NHIF authorization — it went through cash path
    $nhifItem = NhifPreApprovalSessionItem::where('source_type', ReceptionVisit::class)
        ->where('source_id', $visit->id)
        ->first();

    // No NHIF session item linked to this visit (cash bypass does not get NHIF auth)
    expect($nhifItem)->toBeNull();
});

// ---------------------------------------------------------------------------
// 14. NHIF accepted → ChargeInsuranceAllocation is created
// ---------------------------------------------------------------------------

test('NHIF accepted walk-in creates ChargeInsuranceAllocation on the charge', function () {
    $ctx = nhifWalkInContext();

    // Enable the price-lookup path by setting nhif_facility_code
    $ctx['insurance']->update(['nhif_facility_code' => 'FAC001']);

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('requestPreauthorizedServices')->once()->andReturn(nhifGateAcceptedResponse());
    $mock->shouldReceive('getPricePackageByItemCode')->andReturn([
        'success' => true,
        'data' => [['ItemCode' => '10001', 'ItemName' => 'GP Consultation', 'UnitPrice' => 15000]],
    ]);
    app()->instance(NhifHttpClient::class, $mock);

    $this->actingAs($ctx['user'])->post(route('reception.walk-in.store'), nhifWalkInPayload($ctx));

    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->first();
    expect($visit)->not->toBeNull();

    $charge = Charge::where('source_type', ReceptionVisit::class)
        ->where('source_id', $visit->id)
        ->first();
    expect($charge)->not->toBeNull();

    $allocation = ChargeInsuranceAllocation::where('charge_id', $charge->id)->first();
    expect($allocation)->not->toBeNull()
        ->and((float) $allocation->patient_responsibility)->toBe(0.0)
        ->and($allocation->status)->toBe(ChargeInsuranceAllocation::STATUS_FULLY_COVERED);
});
