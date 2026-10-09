<?php

use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\DiagnosisCode;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\EncounterDiagnosis;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\NhifBillConfirmation;
use App\Models\NhifClaim;
use App\Models\NhifClaimItem;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\NHIF\ClaimCalculationService;
use App\Services\NHIF\NhifFolioService;
use App\Services\NHIF\NhifHttpClient;
use Illuminate\Validation\ValidationException;

function finalClaimContext(?Hospital $hospital = null): array
{
    seedRolesAndPermissions();

    $hospital = $hospital ?? Hospital::factory()->create([
        'code' => 'FAC-999',
    ]);

    $department = Department::factory()->create([
        'hospital_id' => $hospital->id,
    ]);

    $patient = Patient::factory()->create();

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => '201627864221',
        'card_number' => '1234567890',
        'nhif_facility_code' => 'FAC-999',
        'membership_number' => 'MEM-123456',
    ]);

    $user = User::factory()->create();
    $user->assignRole('hospital-admin');
    Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $hospital->id,
    ]);

    $doctorUser = User::factory()->create();
    $doctorUser->assignRole('doctor');
    $doctorStaff = Staff::factory()->create([
        'user_id' => $doctorUser->id,
        'hospital_id' => $hospital->id,
    ]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $doctorStaff->id,
        'nhif_practitioner_no' => 'MCT-9999',
    ]);

    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'status' => ClinicalEncounter::STATUS_COMPLETED,
        'encounter_type' => 'opd',
        'started_at' => now()->subHours(2),
        'ended_at' => now(),
    ]);

    $diagCode = DiagnosisCode::firstOrCreate(
        ['code' => 'J18.9'],
        ['description' => 'Pneumonia, unspecified', 'system' => 'icd10']
    );

    EncounterDiagnosis::create([
        'clinical_encounter_id' => $encounter->id,
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'status' => EncounterDiagnosis::STATUS_CONFIRMED,
        'diagnosed_by' => $doctorUser->id,
        'diagnosed_at' => now(),
        'nhif_disease_code' => 'J18.9',
        'nhif_disease_name' => 'Pneumonia, unspecified',
    ]);

    return compact('hospital', 'department', 'patient', 'insurance', 'user', 'practitioner', 'encounter');
}

function createPreApprovalSession(array $ctx, array $overrides = []): NhifPreApprovalSession
{
    return NhifPreApprovalSession::create(array_merge([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'nhif_authorization_no' => $ctx['insurance']->nhif_authorization_no,
        'facility_code' => $ctx['hospital']->code,
        'status' => NhifPreApprovalSession::STATUS_ISSUED,
        'created_by' => $ctx['user']->id,
    ], $overrides));
}

test('1. NHIF patient with issued consultation is included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    // Create session consultation
    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Practitioner Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    expect($items)->toHaveCount(1)
        ->and($items[0]['nhif_item_code'])->toBe('10001')
        ->and($items[0]['unit_price'])->toBe(7000.0)
        ->and($items[0]['quantity'])->toBe(1)
        ->and($items[0]['amount_claimed'])->toBe(7000.0);
});

test('2. NHIF patient with issued lab service is included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $test = LaboratoryTest::factory()->create([
        'name' => 'Full Blood Count (FBC)',
        'nhif_code' => '20001',
        'price' => 5000.0,
    ]);

    $order = LaboratoryOrder::factory()->create([
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => 'completed',
    ]);

    $orderItem = LaboratoryOrderItem::factory()->create([
        'laboratory_order_id' => $order->id,
        'laboratory_test_id' => $test->id,
        'status' => 'resulted',
    ]);

    LaboratoryResult::factory()->create([
        'laboratory_order_item_id' => $orderItem->id,
        'status' => LaboratoryResult::STATUS_RELEASED,
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    $labItem = collect($items)->firstWhere('nhif_item_code', '20001');
    expect($labItem)->not->toBeNull()
        ->and($labItem['unit_price'])->toBe(5000.0)
        ->and($labItem['amount_claimed'])->toBe(5000.0);
});

