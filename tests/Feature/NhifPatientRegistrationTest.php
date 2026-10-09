<?php

use App\Models\InsuranceProvider;
use App\Models\NhifMemberVerification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMemberVerificationService;
use Spatie\Permission\Models\Permission;

// ---------------------------------------------------------------------------
// Helper — a receptionist user with the minimum permissions needed
// ---------------------------------------------------------------------------

function registrationUser(): User
{
    $user = User::factory()->create();
    Permission::findOrCreate('patients.create');
    Permission::findOrCreate('patients.view');
    Permission::findOrCreate('patient-insurance.create');
    Permission::findOrCreate('patient-insurance.verify');
    $user->givePermissionTo(['patients.create', 'patients.view', 'patient-insurance.create', 'patient-insurance.verify']);

    return $user;
}

function nhifProvider(): InsuranceProvider
{
    return InsuranceProvider::updateOrCreate(
        ['code' => 'NHIF'],
        [
            'name' => 'National Health Insurance Fund (NHIF)',
            'type' => 'government',
            'api_enabled' => true,
            'status' => 'active',
        ],
    );
}

function validCashPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'John',
        'middle_name' => '',
        'last_name' => 'Doe',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'phone' => '0711234567',
        'email' => '',
        'national_id' => '20000101-12345-12345-6',
        'passport_number' => '',
        'other_identification' => '',
        'occupation' => '',
        'marital_status' => '',
        'blood_group' => '',
        'address' => '',
        'region' => '',
        'district' => '',
        'ward' => '',
        'status' => 'active',
        'payment_type' => 'cash',
        'contacts' => [],
    ], $overrides);
}

function validInsurancePayload(InsuranceProvider $provider, array $overrides = []): array
{
    return array_merge(validCashPayload(), [
        'national_id' => '20000101-99999-99999-1',
        'payment_type' => 'insurance',
        'nhif_provider_id' => (string) $provider->id,
        'nhif_membership_number' => 'MEM-001234',
        'nhif_card_number' => '',
        'nhif_identifier_type' => 'nin',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Cash registration
// ---------------------------------------------------------------------------

test('a cash patient is registered without insurance', function () {
    $user = registrationUser();

    $this->actingAs($user)
        ->post(route('patients.store'), validCashPayload())
        ->assertRedirect();

    $patient = Patient::where('national_id', '20000101-12345-12345-6')->first();
    expect($patient)->not->toBeNull()
        ->and($patient->insurances()->count())->toBe(0);
});

test('cash registration ignores nhif fields even when present', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $payload = validCashPayload([
        'nhif_provider_id' => (string) $provider->id,
        'nhif_membership_number' => 'IGNORED',
        'nhif_identifier_type' => 'nin',
    ]);

    $this->actingAs($user)
        ->post(route('patients.store'), $payload)
        ->assertRedirect();

    $patient = Patient::where('national_id', '20000101-12345-12345-6')->first();
    expect($patient->insurances()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Insurance registration — validation
// ---------------------------------------------------------------------------

test('insurance registration requires membership number', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, ['nhif_membership_number' => '']))
        ->assertSessionHasErrors('nhif_membership_number');
});

test('insurance registration requires identifier type', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, ['nhif_identifier_type' => '']))
        ->assertSessionHasErrors('nhif_identifier_type');
});

test('insurance via NIN requires national_id field', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, [
            'national_id' => '',
            'nhif_identifier_type' => 'nin',
        ]))
        ->assertSessionHasErrors('national_id');
});

test('insurance via CardNo requires nhif_card_number', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, [
            'nhif_identifier_type' => 'CardNo',
            'nhif_card_number' => '',
        ]))
        ->assertSessionHasErrors('nhif_card_number');
});

// ---------------------------------------------------------------------------
// Insurance registration — duplicate membership protection
// ---------------------------------------------------------------------------

