<?php

use App\Models\Hospital;
use App\Models\NhifMemberVerification;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMemberVerificationService;
use App\Services\NHIF\NhifPreApprovalSessionService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function preApprovalContext(): array
{
    $hospital = Hospital::factory()->create();
    $user = userWithRole('nurse');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => 'AUTH-2024-001',
        'card_number' => 'TEST123456',
    ]);

    return compact('hospital', 'patient', 'insurance', 'user');
}

function sampleItems(): array
{
    return [
        [
            'source_type' => 'consultation',
            'source_id' => null,
            'nhif_item_code' => 'CONSULT001',
            'nhif_item_name' => 'Consultation Fee',
            'quantity' => 1,
            'unit_price' => 5000,
        ],
        [
            'source_type' => 'lab',
            'source_id' => null,
            'nhif_item_code' => 'LAB001',
            'nhif_item_name' => 'Full Blood Count',
            'quantity' => 1,
            'unit_price' => 15000,
        ],
        [
            'source_type' => 'medication',
            'source_id' => null,
            'nhif_item_code' => 'MED001',
            'nhif_item_name' => 'Paracetamol 500mg',
            'quantity' => 10,
            'unit_price' => 200,
        ],
    ];
}

// ---------------------------------------------------------------------------
// 1. authorizationNo capture during verification
// ---------------------------------------------------------------------------

test('verification stores nhif_authorization_no on NhifMemberVerification', function () {
    $patient = Patient::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    $user = userWithRole('nurse');

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getMemberByCard')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'CardNo' => 'TEST123',
                'FullName' => 'John Doe',
                'CardStatus' => 'Active',
                'AuthorizationNo' => 'AUTH-FAKE-001',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'GetCardDetails',
        ]);
    $mockClient->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
    $mockClient->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
    $mockClient->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-FAKE-001', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);

    app()->instance(NhifHttpClient::class, $mockClient);
    $service = app(NhifMemberVerificationService::class);

    $record = $service->verify($insurance, NhifMemberVerification::TYPE_CARD_NUMBER, 'TEST123', requestAs($user));

    expect($record->nhif_authorization_no)->toBe('AUTH-FAKE-001');
});

test('verification propagates nhif_authorization_no to PatientInsurance', function () {
    $patient = Patient::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    $user = userWithRole('nurse');

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getMemberByCard')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'CardNo' => 'TEST456',
                'FullName' => 'Jane Doe',
                'CardStatus' => 'Active',
                'AuthorizationNo' => 'AUTH-FAKE-002',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'GetCardDetails',
        ]);
    $mockClient->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
    $mockClient->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
    $mockClient->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-FAKE-002', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);

    app()->instance(NhifHttpClient::class, $mockClient);
    $service = app(NhifMemberVerificationService::class);

    $service->verify($insurance, NhifMemberVerification::TYPE_CARD_NUMBER, 'TEST456', requestAs($user));

    expect($insurance->fresh()->nhif_authorization_no)->toBe('AUTH-FAKE-002');
});

test('verification captures authorizationNo in alternate casing (authorizationNo)', function () {
    $patient = Patient::factory()->create(['first_name' => 'Ali', 'last_name' => 'Hassan']);
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    $user = userWithRole('nurse');

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getMemberByCard')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'CardNo' => 'ALI123',
                'FullName' => 'Ali Hassan',
                'CardStatus' => 'Active',
                'authorizationNo' => 'AUTH-ALT-003',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'GetCardDetails',
        ]);
    $mockClient->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
    $mockClient->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
    $mockClient->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-ALT-003', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);

    app()->instance(NhifHttpClient::class, $mockClient);
    $service = app(NhifMemberVerificationService::class);

    $record = $service->verify($insurance, NhifMemberVerification::TYPE_CARD_NUMBER, 'ALI123', requestAs($user));

    expect($record->nhif_authorization_no)->toBe('AUTH-ALT-003');
    expect($insurance->fresh()->nhif_authorization_no)->toBe('AUTH-ALT-003');
});

// ---------------------------------------------------------------------------
// 2. Session creation
// ---------------------------------------------------------------------------