test('3. NHIF patient with dispensed medication is included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $med = Medication::factory()->create([
        'generic_name' => 'Ibuprofen 400mg',
        'nhif_code' => '11014',
        'price' => 31.0,
    ]);

    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $ctx['encounter']->id,
    ]);

    $orderItem = PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'medication_id' => $med->id,
        'quantity' => 15,
        'status' => 'active',
    ]);

    $dispensing = Dispensing::factory()->create([
        'prescription_order_id' => $order->id,
        'status' => 'completed',
    ]);

    DispensingItem::factory()->create([
        'dispensing_id' => $dispensing->id,
        'prescription_order_item_id' => $orderItem->id,
        'dispensed_quantity' => 15,
        'status' => 'dispensed',
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    $medItem = collect($items)->firstWhere('nhif_item_code', '11014');
    expect($medItem)->not->toBeNull()
        ->and($medItem['quantity'])->toBe(15)
        ->and($medItem['unit_price'])->toBe(31.0)
        ->and($medItem['amount_claimed'])->toBe(465.0);
});

test('4. Ordered but unissued service is NOT included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $med = Medication::factory()->create([
        'generic_name' => 'Paracetamol 500mg',
        'nhif_code' => 'MED-UNISSUED',
        'price' => 50.0,
    ]);

    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $ctx['encounter']->id,
    ]);

    PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'medication_id' => $med->id,
        'quantity' => 20,
        'status' => 'active', // not dispensed!
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    expect(collect($items)->firstWhere('nhif_item_code', 'MED-UNISSUED'))->toBeNull();
});

test('5. Rejected service is NOT included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $session = createPreApprovalSession($ctx, ['status' => NhifPreApprovalSession::STATUS_REJECTED]);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_MEDICATION,
        'nhif_item_code' => 'REJECTED-001',
        'nhif_item_name' => 'Rejected Medication',
        'quantity' => 10,
        'unit_price' => 100.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED,
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    expect(collect($items)->firstWhere('nhif_item_code', 'REJECTED-001'))->toBeNull();
});

test('6. Pending service is NOT included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $session = createPreApprovalSession($ctx, ['status' => NhifPreApprovalSession::STATUS_SUBMITTED]);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
        'nhif_item_code' => 'PENDING-001',
        'nhif_item_name' => 'Pending Approval Lab',
        'quantity' => 1,
        'unit_price' => 12000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL,
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    expect(collect($items)->firstWhere('nhif_item_code', 'PENDING-001'))->toBeNull();
});

test('7. Cancelled service is NOT included in claim', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $med = Medication::factory()->create([
        'generic_name' => 'Cancelled Med',
        'nhif_code' => 'CANCELLED-001',
        'price' => 100.0,
    ]);

    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $ctx['encounter']->id,
    ]);

    $orderItem = PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'medication_id' => $med->id,
        'quantity' => 10,
        'status' => PrescriptionOrderItem::STATUS_CANCELLED,
    ]);

    $items = $calc->getClaimableIssuedServices($ctx['encounter']);

    expect(collect($items)->firstWhere('nhif_item_code', 'CANCELLED-001'))->toBeNull();
});

test('8 & 9. Correct quantity × unit price calculation and total sum', function () {
    $ctx = finalClaimContext();
    $calc = app(ClaimCalculationService::class);

    $items = [
        ['amount_claimed' => 7000.0],
        ['amount_claimed' => 5000.0],
        ['amount_claimed' => 3000.0],
        ['amount_claimed' => 465.0],
    ];

    $total = $calc->calculateTotal($items);
    expect($total)->toBe(15465.0);
});