test('duplicate active membership number is blocked', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    // Existing patient with same membership number
    $existingPatient = Patient::factory()->create();
    PatientInsurance::factory()->create([
        'patient_id' => $existingPatient->id,
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-001234',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider))
        ->assertSessionHasErrors('nhif_membership_number');
});

test('inactive membership number does not block new registration', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    // An INACTIVE insurance record with same membership
    $existingPatient = Patient::factory()->create();
    PatientInsurance::factory()->create([
        'patient_id' => $existingPatient->id,
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-INACTIVE',
        'status' => 'inactive',
    ]);

    // Mock the NHIF client to return a successful verification
    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'John Doe',
                'NIN' => '20000101-99999-99999-1',
                'Status' => 'active',
                'MembershipNo' => 'MEM-INACTIVE',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-corr-123',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
        $mock->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
        $mock->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-INACTIVE-TEST', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);
        $mock->shouldReceive('checkEligibility')->andReturn([
            'success' => true,
            'data' => ['EligibilityStatus' => 'Eligible', 'CoveragePercent' => '80'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-corr-456',
            'operation' => 'CheckEligibility',
        ]);
    });

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, ['nhif_membership_number' => 'MEM-INACTIVE']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Insurance registration — NHIF verification flow
// ---------------------------------------------------------------------------

test('insurance registration creates patient and insurance when nhif verifies', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'John Doe',
                'NIN' => '20000101-99999-99999-1',
                'Status' => 'active',
                'CardNo' => 'CARD-99999',
                'MembershipNo' => 'MEM-001234',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-corr-789',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
        $mock->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
        $mock->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-TEST', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);
        $mock->shouldReceive('checkEligibility')->once()->andReturn([
            'success' => true,
            'data' => ['EligibilityStatus' => 'Eligible', 'CoveragePercent' => '80'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-corr-abc',
            'operation' => 'CheckEligibility',
        ]);
    });

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $patient = Patient::where('national_id', '20000101-99999-99999-1')->first();
    expect($patient)->not->toBeNull();

    $insurance = $patient->insurances()->first();
    expect($insurance)->not->toBeNull()
        ->and($insurance->membership_number)->toBe('MEM-001234')
        ->and($insurance->insurance_provider_id)->toBe($provider->id)
        ->and($insurance->verification_status)->toBe(PatientInsurance::VERIFICATION_VERIFIED)
        ->and($insurance->is_primary)->toBeTrue();

    $verification = NhifMemberVerification::where('patient_insurance_id', $insurance->id)->first();
    expect($verification)->not->toBeNull()
        ->and($verification->verification_status)->toBe(NhifMemberVerification::STATUS_VERIFIED)
        ->and($verification->identifier_type)->toBe('nin');
});

test('insurance registration fails when nhif api is unavailable', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->andThrow(RuntimeException::class, 'NHIF unreachable');
    });

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider))
        ->assertSessionHasErrors('nhif_verification')
        ->assertRedirect();

    expect(Patient::where('national_id', '20000101-99999-99999-1')->exists())->toBeFalse();
});