test('session is created with multiple service types', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);

    $session = $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));

    expect($session->status)->toBe(NhifPreApprovalSession::STATUS_DRAFT);
    expect($session->nhif_authorization_no)->toBe('AUTH-2024-001');
    expect($session->items)->toHaveCount(3);
    expect($session->items->pluck('source_type')->toArray())->toContain('consultation', 'lab', 'medication');
});

test('session hospital_id is derived from user staff record when no encounter', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);

    $session = $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));

    expect($session->hospital_id)->toBe($ctx['hospital']->id);
});

test('create throws when nhif_authorization_no is missing on insurance', function () {
    $ctx = preApprovalContext();
    $ctx['insurance']->update(['nhif_authorization_no' => null]);

    $service = app(NhifPreApprovalSessionService::class);

    $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));
})->throws(ValidationException::class);

test('create throws when nhif_item_code is missing', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);

    $items = sampleItems();
    $items[0]['nhif_item_code'] = '';

    $service->create($ctx['insurance'], null, $items, requestAs($ctx['user']));
})->throws(ValidationException::class);

test('create throws when items array is empty', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);

    $service->create($ctx['insurance'], null, [], requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 3. Submit
// ---------------------------------------------------------------------------

test('submit builds correct requestedServices payload and marks session submitted', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);
    $session = $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')
        ->once()
        ->withArgs(function ($payload) {
            expect($payload['authorizationNo'])->toBe('AUTH-2024-001');
            expect($payload['requestedServices'])->toHaveCount(3);
            expect(collect($payload['requestedServices'])->pluck('itemCode')->toArray())
                ->toContain('CONSULT001', 'LAB001', 'MED001');

            return true;
        })
        ->andReturn([
            'success' => true,
            'data' => [],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'RequestServices',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_SUBMITTED);
    expect($updated->submitted_at)->not()->toBeNull();
});

test('submit keeps draft status when NHIF call fails', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);
    $session = $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')
        ->once()
        ->andReturn([
            'success' => false,
            'data' => [],
            'http_status' => 500,
            'error' => 'Server error',
            'correlation_id' => 'x',
            'operation' => 'RequestServices',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_DRAFT);
});

test('submit throws when session is not in draft status', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()
        ->submitted()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $ctx['user']->id,
        ]);

    app(NhifPreApprovalSessionService::class)->submit($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 4. Sync
// ---------------------------------------------------------------------------

test('syncStatus updates item approval statuses from NHIF response', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);
    $session = $service->create($ctx['insurance'], null, sampleItems(), requestAs($ctx['user']));
    $session->update(['status' => NhifPreApprovalSession::STATUS_SUBMITTED]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getRequestServicesByAuthorizationNo')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'authorizedItems' => [
                    ['itemCode' => 'CONSULT001', 'approvalStatusID' => 1, 'referenceNo' => 'REF-001', 'quantity' => 1],
                    ['itemCode' => 'LAB001', 'approvalStatusID' => 1, 'referenceNo' => 'REF-002', 'quantity' => 1],
                    ['itemCode' => 'MED001', 'approvalStatusID' => 2, 'referenceNo' => null, 'quantity' => null, 'remarks' => 'Not covered'],
                ],
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'GetRequestServices',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->syncStatus($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED);

    $items = $updated->items->keyBy('nhif_item_code');
    expect($items['CONSULT001']->approval_status)->toBe('approved');
    expect($items['CONSULT001']->approval_reference_no)->toBe('REF-001');
    expect($items['LAB001']->approval_status)->toBe('approved');
    expect($items['MED001']->approval_status)->toBe('rejected');
    expect($items['MED001']->rejection_reason)->toBe('Not covered');
});

test('syncStatus sets approved when all items are approved', function () {
    $ctx = preApprovalContext();
    $service = app(NhifPreApprovalSessionService::class);
    $items = array_slice(sampleItems(), 0, 2);
    $session = $service->create($ctx['insurance'], null, $items, requestAs($ctx['user']));
    $session->update(['status' => NhifPreApprovalSession::STATUS_SUBMITTED]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getRequestServicesByAuthorizationNo')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'authorizedItems' => [
                    ['itemCode' => 'CONSULT001', 'approvalStatusID' => 1, 'referenceNo' => 'REF-001', 'quantity' => 1],
                    ['itemCode' => 'LAB001', 'approvalStatusID' => 1, 'referenceNo' => 'REF-002', 'quantity' => 1],
                ],
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'GetRequestServices',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->syncStatus($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_APPROVED);
});