test('10 & 11. SignFolio and SubmitFolio receive the same backend-calculated total', function () {
    $ctx = finalClaimContext();

    // Setup Consultation (7000), Lab (5000), Medication (3465) = 15465
    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_LAB,
        'nhif_item_code' => '20001',
        'nhif_item_name' => 'FBC Test',
        'quantity' => 1,
        'unit_price' => 5000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_MEDICATION,
        'nhif_item_code' => '30001',
        'nhif_item_name' => 'Antibiotic',
        'quantity' => 1,
        'unit_price' => 3465.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);

    // SignFolio must receive 15465
    $mockClient->shouldReceive('signFolio')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['AmountClaimed'] === 15465.0 && $p['AuthorizationNo'] === '201627864221'))
        ->andReturn([
            'success' => true,
            'data' => ['Status' => 'Signed'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-1',
            'operation' => 'SignFolio',
        ]);

    // SubmitFolio must receive 15465 and all 3 items
    $mockClient->shouldReceive('submitFolio')
        ->once()
        ->with(Mockery::on(function ($p) {
            return $p['AmountClaimed'] === 15465.0
                && count($p['FolioItems']) === 3
                && $p['MainDiagnosisCode'] === 'J18.9';
        }))
        ->andReturn([
            'success' => true,
            'data' => [
                'SubmissionID' => 'SUB-999',
                'SubmissionNo' => 'SN-999',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-2',
            'operation' => 'SubmitFolio',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);
    $folioService = app(NhifFolioService::class);

    $claim = $folioService->prepareFolio($ctx['encounter'], requestAs($ctx['user']));
    expect((float) $claim->nhif_allowable_total)->toBe(15465.0);

    $signedClaim = $folioService->sign($claim, ['signature_method' => 'Manual'], requestAs($ctx['user']));
    expect($signedClaim->status)->toBe(NhifClaim::STATUS_SIGNED);

    $submittedClaim = $folioService->submit($signedClaim, requestAs($ctx['user']));
    expect($submittedClaim->status)->toBe(NhifClaim::STATUS_SUBMITTED)
        ->and($submittedClaim->nhif_submission_no)->toBe('SN-999');
});

test('13 & 14. FolioDiseases and MainDiagnosisCode come from actual clinical diagnosis', function () {
    $ctx = finalClaimContext();
    $folioService = app(NhifFolioService::class);

    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    // Add secondary diagnosis
    $secCode = DiagnosisCode::firstOrCreate(
        ['code' => 'K29.7'],
        ['description' => 'Gastritis, unspecified', 'system' => 'icd10']
    );

    EncounterDiagnosis::create([
        'clinical_encounter_id' => $ctx['encounter']->id,
        'diagnosis_code_id' => $secCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
        'status' => EncounterDiagnosis::STATUS_CONFIRMED,
        'diagnosed_by' => $ctx['user']->id,
        'diagnosed_at' => now(),
        'nhif_disease_code' => 'K29.7',
        'nhif_disease_name' => 'Gastritis, unspecified',
    ]);

    $claim = $folioService->prepareFolio($ctx['encounter'], requestAs($ctx['user']));
    $payload = $folioService->buildFolioPayload($claim);

    expect($payload['MainDiagnosisCode'])->toBe('J18.9')
        ->and($payload['FolioDiseases'])->toHaveCount(2);
});

test('15. SignFolio failure halts workflow and sets signing_failed status', function () {
    $ctx = finalClaimContext();

    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')->once()->andReturn([
        'success' => false,
        'data' => [],
        'http_status' => 400,
        'error' => 'Invalid Card Signature',
        'correlation_id' => 'err-1',
        'operation' => 'SignFolio',
    ]);
    $mockClient->shouldNotReceive('submitFolio');

    app()->instance(NhifHttpClient::class, $mockClient);
    $folioService = app(NhifFolioService::class);

    $res = $folioService->finalizeClaim($ctx['encounter'], ['signature_method' => 'Manual'], requestAs($ctx['user']));

    expect($res['success'])->toBeFalse()
        ->and($res['step'])->toBe('sign')
        ->and($res['claim']->status)->toBe(NhifClaim::STATUS_SIGNING_FAILED);
});

test('16. SubmitFolio failure halts workflow before bill confirmation and sets submission_failed status', function () {
    $ctx = finalClaimContext();

    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')->once()->andReturn([
        'success' => true,
        'data' => ['Status' => 'Signed'],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'c1',
        'operation' => 'SignFolio',
    ]);
    $mockClient->shouldReceive('submitFolio')->once()->andReturn([
        'success' => false,
        'data' => [],
        'http_status' => 400,
        'error' => 'NHIF OCS Down',
        'correlation_id' => 'c2',
        'operation' => 'SubmitFolio',
    ]);
    $mockClient->shouldNotReceive('requestBillConfirmation');

    app()->instance(NhifHttpClient::class, $mockClient);
    $folioService = app(NhifFolioService::class);

    $res = $folioService->finalizeClaim($ctx['encounter'], ['signature_method' => 'Manual'], requestAs($ctx['user']));

    expect($res['success'])->toBeFalse()
        ->and($res['step'])->toBe('submit')
        ->and($res['claim']->status)->toBe(NhifClaim::STATUS_SUBMISSION_FAILED);
});