test('identity mismatch blocks patient registration', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->andReturn([
            'success' => true,
            'data' => [
                // Name is completely different from patient "John Doe" — NIN intentionally
                // omitted so only the name-based identity check applies.
                'MemberName' => 'Jane Smith',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-mismatch',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
    });

    $this->actingAs($user)
        ->post(route('patients.store'), validInsurancePayload($provider, [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]))
        ->assertSessionHasErrors('nhif_verification')
        ->assertRedirect();

    expect(Patient::where('national_id', '20000101-99999-99999-1')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Security: NHIF credentials must never be in page props
// ---------------------------------------------------------------------------

test('patient create page does not expose nhif credentials', function () {
    $user = registrationUser();
    nhifProvider();

    $response = $this->actingAs($user)->get(route('patients.create'));
    $response->assertOk();

    $content = $response->content();

    // Credentials must not appear anywhere in the rendered page
    expect($content)
        ->not->toContain('NHIF_CLIENT_SECRET')
        ->not->toContain('client_secret')
        ->not->toContain('access_token')
        ->not->toContain('nhif.password')
        ->not->toContain('nhif.client_secret');
});

// ---------------------------------------------------------------------------
// NhifMemberVerificationController — standalone verify endpoint
// ---------------------------------------------------------------------------

test('nhif verify endpoint triggers verification and redirects', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $patient = Patient::factory()->create([
        'first_name' => 'Marcus',
        'last_name' => 'Odhiambo',
        'national_id' => '11111111-11111-11111-1',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-XYZ',
        'verification_status' => 'pending',
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Marcus Odhiambo',
                'NIN' => '11111111-11111-11111-1',
                'Status' => 'active',
                'CardNo' => 'NHIF-CARD-001',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-ctrl',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
        $mock->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
        $mock->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-TEST', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);
        $mock->shouldReceive('checkEligibility')->andReturn([
            'success' => false,
            'data' => [],
            'http_status' => 503,
            'error' => 'Service unavailable',
            'correlation_id' => 'test-ctrl-elig',
            'operation' => 'CheckEligibility',
        ]);
    });

    $this->actingAs($user)
        ->post(route('patients.insurance.nhif-verify', [$patient, $insurance]), [
            'identifier_type' => 'nin',
            'identifier_value' => '11111111-11111-11111-1',
        ])
        ->assertRedirect(route('patients.insurance.show', [$patient, $insurance]));

    expect($insurance->fresh()->verification_status)->toBe(PatientInsurance::VERIFICATION_VERIFIED);
});

test('nhif verify endpoint auto-resolves identifier from insurance when payload is empty', function () {
    $user = registrationUser();

    $provider = InsuranceProvider::factory()->create(['api_enabled' => true]);
    $patient = Patient::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'national_id' => '11111111-11111-11111-1',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'card_number' => 'CARD-12345',
        'verification_status' => PatientInsurance::VERIFICATION_PENDING,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => true,
            'data' => [
                'FullName' => 'John Doe',
                'CardNo' => 'CARD-12345',
                'IsActive' => true,
                'IsValidCard' => true,
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-empty-payload',
            'operation' => 'GetCardDetails',
        ]);
        $mock->shouldReceive('getVisitTypes')->once()->andReturn(['success' => true, 'data' => [], 'error' => null]);
        $mock->shouldReceive('getCardVerifiers')->once()->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'error' => null]);
        $mock->shouldReceive('verifyCard')->once()->andReturn([
            'success' => true,
            'data' => ['authorizationNo' => 'AUTH-EMPTY-TEST'],
            'http_status' => 200,
            'error' => null,
        ]);
        $mock->shouldReceive('checkEligibility')->andReturn([
            'success' => true,
            'data' => [
                'Remarks' => 'Eligible',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'test-elig',
            'operation' => 'CheckEligibility',
        ]);
    });

    $this->actingAs($user)
        ->post(route('patients.insurance.nhif-verify', [$patient, $insurance]), [])
        ->assertRedirect(route('patients.insurance.show', [$patient, $insurance]))
        ->assertSessionHas('success');

    $fresh = $insurance->fresh();
    expect($fresh->verification_status)->toBe(PatientInsurance::VERIFICATION_VERIFIED)
        ->and($fresh->nhif_authorization_no)->toBe('AUTH-EMPTY-TEST')
        ->and($fresh->hasValidAuthorization())->toBeTrue();
});

test('nhif verify endpoint is forbidden without permission', function () {
    $user = User::factory()->create();

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($user)
        ->post(route('patients.insurance.nhif-verify', [$patient, $insurance]), [
            'identifier_type' => 'nin',
            'identifier_value' => '12345',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// NhifMemberVerificationService — identity matching unit-level
// ---------------------------------------------------------------------------

test('nhif verification marks as verified when nin and name match', function () {
    $user = registrationUser();
    $provider = nhifProvider();
    $patient = Patient::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Banda',
        'national_id' => 'NIN-ALICE-001',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Alice Banda',
                'NIN' => 'NIN-ALICE-001',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'unit-001',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => [], 'error' => null, 'http_status' => 200, 'correlation_id' => 'x', 'operation' => 'GetVisitTypes']);
        $mock->shouldReceive('getCardVerifiers')->andReturn(['success' => true, 'data' => [['verifierID' => 'V1']], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'GetCardVerifiers']);
        $mock->shouldReceive('verifyCard')->andReturn(['success' => true, 'data' => ['authorizationNo' => 'AUTH-TEST', 'authorizationStatus' => 'ACCEPTED'], 'http_status' => 200, 'error' => null, 'correlation_id' => 'x', 'operation' => 'VerifyCard']);
    });

    /** @var NhifMemberVerificationService $service */
    $service = app(NhifMemberVerificationService::class);
    $record = $service->verify($insurance, 'nin', 'NIN-ALICE-001', request());

    expect($record->verification_status)->toBe(NhifMemberVerification::STATUS_VERIFIED);
});

