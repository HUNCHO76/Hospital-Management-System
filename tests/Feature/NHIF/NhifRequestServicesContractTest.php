<?php

/**
 * NhifRequestServicesContractTest
 *
 * Tests the verified NHIF RequestServices contract:
 *   POST /api/PreApprovals/RequestServices
 *
 * Covers the exact example from the NHIF specification:
 *   authorizationNo=860627861758, itemCode=5024
 *   Response: status=Accepted, requiresApproval=false
 *
 * Also covers the negative/edge cases required by the definition of done.
 */

use App\Models\Hospital;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifPreApprovalSessionService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function contractContext(): array
{
    // Use hospital->code as the NHIF facility code (nhif_facility_code column doesn't exist yet)
    $hospital = Hospital::factory()->create(['code' => 'FAC123']);
    $user = userWithRole('nurse');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $patient = Patient::factory()->create([
        'first_name' => 'Olivia',
        'last_name' => 'Martin',
        'gender' => 'Male',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => '860627861758',
        'card_number' => 'Card-0005',
    ]);

    return compact('hospital', 'patient', 'insurance', 'user');
}

function nhifAcceptedResponse(): array
{
    return [
        'success' => true,
        'data' => [
            'requestID' => '1e594867-4868-436a-910e-d23c578a2b4a',
            'requestNo' => '390620082451',
            'cardNo' => 'Card-0005',
            'schemeID' => 1001,
            'productCode' => null,
            'firstName' => 'Olivia',
            'lastName' => 'Martin',
            'dateOfBirth' => '1980-01-01',
            'gender' => 'Male',
            'practitionerNo' => 'MCT20240628',
            'authorizationNo' => '860627861758',
            'facilityCode' => '01141',
            'clinicalNotes' => 'Patient has a history of hypertension.',
            'createdBy' => 'test',
            'dateCreated' => '2026-09-26T12:16:11.765506Z',
            'services' => [
                [
                    'requestedServiceID' => '612e44bb-7e4b-4304-b5f0-2d4c74f39444',
                    'requestID' => '1e594867-4868-436a-910e-d23c578a2b4a',
                    'itemCode' => '5024',
                    'usage' => 'Consultation',
                    'quantityRequested' => 1,
                    'effectiveDate' => '2026-09-26T00:00:00',
                    'endDate' => '2026-09-30T00:00:00',
                    'availableAfterDate' => '2026-09-30T00:00:00',
                    'status' => 'Accepted',
                    'requiresApproval' => false,
                    'remarks' => 'Initial consultation',
                    'rejectionDetails' => null,
                    'rejectionReasonCode' => null,
                    'authorizationNo' => '860627861758',
                    'createdBy' => 'test',
                    'dateCreated' => '2026-09-26T15:16:09.78',
                    'itemName' => 'Antinuclear Antibodies - (ANA)',
                    'percentCovered' => null,
                ],
            ],
        ],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'test-correlation-id',
        'operation' => 'RequestServices',
    ];
}

// ---------------------------------------------------------------------------
// 1. Exact NHIF contract example — Accepted + requiresApproval=false
// ---------------------------------------------------------------------------

test('submit stores nhif requestID from NHIF response', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->nhif_request_id)->toBe('1e594867-4868-436a-910e-d23c578a2b4a');
});

test('submit stores nhif requestNo from NHIF response', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->nhif_request_no)->toBe('390620082451');
});

test('submit stores authorizationNo on session', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->nhif_authorization_no)->toBe('860627861758');
});

test('submit stores requestedServiceID on item', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_requested_service_id)->toBe('612e44bb-7e4b-4304-b5f0-2d4c74f39444');
});

test('submit stores itemCode on item', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_item_code)->toBe('5024');
});

test('submit stores NHIF-returned itemName separately from the submitted item name', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024',
        'nhif_item_name' => 'Consultation',  // submitted name
        'quantity' => 1,
        'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_item_name)->toBe('Consultation');                           // original, unchanged
    expect($item->nhif_item_name_returned)->toBe('Antinuclear Antibodies - (ANA)'); // from NHIF response
});

test('submit stores raw nhif_status on item', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_status)->toBe('Accepted');
});

test('submit stores nhif_requires_approval on item', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_requires_approval)->toBeFalse();
});

test('submit stores nhif_available_after_date on item', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->nhif_available_after_date)->not()->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Accepted + requiresApproval=false → immediately eligible, no approval poll
// ---------------------------------------------------------------------------

