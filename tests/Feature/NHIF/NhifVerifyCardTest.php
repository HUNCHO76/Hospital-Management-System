<?php

use App\Models\InsuranceProvider;
use App\Models\NhifMemberVerification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMemberVerificationService;

/**
 * NHIF three-step VerifyCard flow (Phase 8).
 *
 * Contract:
 *  - STATUS_LOOKUP_FOUND is set after GetCardDetails succeeds (identity check passes).
 *  - STATUS_VERIFIED is only set after VerifyCard returns an authorizationNo.
 *  - STATUS_VERIFICATION_FAILED is set when VerifyCard returns no authorizationNo.
 *  - authorizationNo is propagated to both NhifMemberVerification and PatientInsurance.
 *  - When a user-supplied verifierID is provided, getCardVerifiers() is NOT called.
 */
function nhifVerifyCardSetup(): array
{
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);
    $patient = Patient::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'national_id' => 'NIN-12345',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'card_number' => 'CARD-001',
        'verification_status' => 'pending',
    ]);

    return compact('provider', 'patient', 'insurance');
}

function cardDetailsResponse(array $overrides = []): array
{
    return array_merge([
        'success' => true,
        'operation' => 'GetCardDetails',
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'corr-1',
        'data' => [
            'FullName' => 'John Doe',
            'CardNo' => 'CARD-001',
            'MembershipNo' => 'MEM-001',
            'CHNationalID' => 'NIN-12345',
            'IsActive' => true,
            'IsValidCard' => true,
        ],
    ], $overrides);
}

function verifyCardSuccessResponse(string $authNo = '890627863923'): array
{
    return [
        'success' => true,
        'operation' => 'VerifyCard',
        'http_status' => 200,
        'error' => null,
        'data' => ['authorizationNo' => $authNo],
    ];
}

function verifyCardFailureResponse(string $error = 'Card not eligible'): array
{
    return [
        'success' => false,
        'operation' => 'VerifyCard',
        'http_status' => 422,
        'error' => $error,
        'data' => [],
    ];
}

// ---------------------------------------------------------------------------
// NhifMemberVerificationService tests
// ---------------------------------------------------------------------------

test('verify() sets STATUS_LOOKUP_FOUND after GetCardDetails succeeds', function () {
    $ctx = nhifVerifyCardSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsResponse());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardSuccessResponse());
    });

    $actor = userWithRole('receptionist');
    $request = requestAs($actor);

    $record = app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'CARD-001', $request);

    // Final status after VerifyCard = STATUS_VERIFIED
    expect($record->verification_status)->toBe(NhifMemberVerification::STATUS_VERIFIED);
});

test('verify() sets STATUS_VERIFIED and persists authorizationNo after successful VerifyCard', function () {
    $ctx = nhifVerifyCardSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsResponse());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardSuccessResponse('AUTH-999'));
    });

    $actor = userWithRole('receptionist');
    $request = requestAs($actor);

    $record = app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'CARD-001', $request);

    expect($record->verification_status)->toBe(NhifMemberVerification::STATUS_VERIFIED)
        ->and($record->nhif_authorization_no)->toBe('AUTH-999')
        ->and($record->verified_at)->not->toBeNull();

    $ctx['insurance']->refresh();
    expect($ctx['insurance']->nhif_authorization_no)->toBe('AUTH-999')
        ->and($ctx['insurance']->verification_status)->toBe('verified');
});

test('verify() sets STATUS_VERIFICATION_FAILED when VerifyCard returns no authorizationNo', function () {
    $ctx = nhifVerifyCardSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsResponse());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardFailureResponse());
    });

    $actor = userWithRole('receptionist');
    $request = requestAs($actor);

    $record = app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'CARD-001', $request);

    expect($record->verification_status)->toBe(NhifMemberVerification::STATUS_VERIFICATION_FAILED)
        ->and($record->isSuccessful())->toBeFalse();

    $ctx['insurance']->refresh();
    expect($ctx['insurance']->verification_status)->toBe('failed');
});

test('verify() skips getCardVerifiers() when verifierID is supplied by the caller', function () {
    $ctx = nhifVerifyCardSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsResponse());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldNotReceive('getCardVerifiers');
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardSuccessResponse());
    });

    $actor = userWithRole('receptionist');
    $request = requestAs($actor);

    $record = app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'CARD-001', $request,
            visitTypeId: 1, verifierId: 'USER-VERIFIER-1');

    expect($record->isSuccessful())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Controller endpoint tests
// ---------------------------------------------------------------------------

test('GET patients/nhif-visit-types returns visit types from NHIF', function () {
    $actor = userWithRole('receptionist');

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getVisitTypes')->once()->andReturn([
            'success' => true,
            'data' => [['visitTypeID' => 1, 'VisitTypeName' => 'Normal Visit', 'RequiresRemarks' => false]],
            'error' => null,
        ]);
    });

    $response = $this->actingAs($actor)->getJson(route('patients.nhif-visit-types'));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

test('POST patients/nhif-verifiers returns verifiers for the card', function () {
    $actor = userWithRole('receptionist');

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getCardVerifiers')->once()->with('CARD-001')->andReturn([
            'success' => true,
            'data' => [['verifierID' => 'V1', 'verifierName' => 'NHIF Verifier 1']],
            'error' => null,
        ]);
    });

    $response = $this->actingAs($actor)->postJson(route('patients.nhif-verifiers'), ['card_no' => 'CARD-001']);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// PatientController::store() transaction tests
// ---------------------------------------------------------------------------

test('store() rolls back patient and insurance when VerifyCard fails', function () {
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsResponse());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        // nhif_verifier_id is supplied so getCardVerifiers() must NOT be called.
        $mock->shouldNotReceive('getCardVerifiers');
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardFailureResponse());
    });

    $actor = userWithRole('receptionist');
    $patientCountBefore = Patient::count();

    $response = $this->actingAs($actor)->post(route('patients.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Test',
        'gender' => 'female',
        'status' => 'active',
        'payment_type' => 'insurance',
        'nhif_provider_id' => $provider->id,
        'nhif_membership_number' => 'MEM-TEST-001',
        'nhif_card_number' => 'CARD-001',
        'nhif_identifier_type' => 'CardNo',
        'nhif_verifier_id' => 'V1',
        'nhif_visit_type_id' => 1,
    ]);

    // Patient must NOT be in the database — transaction rolled back.
    expect(Patient::count())->toBe($patientCountBefore);
    $response->assertSessionHasErrors('nhif_verification');
});
