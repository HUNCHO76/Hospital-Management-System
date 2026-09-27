<?php

/**
 * NhifConsultationAuthorizationTest
 *
 * Tests the NHIF Service Authorization Gate for consultation charges.
 * Verifies that an NHIF-accepted consultation proceeds to the clinical queue
 * without cash payment, while cash patients and NHIF edge cases are handled
 * correctly.
 */

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\FinancialAuthorizationService;
use App\Services\NHIF\NhifConsultationAuthorizationService;
use App\Services\NHIF\NhifHttpClient;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function consultAuthContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'FAC001']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $service = Service::factory()->create(['code' => 'CONSULT-GP', 'category' => 'consultation']);

    $user = userWithRole('receptionist');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $mapping = NhifServiceMapping::create([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'nhif_item_code' => '5024',
        'nhif_item_name' => 'General Consultation',
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'is_active' => true,
        'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => '860627861758',
        'is_primary' => true,
    ]);

    $practitioner = Practitioner::factory()->create([
        'consultation_service_id' => $service->id,
    ]);

    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
    ]);

    $account = PatientAccount::factory()->create([
        'patient_id' => $patient->id,
        'hospital_id' => $hospital->id,
    ]);

    $invoice = Invoice::factory()->create([
        'patient_account_id' => $account->id,
        'status' => Invoice::STATUS_POSTED,
    ]);

    $charge = Charge::factory()->create([
        'source_type' => ReceptionVisit::class,
        'source_id' => $visit->id,
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'status' => Charge::STATUS_POSTED,
        'unit_price' => 5000,
        'net_amount' => 5000,
        'currency' => 'TZS',
        'invoice_id' => $invoice->id,
    ]);

    return compact('hospital', 'department', 'service', 'mapping', 'patient', 'account', 'insurance', 'practitioner', 'visit', 'charge', 'invoice', 'user');
}

function nhifConsultAcceptedResponse(string $itemCode = '5024', bool $requiresApproval = false): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => 'req-uuid-001',
            'requestNo' => '390620082451',
            'cardNo' => 'CARD-001',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'FAC001',
            'services' => [[
                'requestedServiceID' => 'svc-uuid-001',
                'requestID' => 'req-uuid-001',
                'itemCode' => $itemCode,
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => '2026-09-26T00:00:00',
                'endDate' => '2026-09-30T00:00:00',
                'availableAfterDate' => null,
                'status' => 'Accepted',
                'requiresApproval' => $requiresApproval,
                'itemName' => 'General Consultation',
                'percentCovered' => null,
                'rejectionDetails' => null,
                'rejectionReasonCode' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

function nhifConsultRejectedResponse(string $itemCode = '5024'): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => 'req-uuid-002',
            'requestNo' => '390620082452',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'FAC001',
            'services' => [[
                'requestedServiceID' => 'svc-uuid-002',
                'requestID' => 'req-uuid-002',
                'itemCode' => $itemCode,
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => '2026-09-26T00:00:00',
                'endDate' => '2026-09-30T00:00:00',
                'availableAfterDate' => null,
                'status' => 'Rejected',
                'requiresApproval' => false,
                'itemName' => 'General Consultation',
                'percentCovered' => null,
                'rejectionDetails' => 'Service not covered under this scheme',
                'rejectionReasonCode' => 'NOT_COVERED',
            ]],
        ],
        'http_status' => 200,
        'error' => null,
    ];
}

function mockConsultClient(string $method, array $response): void
{
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive($method)->once()->andReturn($response);
    app()->instance(NhifHttpClient::class, $mock);
}

// ---------------------------------------------------------------------------
// 1. NHIF accepted — ChargeInsuranceAllocation created, patient_responsibility=0
// ---------------------------------------------------------------------------

test('NHIF accepted consultation creates fully-covered insurance allocation', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultAcceptedResponse());

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('authorized');

    $allocation = ChargeInsuranceAllocation::where('charge_id', $ctx['charge']->id)->first();
    expect($allocation)->not->toBeNull();
    expect((float) $allocation->patient_responsibility)->toBe(0.0);
    expect($allocation->status)->toBe(ChargeInsuranceAllocation::STATUS_FULLY_COVERED);
});

// ---------------------------------------------------------------------------
// 2. NHIF accepted — FinancialAuthorizationService returns authorized
// ---------------------------------------------------------------------------

test('NHIF accepted consultation is financially authorized without cash payment', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultAcceptedResponse());

    $svc = app(NhifConsultationAuthorizationService::class);
    $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    $financialAuth = app(FinancialAuthorizationService::class);
    $status = $financialAuth->statusForSource(ReceptionVisit::class, $ctx['visit']->id);

    expect($status)->toBe(FinancialAuthorizationService::STATUS_AUTHORIZED);
});

// ---------------------------------------------------------------------------
// 3. NHIF pending approval — no allocation, financial status blocked
// ---------------------------------------------------------------------------