test('accepted item is immediately eligible for issue without syncStatus', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    // Session should be STATUS_ACCEPTED — no approval polling needed
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_ACCEPTED);
    // Item approval_status should be ACCEPTED, not pending/submitted
    expect($updated->items->first()->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);
    // Session is immediately issuable
    expect($updated->isIssuable())->toBeTrue();
});

test('item with Accepted + requiresApproval=false does not need approval polling', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    // syncStatus should reject STATUS_ACCEPTED (not STATUS_SUBMITTED)
    $this->expectException(ValidationException::class);
    app(NhifPreApprovalSessionService::class)->syncStatus($updated, requestAs($ctx['user']));
});

// ---------------------------------------------------------------------------
// 3. requiresApproval=true → item stays PENDING_APPROVAL
// ---------------------------------------------------------------------------

test('service with requiresApproval=true stays pending_approval after submit', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'lab', 'source_id' => null,
        'nhif_item_code' => 'LAB001', 'nhif_item_name' => 'CBC', 'quantity' => 1, 'unit_price' => 5000,
    ]], requestAs($ctx['user']));

    $response = nhifAcceptedResponse();
    $response['data']['services'][0]['itemCode'] = 'LAB001';
    $response['data']['services'][0]['requiresApproval'] = true;

    mockNhifClient('requestPreauthorizedServices', $response);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->items->first()->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL);
    // Session still in SUBMITTED — must poll for approval
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_SUBMITTED);
    expect($updated->isIssuable())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. NHIF Rejection
// ---------------------------------------------------------------------------

test('rejected service has REJECTED approval_status and stores rejection details', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'medication', 'source_id' => null,
        'nhif_item_code' => 'MED001', 'nhif_item_name' => 'Amoxicillin', 'quantity' => 5, 'unit_price' => 500,
    ]], requestAs($ctx['user']));

    $response = nhifAcceptedResponse();
    $response['data']['services'][0]['itemCode'] = 'MED001';
    $response['data']['services'][0]['status'] = 'Rejected';
    $response['data']['services'][0]['requiresApproval'] = false;
    $response['data']['services'][0]['rejectionDetails'] = 'Not covered under this scheme';
    $response['data']['services'][0]['rejectionReasonCode'] = 'NC001';

    mockNhifClient('requestPreauthorizedServices', $response);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED);
    expect($item->nhif_status)->toBe('Rejected');
    expect($item->nhif_rejection_details)->toBe('Not covered under this scheme');
    expect($item->nhif_rejection_reason_code)->toBe('NC001');
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_REJECTED);
});

test('rejected service cannot be issued', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->accepted()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'created_by' => $ctx['user']->id,
        'status' => NhifPreApprovalSession::STATUS_REJECTED,
    ]);

    NhifPreApprovalSessionItem::factory()->rejected()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'nhif_item_code' => '5024',
    ]);

    $this->expectException(ValidationException::class);
    app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));
});

// ---------------------------------------------------------------------------
// 5. Unknown NHIF status
// ---------------------------------------------------------------------------

test('unknown NHIF status is stored as UNKNOWN and blocks issue', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'lab', 'source_id' => null,
        'nhif_item_code' => 'LAB002', 'nhif_item_name' => 'MRI', 'quantity' => 1, 'unit_price' => 80000,
    ]], requestAs($ctx['user']));

    $response = nhifAcceptedResponse();
    $response['data']['services'][0]['itemCode'] = 'LAB002';
    $response['data']['services'][0]['status'] = 'PendingVerification';
    $response['data']['services'][0]['requiresApproval'] = false;

    mockNhifClient('requestPreauthorizedServices', $response);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $item = $updated->items->first();
    expect($item->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_UNKNOWN);
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_SUBMITTED);
    expect($updated->isIssuable())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Facility code discrepancy preserved
// ---------------------------------------------------------------------------

test('submit stores NHIF-returned facility code separately from submitted facility code', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [[
        'source_type' => 'consultation', 'source_id' => null,
        'nhif_item_code' => '5024', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 100,
    ]], requestAs($ctx['user']));

    mockNhifClient('requestPreauthorizedServices', nhifAcceptedResponse());

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    // Request used FAC123 (from hospital), response returned 01141
    expect($updated->facility_code)->toBe('FAC123');
    expect($updated->nhif_facility_code_returned)->toBe('01141');
});

// ---------------------------------------------------------------------------
// 7. Batch requests — individual item statuses
// ---------------------------------------------------------------------------