test('17. BillConfirmation failure allows retry without creating duplicate folio', function () {
    $ctx = finalClaimContext();

    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')->once()->andReturn([
        'success' => true,
        'data' => ['Status' => 'Signed'],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'c1',
        'operation' => 'SignFolio',
    ]);
    $mockClient->shouldReceive('submitFolio')->once()->andReturn([
        'success' => true,
        'data' => ['SubmissionID' => 'SUB-1', 'SubmissionNo' => 'SN-1'],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'c2',
        'operation' => 'SubmitFolio',
    ]);
    $mockClient->shouldReceive('requestBillConfirmation')->once()->andReturn([
        'success' => false,
        'data' => [],
        'http_status' => 400,
        'error' => 'OTP Timeout',
        'correlation_id' => 'c3',
        'operation' => 'RequestBillConfirmation',
    ]);

    app()->instance(NhifHttpClient::class, $mockClient);
    $folioService = app(NhifFolioService::class);

    $res = $folioService->finalizeClaim($ctx['encounter'], ['signature_method' => 'Manual'], requestAs($ctx['user']));

    expect($res['success'])->toBeFalse()
        ->and($res['step'])->toBe('bill_confirmation')
        ->and($res['claim']->status)->toBe(NhifClaim::STATUS_BILL_CONFIRMATION_FAILED);

    // Now retry bill confirmation
    $mockClient2 = Mockery::mock(NhifHttpClient::class);
    $mockClient2->shouldReceive('requestBillConfirmation')->once()->andReturn([
        'success' => true,
        'data' => ['ConfirmationCode' => 'CONF-777'],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'c4',
        'operation' => 'RequestBillConfirmation',
    ]);
    app()->instance(NhifHttpClient::class, $mockClient2);
    $folioService2 = app(NhifFolioService::class);

    $retryRes = $folioService2->retryBillConfirmation($res['claim'], requestAs($ctx['user']));

    expect($retryRes['success'])->toBeTrue()
        ->and($retryRes['claim']->status)->toBe(NhifClaim::STATUS_FINALIZED)
        ->and(NhifClaim::where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('18. Already finalized claim cannot be submitted again', function () {
    $ctx = finalClaimContext();
    $folioService = app(NhifFolioService::class);

    $claim = NhifClaim::factory()->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => NhifClaim::STATUS_FINALIZED,
        'nhif_claim_reference' => 'SUB-EXISTING',
    ]);

    expect(fn () => $folioService->submit($claim, requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

test('22. Full NHIF claim flow works end-to-end', function () {
    $ctx = finalClaimContext();

    // Setup issued consultation, lab, and medication
    $session = createPreApprovalSession($ctx);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => NhifPreApprovalSessionItem::SOURCE_CONSULTATION,
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Consultation',
        'quantity' => 1,
        'unit_price' => 7000.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);

    $mockClient->shouldReceive('signFolio')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['AmountClaimed'] === 7000.0))
        ->andReturn([
            'success' => true,
            'data' => ['Status' => 'Signed'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'sig-1',
            'operation' => 'SignFolio',
        ]);

    $mockClient->shouldReceive('submitFolio')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['AmountClaimed'] === 7000.0 && $p['MainDiagnosisCode'] === 'J18.9'))
        ->andReturn([
            'success' => true,
            'data' => ['SubmissionID' => 'SUB-ENDTOEND', 'SubmissionNo' => 'SN-100'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'sub-1',
            'operation' => 'SubmitFolio',
        ]);

    $mockClient->shouldReceive('requestBillConfirmation')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['TotalAmount'] === 7000.0 && $p['FacilityCode'] === 'FAC-999'))
        ->andReturn([
            'success' => true,
            'data' => ['ConfirmationCode' => 'CONF-E2E-123'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'bill-1',
            'operation' => 'RequestBillConfirmation',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);
    $folioService = app(NhifFolioService::class);

    $res = $folioService->finalizeClaim($ctx['encounter'], [
        'signature_method' => 'Manual',
        'signed_by_name' => 'Pharmacist John',
    ], requestAs($ctx['user']));

    expect($res['success'])->toBeTrue()
        ->and($res['step'])->toBe('finalized')
        ->and($res['claim']->status)->toBe(NhifClaim::STATUS_FINALIZED)
        ->and($res['claim']->confirmation_code)->toBe('CONF-E2E-123')
        ->and($res['claim']->nhif_submission_no)->toBe('SN-100')
        ->and((float) $res['claim']->nhif_allowable_total)->toBe(7000.0);
});