test('NHIF pending consultation does not create allocation and returns pending', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultAcceptedResponse('5024', true));

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('pending');
    expect(ChargeInsuranceAllocation::where('charge_id', $ctx['charge']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. NHIF rejected — no allocation, returns rejected with reason
// ---------------------------------------------------------------------------

test('NHIF rejected consultation does not create allocation and returns rejected', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultRejectedResponse());

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('rejected');
    expect($result['rejection_reason'])->toContain('not covered');
    expect(ChargeInsuranceAllocation::where('charge_id', $ctx['charge']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5. No NHIF mapping — returns not_covered, no allocation
// ---------------------------------------------------------------------------

test('charge with no NHIF mapping returns not_covered', function () {
    $ctx = consultAuthContext();
    $ctx['mapping']->update(['is_active' => false]);

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('not_covered');
    expect(ChargeInsuranceAllocation::where('charge_id', $ctx['charge']->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. No NHIF insurance (cash patient) — returns not_covered
// ---------------------------------------------------------------------------

test('cash patient without NHIF insurance returns not_covered', function () {
    $ctx = consultAuthContext();
    $ctx['insurance']->update(['nhif_authorization_no' => null]);

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('not_covered');
});

// ---------------------------------------------------------------------------
// 7. NHIF partial coverage — patient_responsibility > 0
// ---------------------------------------------------------------------------

test('NHIF partial coverage sets patient_responsibility to uncovered portion', function () {
    $ctx = consultAuthContext();

    $partialResponse = nhifConsultAcceptedResponse();
    $partialResponse['data']['services'][0]['percentCovered'] = 80.0;

    mockConsultClient('requestPreauthorizedServices', $partialResponse);

    $svc = app(NhifConsultationAuthorizationService::class);
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('authorized');

    $allocation = ChargeInsuranceAllocation::where('charge_id', $ctx['charge']->id)->first();
    expect($allocation)->not->toBeNull();
    expect((float) $allocation->patient_responsibility)->toBe(1000.0); // 20% of 5000
    expect((float) $allocation->covered_amount)->toBe(4000.0);
    expect($allocation->status)->toBe(ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED);
});

// ---------------------------------------------------------------------------
// 8. Idempotency — repeated calls reuse existing session item
// ---------------------------------------------------------------------------

test('repeated authorization calls do not create duplicate RequestServices records', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultAcceptedResponse());

    $svc = app(NhifConsultationAuthorizationService::class);
    $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    // Second call — no NHIF client call should be made (mock not re-set)
    $result = $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    expect($result['status'])->toBe('authorized');

    $sessionCount = NhifPreApprovalSession::where('patient_id', $ctx['patient']->id)->count();
    expect($sessionCount)->toBe(1);
});

// ---------------------------------------------------------------------------
// 9. Cash patient — unpaid consultation is payment_required
// ---------------------------------------------------------------------------

test('unpaid cash consultation remains payment_required', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $service = Service::factory()->create(['code' => 'CONSULT-GP', 'category' => 'consultation']);
    $patient = Patient::factory()->create();
    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
    ]);
    $account = PatientAccount::factory()->create([
        'patient_id' => $patient->id,
        'hospital_id' => $hospital->id,
    ]);
    $charge = Charge::factory()->create([
        'source_type' => ReceptionVisit::class,
        'source_id' => $visit->id,
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'status' => Charge::STATUS_POSTED,
        'unit_price' => 5000,
        'net_amount' => 5000,
    ]);
    $invoice = Invoice::factory()->create([
        'patient_account_id' => $account->id,
        'status' => Invoice::STATUS_POSTED,
    ]);
    $charge->update(['invoice_id' => $invoice->id]);

    $financialAuth = app(FinancialAuthorizationService::class);
    expect($financialAuth->statusForSource(ReceptionVisit::class, $visit->id))
        ->toBe(FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED);
});

// ---------------------------------------------------------------------------
// 10. Security — client cannot fake NHIF approval status via form input
// ---------------------------------------------------------------------------

test('sendToService rejects NHIF patient with pending approval despite any form input', function () {
    $ctx = consultAuthContext();

    // Create a session item in pending_approval state (no ChargeInsuranceAllocation created)
    $session = NhifPreApprovalSession::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'status' => NhifPreApprovalSession::STATUS_SUBMITTED,
    ]);
    NhifPreApprovalSessionItem::factory()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => ReceptionVisit::class,
        'source_id' => $ctx['visit']->id,
        'nhif_item_code' => '5024',
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL,
        'unit_price' => 5000,
        'quantity' => 1,
    ]);

    // Mark visit as ready_for_service
    $ctx['visit']->update(['status' => ReceptionVisit::STATUS_READY_FOR_SERVICE]);

    // Attempt to send to service as authenticated user
    $this->actingAs($ctx['user'])
        ->post(route('reception.visits.send-to-service', $ctx['visit']))
        ->assertSessionHasErrors('financial_authorization');

    expect($ctx['visit']->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);
});

// ---------------------------------------------------------------------------
// 11. NhifPreApprovalItem shown on visit show page
// ---------------------------------------------------------------------------

test('reception visit show page includes nhifPreApprovalItem when NHIF session exists', function () {
    $ctx = consultAuthContext();
    mockConsultClient('requestPreauthorizedServices', nhifConsultAcceptedResponse());

    $svc = app(NhifConsultationAuthorizationService::class);
    $svc->authorizeConsultation($ctx['visit'], $ctx['charge'], requestAs($ctx['user']));

    $this->actingAs($ctx['user'])
        ->get(route('reception.visits.show', $ctx['visit']))
        ->assertInertia(fn ($page) => $page
            ->component('reception/visits/show')
            ->has('nhifPreApprovalItem')
            ->where('nhifPreApprovalItem.approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED)
        );
});