test('batch request with mixed statuses sets correct per-item statuses', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [
        ['source_type' => 'consultation', 'source_id' => null, 'nhif_item_code' => 'CONSULT', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 5000],
        ['source_type' => 'lab', 'source_id' => null, 'nhif_item_code' => 'CBC', 'nhif_item_name' => 'CBC', 'quantity' => 1, 'unit_price' => 8000],
        ['source_type' => 'medication', 'source_id' => null, 'nhif_item_code' => 'MEDA', 'nhif_item_name' => 'Drug A', 'quantity' => 5, 'unit_price' => 500],
        ['source_type' => 'medication', 'source_id' => null, 'nhif_item_code' => 'MEDB', 'nhif_item_name' => 'Drug B', 'quantity' => 1, 'unit_price' => 2000],
    ], requestAs($ctx['user']));

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')->once()->andReturn([
        'success' => true,
        'data' => [
            'requestID' => 'batch-req-id',
            'requestNo' => 'BATCH001',
            'facilityCode' => 'FAC123',
            'authorizationNo' => '860627861758',
            'services' => [
                ['requestedServiceID' => 'svc-1', 'requestID' => 'batch-req-id', 'itemCode' => 'CONSULT', 'status' => 'Accepted', 'requiresApproval' => false, 'itemName' => 'Consultation', 'availableAfterDate' => now()->addDays(1)->toIso8601String(), 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => null, 'rejectionReasonCode' => null, 'percentCovered' => null],
                ['requestedServiceID' => 'svc-2', 'requestID' => 'batch-req-id', 'itemCode' => 'CBC', 'status' => 'Accepted', 'requiresApproval' => false, 'itemName' => 'Complete Blood Count', 'availableAfterDate' => now()->addDays(1)->toIso8601String(), 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => null, 'rejectionReasonCode' => null, 'percentCovered' => null],
                ['requestedServiceID' => 'svc-3', 'requestID' => 'batch-req-id', 'itemCode' => 'MEDA', 'status' => 'Rejected', 'requiresApproval' => false, 'itemName' => 'Drug A', 'availableAfterDate' => null, 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => 'Not covered', 'rejectionReasonCode' => 'NC001', 'percentCovered' => null],
                ['requestedServiceID' => 'svc-4', 'requestID' => 'batch-req-id', 'itemCode' => 'MEDB', 'status' => 'Accepted', 'requiresApproval' => true, 'itemName' => 'Drug B', 'availableAfterDate' => null, 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => null, 'rejectionReasonCode' => null, 'percentCovered' => null],
            ],
        ],
        'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'RequestServices',
    ]);
    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    $items = $updated->items->keyBy('nhif_item_code');

    // Consultation accepted — no approval needed
    expect($items['CONSULT']->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);
    // CBC accepted — no approval needed
    expect($items['CBC']->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);
    // Drug A rejected
    expect($items['MEDA']->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED);
    // Drug B needs approval
    expect($items['MEDB']->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL);

    // Session is SUBMITTED — still waiting on Drug B approval
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_SUBMITTED);
});

test('batch request where all accepted and some rejected gives partially_approved session', function () {
    $ctx = contractContext();
    $svc = app(NhifPreApprovalSessionService::class);
    $session = $svc->create($ctx['insurance'], null, [
        ['source_type' => 'consultation', 'source_id' => null, 'nhif_item_code' => 'CONSULT', 'nhif_item_name' => 'Consultation', 'quantity' => 1, 'unit_price' => 5000],
        ['source_type' => 'medication', 'source_id' => null, 'nhif_item_code' => 'MEDA', 'nhif_item_name' => 'Drug A', 'quantity' => 5, 'unit_price' => 500],
    ], requestAs($ctx['user']));

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')->once()->andReturn([
        'success' => true,
        'data' => [
            'requestID' => 'req-partial', 'requestNo' => 'P001', 'facilityCode' => 'FAC123',
            'authorizationNo' => '860627861758',
            'services' => [
                ['requestedServiceID' => 'svc-1', 'requestID' => 'req-partial', 'itemCode' => 'CONSULT', 'status' => 'Accepted', 'requiresApproval' => false, 'itemName' => 'Consultation', 'availableAfterDate' => now()->addDays(1)->toIso8601String(), 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => null, 'rejectionReasonCode' => null, 'percentCovered' => null],
                ['requestedServiceID' => 'svc-2', 'requestID' => 'req-partial', 'itemCode' => 'MEDA', 'status' => 'Rejected', 'requiresApproval' => false, 'itemName' => 'Drug A', 'availableAfterDate' => null, 'effectiveDate' => now()->toDateString(), 'endDate' => now()->addDays(4)->toDateString(), 'rejectionDetails' => 'Limit exceeded', 'rejectionReasonCode' => 'LE001', 'percentCovered' => null],
            ],
        ],
        'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'RequestServices',
    ]);
    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED);
    expect($updated->isIssuable())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. Issue accepted service — uses requestedServiceID (no approvalReferenceNo)
