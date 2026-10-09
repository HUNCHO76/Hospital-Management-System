<?php

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\Patient;
use App\Models\PatientInsurance;
use Illuminate\Support\Carbon;

test('an insurance provider has many schemes and patient insurances', function () {
    $provider = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id]);
    PatientInsurance::factory()->create(['insurance_provider_id' => $provider->id]);

    expect($provider->schemes)->toHaveCount(1)
        ->and($provider->schemes->first())->toBeInstanceOf(InsuranceScheme::class)
        ->and($provider->patientInsurances)->toHaveCount(1)
        ->and($provider->patientInsurances->first())->toBeInstanceOf(PatientInsurance::class);
});

test('a scheme belongs to its provider', function () {
    $provider = InsuranceProvider::factory()->create();
    $scheme = InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id]);

    expect($scheme->insuranceProvider)->toBeInstanceOf(InsuranceProvider::class)
        ->and($scheme->insuranceProvider->id)->toBe($provider->id);
});

test('a patient insurance record links a patient, provider and scheme', function () {
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();
    $scheme = InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'insurance_scheme_id' => $scheme->id,
    ]);

    expect($insurance->patient)->toBeInstanceOf(Patient::class)
        ->and($insurance->insuranceProvider->id)->toBe($provider->id)
        ->and($insurance->insuranceScheme->id)->toBe($scheme->id);
});

test('patient insurance casts is_primary to boolean and verified_at to datetime', function () {
    $insurance = PatientInsurance::factory()->create([
        'is_primary' => 1,
        'verified_at' => '2026-01-15 10:00:00',
    ]);

    $fresh = $insurance->fresh();

    expect($fresh->is_primary)->toBeTrue()
        ->and($fresh->verified_at)->toBeInstanceOf(Carbon::class);
});

test('an insurance provider is soft deleted rather than removed', function () {
    $provider = InsuranceProvider::factory()->create();

    $provider->delete();

    expect(InsuranceProvider::query()->find($provider->id))->toBeNull()
        ->and(InsuranceProvider::withTrashed()->find($provider->id))->not->toBeNull();
});

test('insurance authorization is valid within 24 hours of verification', function () {
    $insurance = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-123456',
        'verified_at' => now()->subHours(10),
    ]);

    expect($insurance->hasValidAuthorization())->toBeTrue()
        ->and($insurance->isAuthorizationExpired())->toBeFalse()
        ->and($insurance->authorizationHoursRemaining())->toBeGreaterThan(0.0)
        ->and($insurance->authorizationExpiresAt())->toBeInstanceOf(Carbon::class);
});

test('insurance authorization expires after 24 hours', function () {
    $insurance = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-EXPIRED',
        'verified_at' => now()->subHours(25),
    ]);

    expect($insurance->hasValidAuthorization())->toBeFalse()
        ->and($insurance->isAuthorizationExpired())->toBeTrue()
        ->and($insurance->authorizationHoursRemaining())->toBe(0.0);
});

test('scopeWithValidAuthorization filters out records verified more than 24 hours ago', function () {
    $valid = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-VALID',
        'verified_at' => now()->subHours(2),
    ]);

    $expired = PatientInsurance::factory()->create([
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-STALE',
        'verified_at' => now()->subHours(26),
    ]);

    $results = PatientInsurance::withValidAuthorization()->pluck('id')->all();

    expect($results)->toContain($valid->id)
        ->and($results)->not->toContain($expired->id);
});

test('patient activeNhifInsurance returns null when authorization exceeds 24 hours', function () {
    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_scheme_id' => 101,
        'nhif_authorization_no' => 'AUTH-OLD',
        'verified_at' => now()->subHours(25),
    ]);

    expect($patient->activeNhifInsurance())->toBeNull()
        ->and($patient->latestNhifInsurance()?->id)->toBe($insurance->id);

    // After re-verification (within 24 hours):
    $insurance->update(['verified_at' => now(), 'nhif_authorization_no' => 'AUTH-NEW']);
    $patient->refresh();

    expect($patient->activeNhifInsurance()?->id)->toBe($insurance->id)
        ->and($patient->activeNhifInsurance()?->nhif_authorization_no)->toBe('AUTH-NEW');
});

