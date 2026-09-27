<?php

/**
 * NhifSchemeServicesTest
 *
 * Tests schemeID/facilityCode capture from VerifyCard, NhifSchemeService
 * model scopes, session-creation eligibility guard, and the
 * GET patients/nhif-scheme-services endpoint.
 */

use App\Models\InsuranceProvider;
use App\Models\NhifMemberVerification;
use App\Models\NhifSchemeService;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMemberVerificationService;
use App\Services\NHIF\NhifPreApprovalSessionService;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function schemeServicesSetup(): array
{
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);
    $patient = Patient::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Njoroge',
        'national_id' => 'NIN-SCHEME-01',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'card_number' => 'SCARD-001',
        'verification_status' => 'pending',
    ]);

    return compact('provider', 'patient', 'insurance');
}

function verifyCardWithScheme(string $authNo = 'AUTH-SCHEME-001', int $schemeId = 3001, string $facilityCode = 'FAC-SCHEME-01'): array
{
    return [
        'success' => true,
        'operation' => 'VerifyCard',
        'http_status' => 200,
        'error' => null,
        'data' => [
            'authorizationNo' => $authNo,
            'schemeID' => $schemeId,
            'facilityCode' => $facilityCode,
        ],
    ];
}

function cardDetailsForSchemeTest(): array
{
    return [
        'success' => true,
        'operation' => 'GetCardDetails',
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'corr-scheme-1',
        'data' => [
            'FullName' => 'Jane Njoroge',
            'CardNo' => 'SCARD-001',
            'MembershipNo' => 'MEM-SCHEME-001',
            'CHNationalID' => 'NIN-SCHEME-01',
            'IsActive' => true,
            'IsValidCard' => true,
            'SchemeID' => 3001,
        ],
    ];
}

// ---------------------------------------------------------------------------
// A. schemeID + facilityCode captured from VerifyCard
// ---------------------------------------------------------------------------

test('schemeID is saved to NhifMemberVerification from VerifyCard response', function () {
    $ctx = schemeServicesSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsForSchemeTest());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardWithScheme('AUTH-S1', 3001, 'FAC-001'));
    });

    $record = app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'SCARD-001', requestAs(userWithRole('receptionist')));

    expect($record->nhif_scheme_id)->toBe(3001)
        ->and($record->nhif_facility_code)->toBe('FAC-001');
});

test('schemeID is propagated from NhifMemberVerification to PatientInsurance after verify()', function () {
    $ctx = schemeServicesSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn(cardDetailsForSchemeTest());
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn(verifyCardWithScheme('AUTH-S2', 3002, 'FAC-002'));
    });

    app(NhifMemberVerificationService::class)
        ->verify($ctx['insurance'], NhifMemberVerification::TYPE_CARD_NUMBER, 'SCARD-001', requestAs(userWithRole('receptionist')));

    $ctx['insurance']->refresh();
    expect($ctx['insurance']->nhif_scheme_id)->toBe(3002)
        ->and($ctx['insurance']->nhif_facility_code)->toBe('FAC-002');
});

// ---------------------------------------------------------------------------
// B. NhifSchemeService active() scope
// ---------------------------------------------------------------------------

test('active() scope returns services with no date restrictions', function () {
    NhifSchemeService::create([
        'scheme_id' => 9001,
        'item_code' => 'ITEM-A',
        'item_name' => 'Active Item',
        'is_active' => true,
    ]);

    expect(NhifSchemeService::active()->where('scheme_id', 9001)->count())->toBe(1);
});

test('active() scope excludes inactive records', function () {
    NhifSchemeService::create([
        'scheme_id' => 9002,
        'item_code' => 'ITEM-INACTIVE',
        'item_name' => 'Inactive Item',
        'is_active' => false,
    ]);

    expect(NhifSchemeService::active()->where('scheme_id', 9002)->count())->toBe(0);
});

test('active() scope excludes services whose end_date is in the past', function () {
    NhifSchemeService::create([
        'scheme_id' => 9003,
        'item_code' => 'ITEM-EXPIRED',
        'item_name' => 'Expired Item',
        'is_active' => true,
        'end_date' => now()->subDay()->toDateString(),
    ]);

    expect(NhifSchemeService::active()->where('scheme_id', 9003)->count())->toBe(0);
});