test('23. Requested and dispensed medication with code snapshot or fallback appears on finalize claim', function () {
    $ctx = finalClaimContext();

    $medication = Medication::factory()->create([
        'code' => '11098',
        'generic_name' => 'Amoxicillin 500mg',
        'nhif_code' => null, // Not explicitly set on catalog row
        'price' => 2500.0,
    ]);

    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => PrescriptionOrder::STATUS_ACTIVE,
    ]);

    $orderItem = PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'medication_id' => $medication->id,
        'quantity' => 2,
        'status' => PrescriptionOrderItem::STATUS_ACTIVE,
        'medication_code_snapshot' => '11098',
    ]);

    $dispensing = Dispensing::factory()->create([
        'prescription_order_id' => $order->id,
        'status' => Dispensing::STATUS_COMPLETED,
    ]);

    DispensingItem::factory()->create([
        'dispensing_id' => $dispensing->id,
        'prescription_order_item_id' => $orderItem->id,
        'dispensed_quantity' => 2,
        'status' => DispensingItem::STATUS_DISPENSED,
    ]);

    $calculator = app(ClaimCalculationService::class);
    $readiness = $calculator->validateClaimReadiness($ctx['encounter']);

    expect($readiness['is_ready'])->toBeTrue();
    $itemCodes = array_column($readiness['items'], 'nhif_item_code');
    expect($itemCodes)->toContain('11098');

    $medItem = collect($readiness['items'])->firstWhere('nhif_item_code', '11098');
    expect($medItem)->not->toBeNull();
    expect((int) $medItem['quantity'])->toBe(2);
    expect((float) $medItem['amount_claimed'])->toBe(5000.0);
});

test('24. Claim submission uses authorized VisitTypeID from verification record to avoid NHIF mismatch', function () {
    $ctx = finalClaimContext();

    // Record verification with VisitTypeID = 2 (Inpatient authorization)
    \App\Models\NhifMemberVerification::create([
        'patient_insurance_id' => $ctx['insurance']->id,
        'identifier_type' => 'CardNo',
        'identifier_value' => $ctx['insurance']->card_number,
        'nhif_operation' => 'VerifyCard',
        'nhif_authorization_no' => $ctx['insurance']->nhif_authorization_no,
        'verification_status' => \App\Models\NhifMemberVerification::STATUS_VERIFIED,
        'verify_card_request' => [
            'cardNo' => $ctx['insurance']->card_number,
            'visitTypeID' => 2,
        ],
    ]);

    $session = createPreApprovalSession($ctx);
    \App\Models\NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => \App\Models\NhifPreApprovalSessionItem::SOURCE_LAB,
        'nhif_item_code' => '20001',
        'nhif_item_name' => 'Full Blood Count',
        'quantity' => 1,
        'unit_price' => 10000.0,
        'approval_status' => \App\Models\NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
    ]);

    $folioService = app(NhifFolioService::class);
    $claim = $folioService->prepareFolio($ctx['encounter'], requestAs($ctx['user']));

    expect((int) $claim->visit_type_id)->toBe(2);
    expect($claim->patient_type_code)->toBe('IN');

    $payload = $folioService->buildFolioPayload($claim);
    expect($payload['VisitTypeID'])->toBe(2);
    expect($payload['PatientTypeCode'])->toBe('IN');
    expect($payload['DateAdmitted'])->not->toBeNull();
});

test('25. Claim readiness preview succeeds for in-progress encounter with completed consultation', function () {
    $ctx = finalClaimContext();
    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_IN_PROGRESS]);

    // Create consultation for this encounter
    \App\Models\Consultation::create([
        'clinical_encounter_id' => $ctx['encounter']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'created_by' => $ctx['user']->id,
        'status' => \App\Models\Consultation::STATUS_COMPLETED,
        'completed_at' => now(),
        'completed_by' => $ctx['user']->id,
        'chief_complaint' => 'Fever and cough',
        'history_presenting_illness' => 'Patient presented with 3 days fever',
        'assessment_summary' => 'Upper respiratory tract infection',
        'plan_summary' => 'Supportive therapy',
    ]);

    $calculator = app(ClaimCalculationService::class);
    $readiness = $calculator->validateClaimReadiness($ctx['encounter']);

    expect($readiness['is_ready'])->toBeTrue();
    $checklist = collect($readiness['checklist'])->firstWhere('key', 'services_completed');
    expect($checklist['passed'])->toBeTrue();
});






