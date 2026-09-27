<?php

use App\Models\Hospital;
use App\Models\NhifBillConfirmation;
use App\Models\NhifClaim;
use App\Models\NhifClaimItem;
use App\Models\NhifFolioDisease;
use App\Models\NhifMonthlySubmission;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Services\NHIF\NhifFolioService;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMonthlyClaimService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function folioContext(): array
{
    $hospital = Hospital::factory()->create([
        'code' => 'FAC001',
    ]);
    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => 'AUTH-2024-TEST',
        'card_number' => 'CARD123456',
    ]);
    $user = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    return compact('hospital', 'patient', 'insurance', 'user');
}

function readyClaim(array $ctx): NhifClaim
{
    return NhifClaim::factory()->ready()->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'facility_code' => $ctx['hospital']->code,
        'card_no' => $ctx['insurance']->card_number,
        'authorization_no' => $ctx['insurance']->nhif_authorization_no,
        'folio_year' => (int) now()->format('Y'),
        'folio_month' => (int) now()->format('n'),
        'folio_no' => '0001',
        'created_by' => $ctx['user']->id,
    ]);
}

function submittedClaim(array $ctx): NhifClaim
{
    return NhifClaim::factory()->submitted()->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'facility_code' => $ctx['hospital']->code,
        'folio_year' => (int) now()->format('Y'),
        'folio_month' => (int) now()->format('n'),
        'folio_no' => '0002',
        'created_by' => $ctx['user']->id,
    ]);
}

function nhifOkResponse(array $data = []): array
{
    return [
        'success' => true,
        'data' => $data,
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'test-'.uniqid(),
        'operation' => 'test',
    ];
}

function nhifFailResponse(string $error = 'NHIF error'): array
{
    return [
        'success' => false,
        'data' => [],
        'http_status' => 400,
        'error' => $error,
        'correlation_id' => 'test-'.uniqid(),
        'operation' => 'test',
    ];
}

// ---------------------------------------------------------------------------
// 1. Folio payload field mapping
// ---------------------------------------------------------------------------

test('buildFolioPayload maps OCS field names correctly', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    NhifClaimItem::factory()->create([
        'nhif_claim_id' => $claim->id,
        'nhif_item_code' => 'ITEM001',
        'nhif_item_name' => 'Consultation',
        'item_type_id' => 1,
        'quantity' => 1,
        'hms_unit_price' => 5000,
        'hms_amount' => 5000,
        'nhif_allowable_amount' => 5000,
        'removed_at' => null,
    ]);

    NhifFolioDisease::factory()->create([
        'nhif_claim_id' => $claim->id,
        'disease_code' => 'A09.0',
        'is_main' => true,
    ]);

    $service = app(NhifFolioService::class);
    $payload = $service->buildFolioPayload($claim->fresh(['patient', 'patientInsurance', 'hospital', 'activeItems', 'diseases']));

    expect($payload)->toHaveKey('FacilityCode', $ctx['hospital']->code)
        ->and($payload)->toHaveKey('AuthorizationNo', 'AUTH-2024-TEST')
        ->and($payload)->toHaveKey('CardNo', 'CARD123456')
        ->and($payload)->toHaveKey('FolioNo', '0001')
        ->and($payload['FolioItems'])->toHaveCount(1)
        ->and($payload['FolioItems'][0])->toHaveKey('ItemCode', 'ITEM001')
        ->and($payload['FolioDiseases'])->toHaveCount(1)
        ->and($payload['FolioDiseases'][0])->toHaveKey('DiseaseCode', 'A09.0');
});

// ---------------------------------------------------------------------------
// 2. Amount calculated server-side only
// ---------------------------------------------------------------------------

test('buildFolioPayload uses nhif_allowable_total from the model, not a request value', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);
    $claim->update(['nhif_allowable_total' => 45000.00]);

    $service = app(NhifFolioService::class);
    $payload = $service->buildFolioPayload($claim->fresh());

    expect($payload['AmountClaimed'])->toBe(45000.0);
});

// ---------------------------------------------------------------------------
// 3. Sign folio records signature metadata
// ---------------------------------------------------------------------------

test('sign records signed_at and signature_method on claim', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')
        ->once()
        ->andReturn(nhifOkResponse(['Status' => 'Signed']));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $result = $service->sign($claim, ['signature_method' => 'Manual'], requestAs($ctx['user']));

    expect($result->signed_at)->not->toBeNull()
        ->and($result->signature_method)->toBe('Manual')
        ->and($result->signed_by)->toBe($ctx['user']->id);
});