test('active() scope excludes services whose start_date is in the future', function () {
    NhifSchemeService::create([
        'scheme_id' => 9004,
        'item_code' => 'ITEM-FUTURE',
        'item_name' => 'Future Item',
        'is_active' => true,
        'start_date' => now()->addDay()->toDateString(),
    ]);

    expect(NhifSchemeService::active()->where('scheme_id', 9004)->count())->toBe(0);
});

test('active() scope includes service whose date range spans today', function () {
    NhifSchemeService::create([
        'scheme_id' => 9005,
        'item_code' => 'ITEM-CURRENT',
        'item_name' => 'Current Item',
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
    ]);

    expect(NhifSchemeService::active()->where('scheme_id', 9005)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// C. NhifPreApprovalSessionService::create() eligibility guard
// ---------------------------------------------------------------------------

test('session creation is blocked when item_code is not in the scheme services', function () {
    $insurance = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-GUARD-001',
        'nhif_scheme_id' => 7001,
    ]);

    // No NhifSchemeService rows for scheme 7001 / ITEM-BLOCKED.

    expect(fn () => app(NhifPreApprovalSessionService::class)->create(
        $insurance,
        null,
        [['nhif_item_code' => 'ITEM-BLOCKED', 'nhif_item_name' => 'Test', 'quantity' => 1, 'unit_price' => 100]],
        requestAs(userWithRole('receptionist')),
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('session creation succeeds when item_code IS in the scheme services', function () {
    $insurance = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-GUARD-002',
        'nhif_scheme_id' => 7002,
    ]);

    NhifSchemeService::create([
        'scheme_id' => 7002,
        'item_code' => 'ITEM-ALLOWED',
        'item_name' => 'Allowed Item',
        'is_active' => true,
    ]);

    $hospital = \App\Models\Hospital::factory()->create();
    $actor = userWithRole('receptionist');
    \App\Models\Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $hospital->id]);

    // create() only writes to DB — no NHIF API call until submit().
    $session = app(NhifPreApprovalSessionService::class)->create(
        $insurance,
        null,
        [['nhif_item_code' => 'ITEM-ALLOWED', 'nhif_item_name' => 'Allowed Item', 'quantity' => 1, 'unit_price' => 100]],
        requestAs($actor),
    );

    expect($session)->not->toBeNull();
    expect($session->items)->toHaveCount(1);
});

test('session creation skips eligibility check when nhif_scheme_id is null (graceful degradation)', function () {
    $insurance = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-NOSCHEME-001',
        'nhif_scheme_id' => null,
    ]);

    $hospital = \App\Models\Hospital::factory()->create();
    $actor = userWithRole('receptionist');
    \App\Models\Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $hospital->id]);

    // Should NOT throw — eligibility guard is skipped when scheme_id is null.
    // create() only writes to DB — no NHIF API call until submit().
    $session = app(NhifPreApprovalSessionService::class)->create(
        $insurance,
        null,
        [['nhif_item_code' => 'ANY-ITEM', 'nhif_item_name' => 'Any Item', 'quantity' => 1, 'unit_price' => 50]],
        requestAs($actor),
    );

    expect($session)->not->toBeNull();
    expect($session->items)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// D. GET patients/nhif-scheme-services endpoint
// ---------------------------------------------------------------------------

test('GET patients/nhif-scheme-services returns active items for scheme', function () {
    $actor = userWithRole('receptionist');

    NhifSchemeService::create([
        'scheme_id' => 5001,
        'item_code' => 'LAB-001',
        'item_name' => 'Full Blood Count',
        'type_name' => 'Laboratory',
        'is_active' => true,
    ]);
    NhifSchemeService::create([
        'scheme_id' => 5001,
        'item_code' => 'LAB-002',
        'item_name' => 'Malaria Test',
        'type_name' => 'Laboratory',
        'is_active' => false,
    ]);

    $this->actingAs($actor)
        ->getJson(route('patients.nhif-scheme-services', ['scheme_id' => 5001]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_code', 'LAB-001');
});

test('GET patients/nhif-scheme-services requires authentication', function () {
    $this->getJson(route('patients.nhif-scheme-services', ['scheme_id' => 5001]))
        ->assertUnauthorized();
});