test('nhif verification marks as invalid when name does not match', function () {
    $provider = nhifProvider();
    $patient = Patient::factory()->create([
        'first_name' => 'Bob',
        'last_name' => 'Kamau',
        'national_id' => 'NIN-BOB-002',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        // NHIF returns a completely different name — identity mismatch
        $mock->shouldReceive('getMemberByNin')->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Charles Omondi',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'unit-002',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
    });

    /** @var NhifMemberVerificationService $service */
    $service = app(NhifMemberVerificationService::class);
    $record = $service->verify($insurance, 'nin', 'NIN-BOB-002', request());

    expect($record->verification_status)->toBe(NhifMemberVerification::STATUS_INVALID);
});

// ---------------------------------------------------------------------------
// NHIF-first registration lookup — POST /patients/nhif-lookup
// ---------------------------------------------------------------------------

test('nhif lookup by card number returns normalized member details', function () {
    $user = registrationUser();
    nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->with('00060000123456')->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'John Michael Doe',
                'CardNo' => '00060000123456',
                'MembershipNo' => 'MEM-001234',
                'PlanName' => 'Standard',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'lookup-card-1',
            'operation' => 'GetMemberDetailsByCard',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'CardNo',
        'identifier_value' => '00060000123456',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'status' => 'found',
        'member' => [
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Doe',
            'membership_number' => 'MEM-001234',
            'card_number' => '00060000123456',
            'plan_name' => 'Standard',
        ],
        'existing_patient' => null,
    ]);

    expect(Patient::count())->toBe(0);
});

test('nhif lookup normalizes the real GetCardDetails response shape', function () {
    $user = registrationUser();
    nhifProvider();

    // The actual NHIF Verification API response shape (not the older
    // MemberName/NIN/Status guess this mapping used before) — discrete
    // FirstName/MiddleName/LastName, Gender, DateOfBirth, CHNationalID for
    // the NIN, and IsActive/IsValidCard/CardStatus for card status.
    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => true,
            'data' => [
                'CardExistence' => 'CARDS',
                'PackageID' => 106,
                'CardNo' => 'CARD-0001',
                'MembershipNo' => 'MEM0001',
                'SchemeID' => 1001,
                'SchemeName' => 'Standard NHIF Benefit Scheme',
                'FirstName' => 'Sophia',
                'MiddleName' => null,
                'LastName' => 'Wilson',
                'FullName' => 'Sophia  Wilson',
                'Gender' => 'Female',
                'DateOfBirth' => '2008-03-12T00:00:00',
                'CHNationalID' => '19890430111040000124',
                'ExpiryDate' => '2028-01-17T00:00:00',
                'CardStatusID' => 1,
                'CardStatus' => 'Active',
                'IsActive' => true,
                'IsValidCard' => true,
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'lookup-real-shape',
            'operation' => 'GetCardDetails',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'CardNo',
        'identifier_value' => 'CARD-0001',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'status' => 'found',
        'member' => [
            'first_name' => 'Sophia',
            'middle_name' => null,
            'last_name' => 'Wilson',
            'gender' => 'female',
            'date_of_birth' => '2008-03-12',
            'membership_number' => 'MEM0001',
            'card_number' => 'CARD-0001',
            'nin' => '19890430111040000124',
            'plan_name' => 'Standard NHIF Benefit Scheme',
            'expiry_date' => '2028-01-17',
        ],
    ]);
});