// ---------------------------------------------------------------------------

test('issuing an accepted session sends requestedServiceID in payload', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->accepted()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'nhif_request_id' => '1e594867-4868-436a-910e-d23c578a2b4a',
        'nhif_request_no' => '390620082451',
        'facility_code' => 'FAC123',
        'nhif_facility_code_returned' => '01141',
        'created_by' => $ctx['user']->id,
    ]);

    NhifPreApprovalSessionItem::factory()->accepted()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'nhif_item_code' => '5024',
        'nhif_requested_service_id' => '612e44bb-7e4b-4304-b5f0-2d4c74f39444',
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('issuePreApprovalRequestServices')
        ->once()
        ->withArgs(function ($payload) {
            expect($payload['authorizationNo'])->toBe('860627861758');
            expect($payload['services'])->toHaveCount(1);
            expect($payload['services'][0]['itemCode'])->toBe('5024');
            expect($payload['services'][0]['requestedServiceID'])->toBe('612e44bb-7e4b-4304-b5f0-2d4c74f39444');

            return true;
        })
        ->andReturn([
            'success' => true, 'data' => [], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'IssueRequestServices',
        ]);
    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_ISSUED);
    expect($updated->issued_at)->not()->toBeNull();
    expect($updated->items->first()->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED);
});

test('item is not marked ISSUED until IssueRequestServices succeeds', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->accepted()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'created_by' => $ctx['user']->id,
    ]);

    NhifPreApprovalSessionItem::factory()->accepted()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'nhif_item_code' => '5024',
    ]);

    mockNhifClient('issuePreApprovalRequestServices', [
        'success' => false, 'data' => [], 'http_status' => 500, 'error' => 'NHIF unavailable', 'correlation_id' => 'x', 'operation' => 'IssueRequestServices',
    ]);

    $updated = app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));

    // Still ACCEPTED — NHIF issue call failed
    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_ACCEPTED);
    expect($updated->items->first()->approval_status)->toBe(NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED);
});

// ---------------------------------------------------------------------------
// 9. Negative — missing/invalid authorizationNo
// ---------------------------------------------------------------------------

test('create throws when nhif_authorization_no is null on insurance', function () {
    $ctx = contractContext();
    $ctx['insurance']->update(['nhif_authorization_no' => null]);

    app(NhifPreApprovalSessionService::class)->create(
        $ctx['insurance'],
        null,
        [['source_type' => 'consultation', 'source_id' => null, 'nhif_item_code' => '5024', 'nhif_item_name' => 'Consult', 'quantity' => 1, 'unit_price' => 100]],
        requestAs($ctx['user']),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 10. Negative — re-submit non-draft session (duplicate prevention)
// ---------------------------------------------------------------------------

test('submitting a non-draft session throws to prevent duplicate RequestServices', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->submitted()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'created_by' => $ctx['user']->id,
    ]);

    app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 11. Negative — issue a session that is not issuable
// ---------------------------------------------------------------------------

test('issue throws for a submitted session that has no issuable items', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->submitted()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'created_by' => $ctx['user']->id,
    ]);

    app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

test('issue throws for an already-issued session (duplicate IssueRequestServices prevention)', function () {
    $ctx = contractContext();

    $session = NhifPreApprovalSession::factory()->issued()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => '860627861758',
        'created_by' => $ctx['user']->id,
    ]);

    app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 12. Negative — missing itemCode
// ---------------------------------------------------------------------------

test('create throws when nhif_item_code is blank', function () {
    $ctx = contractContext();

    app(NhifPreApprovalSessionService::class)->create(
        $ctx['insurance'],
        null,
        [['source_type' => 'consultation', 'source_id' => null, 'nhif_item_code' => '', 'nhif_item_name' => 'Consult', 'quantity' => 1, 'unit_price' => 100]],
        requestAs($ctx['user']),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function mockNhifClient(string $method, array $response): void
{
    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive($method)->once()->andReturn($response);
    app()->instance(NhifHttpClient::class, $mockClient);
}