test('sign throws for a non-ready claim', function () {
    $ctx = folioContext();
    $claim = NhifClaim::factory()->create([
        'hospital_id' => $ctx['hospital']->id,
        'status' => NhifClaim::STATUS_DRAFT,
        'created_by' => $ctx['user']->id,
    ]);

    $service = app(NhifFolioService::class);

    expect(fn () => $service->sign($claim, [], requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 4. Submit calls NHIF SubmitFolio with correct OCS payload
// ---------------------------------------------------------------------------

test('submit calls SubmitFolio with OCS payload and marks claim submitted on success', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('submitFolio')
        ->once()
        ->with(Mockery::on(fn ($p) => isset($p['FacilityCode'])))
        ->andReturn(nhifOkResponse([
            'SubmissionID' => 'SUB-ABC123',
            'SubmissionNo' => 'SN-001',
            'HashCode' => 'HASH001',
        ]));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $result = $service->submit($claim, requestAs($ctx['user']));

    expect($result->status)->toBe(NhifClaim::STATUS_SUBMITTED)
        ->and($result->nhif_claim_reference)->toBe('SUB-ABC123')
        ->and($result->nhif_submission_no)->toBe('SN-001')
        ->and($result->submitted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. Failed NHIF submission does NOT mark folio as submitted
// ---------------------------------------------------------------------------

test('submit does not mark claim as submitted when NHIF returns failure', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('submitFolio')
        ->once()
        ->andReturn(nhifFailResponse('SubmitFolio rejected'));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $result = $service->submit($claim, requestAs($ctx['user']));

    expect($result->status)->toBe(NhifClaim::STATUS_READY)
        ->and($result->nhif_claim_reference)->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. Duplicate submission rejected
// ---------------------------------------------------------------------------

test('submit rejects a folio that is already submitted with a reference', function () {
    $ctx = folioContext();
    $claim = submittedClaim($ctx);

    $service = app(NhifFolioService::class);

    expect(fn () => $service->submit($claim, requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 7. IDOR — wrong-facility folio rejected
// ---------------------------------------------------------------------------

test('NhifFolioPolicy denies sign for user at different hospital', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    // User at a DIFFERENT hospital
    $otherHospital = Hospital::factory()->create();
    $otherUser = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherUser->id, 'hospital_id' => $otherHospital->id]);

    $allowed = $otherUser->can('sign', $claim);

    expect($allowed)->toBeFalse();
});

test('NhifFolioPolicy allows sign for user at same hospital', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    $allowed = $ctx['user']->can('sign', $claim);

    expect($allowed)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. Get receipt after submission
// ---------------------------------------------------------------------------

test('getReceipt calls NHIF GetReceipt for a submitted folio', function () {
    $ctx = folioContext();
    $claim = submittedClaim($ctx);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('getReceipt')
        ->once()
        ->with(Mockery::type('string'), Mockery::type('int'), Mockery::type('int'), Mockery::type('string'))
        ->andReturn(nhifOkResponse(['ReceiptNo' => 'RCT-001']));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $result = $service->getReceipt($claim, requestAs($ctx['user']));

    expect($result['success'])->toBeTrue()
        ->and($result['data']['ReceiptNo'])->toBe('RCT-001');
});

test('getReceipt throws for non-submitted folio', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    $service = app(NhifFolioService::class);

    expect(fn () => $service->getReceipt($claim, requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 9. Bill confirmation workflow
// ---------------------------------------------------------------------------

test('requestBillConfirmation creates pending NhifBillConfirmation on NHIF success', function () {
    $ctx = folioContext();

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestBillConfirmation')
        ->once()
        ->andReturn(nhifOkResponse(['Status' => 'OTP Sent']));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $conf = $service->requestBillConfirmation(
        $ctx['insurance'],
        $ctx['hospital'],
        now(),
        25000.0,
        requestAs($ctx['user']),
    );

    expect($conf)->toBeInstanceOf(NhifBillConfirmation::class)
        ->and($conf->confirmation_status)->toBe(NhifBillConfirmation::STATUS_PENDING)
        ->and($conf->total_amount)->toEqual(25000.0)
        ->and($conf->hospital_id)->toBe($ctx['hospital']->id);
});

test('confirmBill marks confirmation as confirmed on NHIF success', function () {
    $ctx = folioContext();
    $conf = NhifBillConfirmation::factory()->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_insurance_id' => $ctx['insurance']->id,
        'nhif_authorization_no' => $ctx['insurance']->nhif_authorization_no,
        'card_no' => $ctx['insurance']->card_number,
        'facility_code' => $ctx['hospital']->code,
        'attendance_date' => now()->toDateString(),
        'total_amount' => 25000,
        'confirmation_status' => NhifBillConfirmation::STATUS_PENDING,
        'created_by' => $ctx['user']->id,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('sendConfirmationCode')
        ->once()
        ->andReturn(nhifOkResponse(['Status' => 'Confirmed']));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifFolioService::class);
    $result = $service->confirmBill($conf, '123456', requestAs($ctx['user']));

    expect($result->confirmation_status)->toBe(NhifBillConfirmation::STATUS_CONFIRMED)
        ->and($result->confirmation_code)->toBe('123456')
        ->and($result->confirmed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 10. Monthly submission — totals calculated server-side
// ---------------------------------------------------------------------------

test('submitMonthlyClaim calculates totals from submitted folios, ignores any request amount', function () {
    $ctx = folioContext();
    $year = (int) now()->format('Y');
    $month = (int) now()->format('n');

    // Two submitted folios totaling 90,000
    NhifClaim::factory()->submitted()->create([
        'hospital_id' => $ctx['hospital']->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'nhif_allowable_total' => 40000,
        'created_by' => $ctx['user']->id,
    ]);
    NhifClaim::factory()->submitted()->create([
        'hospital_id' => $ctx['hospital']->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'nhif_allowable_total' => 50000,
        'created_by' => $ctx['user']->id,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('submitMonthlyClaim')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['FoliosSubmitted'] === 2 && $p['TotalAmountClaimed'] == 90000.0))
        ->andReturn(nhifOkResponse(['Status' => 'Received']));

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifMonthlyClaimService::class);
    $submission = $service->submitMonthlyClaim($ctx['hospital'], $year, $month, null, requestAs($ctx['user']));

    expect($submission)->toBeInstanceOf(NhifMonthlySubmission::class)
        ->and($submission->folios_submitted)->toBe(2)
        ->and((float) $submission->total_amount_claimed)->toBe(90000.0)
        ->and($submission->status)->toBe(NhifMonthlySubmission::STATUS_SUBMITTED);
});

test('submitMonthlyClaim rejects duplicate submission for same period', function () {
    $ctx = folioContext();
    $year = (int) now()->format('Y');
    $month = (int) now()->format('n');

    NhifMonthlySubmission::factory()->submitted()->create([
        'hospital_id' => $ctx['hospital']->id,
        'claim_year' => $year,
        'claim_month' => $month,
    ]);

    $service = app(NhifMonthlyClaimService::class);

    expect(fn () => $service->submitMonthlyClaim($ctx['hospital'], $year, $month, null, requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

test('submitMonthlyClaim rejects when no submitted folios exist for the period', function () {
    $ctx = folioContext();

    $service = app(NhifMonthlyClaimService::class);

    expect(fn () => $service->submitMonthlyClaim($ctx['hospital'], 2099, 1, null, requestAs($ctx['user'])))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 11. Disease mapping replaces existing diseases
// ---------------------------------------------------------------------------

test('mapDiseases replaces all existing diseases on the claim', function () {
    $ctx = folioContext();
    $claim = readyClaim($ctx);

    // Pre-existing disease
    NhifFolioDisease::factory()->create(['nhif_claim_id' => $claim->id, 'disease_code' => 'OLD001']);

    $service = app(NhifFolioService::class);
    $service->mapDiseases($claim, [
        ['disease_code' => 'A09.0', 'is_main' => true],
        ['disease_code' => 'J00.0'],
    ]);

    $diseases = NhifFolioDisease::where('nhif_claim_id', $claim->id)->get();
    $codes = $diseases->pluck('disease_code')->sort()->values()->all();

    expect($diseases)->toHaveCount(2)
        ->and($codes)->toBe(['A09.0', 'J00.0'])
        ->and($diseases->first(fn ($d) => $d->disease_code === 'A09.0')?->is_main)->toBeTrue();
});