test('nhif lookup by nin returns normalized member details', function () {
    $user = registrationUser();
    nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->with('20000101-99999-99999-1')->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Jane Mary Mushi',
                'NIN' => '20000101-99999-99999-1',
                'MembershipNo' => 'MEM-005678',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'lookup-nin-1',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'nin',
        'identifier_value' => '20000101-99999-99999-1',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'status' => 'found',
        'member' => [
            'first_name' => 'Jane',
            'middle_name' => 'Mary',
            'last_name' => 'Mushi',
            'membership_number' => 'MEM-005678',
        ],
    ]);

    expect(Patient::count())->toBe(0);
});

test('nhif lookup reports not found for an unknown card number and creates no patient', function () {
    $user = registrationUser();
    nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => false,
            'data' => [],
            'http_status' => 404,
            'error' => 'Member not found',
            'correlation_id' => 'lookup-card-404',
            'operation' => 'GetMemberDetailsByCard',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'CardNo',
        'identifier_value' => '00060000000000',
    ]);

    $response->assertOk()->assertJson([
        'success' => false,
        'status' => 'not_found',
        'member' => null,
    ]);

    expect(Patient::count())->toBe(0);
});

test('nhif lookup reports not found for an unknown nin and creates no patient', function () {
    $user = registrationUser();
    nhifProvider();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->andReturn([
            'success' => false,
            'data' => [],
            'http_status' => 404,
            'error' => 'Member not found',
            'correlation_id' => 'lookup-nin-404',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'nin',
        'identifier_value' => '00000000-00000-00000-0',
    ]);

    $response->assertOk()->assertJson([
        'success' => false,
        'status' => 'not_found',
        'member' => null,
    ]);

    expect(Patient::count())->toBe(0);
});

test('nhif lookup detects a member already linked to an existing patient', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    $existingPatient = Patient::factory()->create(['first_name' => 'Existing', 'last_name' => 'Member']);
    PatientInsurance::factory()->create([
        'patient_id' => $existingPatient->id,
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-DUP-1',
        'card_number' => '00060000999999',
        'status' => 'active',
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Existing Member',
                'CardNo' => '00060000999999',
                'MembershipNo' => 'MEM-DUP-1',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'lookup-dup-1',
            'operation' => 'GetMemberDetailsByCard',
        ]);
    });

    $response = $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'CardNo',
        'identifier_value' => '00060000999999',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'status' => 'duplicate_patient',
        'existing_patient' => [
            'id' => $existingPatient->id,
            'patient_number' => $existingPatient->patient_number,
        ],
    ]);

    // No second patient was created for the same NHIF member.
    expect(Patient::count())->toBe(1);
});

test('nhif lookup endpoint requires patients.create permission', function () {
    $user = User::factory()->create();
    nhifProvider();

    $this->actingAs($user)->postJson(route('patients.nhif-lookup'), [
        'identifier_type' => 'CardNo',
        'identifier_value' => '00060000123456',
    ])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Security — the browser cannot bypass server-side NHIF verification
// ---------------------------------------------------------------------------

test('store ignores a client-supplied verification flag and independently verifies with nhif', function () {
    $user = registrationUser();
    $provider = nhifProvider();

    // NHIF itself reports a name mismatch — the request also tries to smuggle
    // an unverified/forged "verified" flag, which must have zero effect: the
    // server always derives verification status from its own NHIF call.
    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByNin')->once()->andReturn([
            'success' => true,
            'data' => [
                'MemberName' => 'Totally Different Person',
                'Status' => 'active',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'security-1',
            'operation' => 'GetMemberDetailsByNIN',
        ]);
    });

    $payload = validInsurancePayload($provider, [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'nhif_verified' => true,
        'verification_status' => 'verified',
    ]);

    // The forged flags are ignored; identity mismatch blocks registration entirely.
    $this->actingAs($user)
        ->post(route('patients.store'), $payload)
        ->assertSessionHasErrors('nhif_verification')
        ->assertRedirect();

    expect(Patient::where('national_id', '20000101-99999-99999-1')->exists())->toBeFalse();
});