test('syncStatus throws when session is not submitted', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => 'AUTH-2024-001',
        'created_by' => $ctx['user']->id,
        'status' => NhifPreApprovalSession::STATUS_DRAFT,
    ]);

    app(NhifPreApprovalSessionService::class)->syncStatus($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 5. Issue
// ---------------------------------------------------------------------------

test('issue sends approved items with their approval reference numbers', function () {
    $ctx = preApprovalContext();

    $session = NhifPreApprovalSession::factory()
        ->approved()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'facility_code' => 'FAC001',
            'created_by' => $ctx['user']->id,
        ]);

    NhifPreApprovalSessionItem::factory()->approved()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'nhif_item_code' => 'CONSULT001',
        'approval_reference_no' => 'REF-001',
    ]);
    NhifPreApprovalSessionItem::factory()->rejected()->create([
        'nhif_pre_approval_session_id' => $session->id,
        'nhif_item_code' => 'MED001',
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('issuePreApprovalRequestServices')
        ->once()
        ->withArgs(function ($payload) {
            expect($payload['authorizationNo'])->toBe('AUTH-2024-001');
            expect($payload['services'])->toHaveCount(1);
            expect($payload['services'][0]['itemCode'])->toBe('CONSULT001');
            expect($payload['services'][0]['approvalReferenceNo'])->toBe('REF-001');

            return true;
        })
        ->andReturn([
            'success' => true,
            'data' => [],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'x',
            'operation' => 'IssueRequestServices',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $updated = app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));

    expect($updated->status)->toBe(NhifPreApprovalSession::STATUS_ISSUED);
    expect($updated->issued_at)->not()->toBeNull();

    $items = $updated->items->keyBy('nhif_item_code');
    expect($items['CONSULT001']->approval_status)->toBe('issued');
    expect($items['MED001']->approval_status)->toBe('rejected');
});

test('issue rejects a submitted session', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()
        ->submitted()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $ctx['user']->id,
        ]);

    app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

test('issue rejects an already-issued session', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()
        ->issued()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $ctx['user']->id,
        ]);

    app(NhifPreApprovalSessionService::class)->issue($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

test('cancel marks session as cancelled', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()
        ->submitted()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $ctx['user']->id,
        ]);

    $result = app(NhifPreApprovalSessionService::class)->cancel($session, requestAs($ctx['user']));

    expect($result->status)->toBe(NhifPreApprovalSession::STATUS_CANCELLED);
});

test('cancel throws when session is already issued', function () {
    $ctx = preApprovalContext();
    $session = NhifPreApprovalSession::factory()
        ->issued()
        ->create([
            'patient_id' => $ctx['patient']->id,
            'patient_insurance_id' => $ctx['insurance']->id,
            'hospital_id' => $ctx['hospital']->id,
            'nhif_authorization_no' => 'AUTH-2024-001',
            'created_by' => $ctx['user']->id,
        ]);

    app(NhifPreApprovalSessionService::class)->cancel($session, requestAs($ctx['user']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 6. IDOR / security
// ---------------------------------------------------------------------------

test('a hospital-admin from another hospital cannot view a session', function () {
    $ctx = preApprovalContext();

    $session = NhifPreApprovalSession::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'hospital_id' => $ctx['hospital']->id,
        'nhif_authorization_no' => 'AUTH-2024-001',
        'created_by' => $ctx['user']->id,
    ]);

    $otherHospital = Hospital::factory()->create();
    $otherAdmin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherAdmin->id, 'hospital_id' => $otherHospital->id]);

    $response = $this->actingAs($otherAdmin)->get(
        route('patients.insurance.nhif.pre-approval-sessions.show', [
            $ctx['patient']->id,
            $ctx['insurance']->id,
            $session->id,
        ])
    );

    $response->assertForbidden();
});
