<?php

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\Patient;
use App\Models\PatientInsurance;

test('a provider can have multiple schemes', function () {
    $provider = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->count(3)->create(['insurance_provider_id' => $provider->id]);

    expect($provider->schemes()->count())->toBe(3);
});

test('a patient insurance record belongs to a provider', function () {
    $provider = InsuranceProvider::factory()->create();
    $patientInsurance = PatientInsurance::factory()->create(['insurance_provider_id' => $provider->id]);

    expect($patientInsurance->insuranceProvider)->toBeInstanceOf(InsuranceProvider::class)
        ->and($patientInsurance->insuranceProvider->id)->toBe($provider->id);
});

test('a patient insurance record can belong to a scheme', function () {
    $scheme = InsuranceScheme::factory()->create();
    $patientInsurance = PatientInsurance::factory()->create(['insurance_scheme_id' => $scheme->id]);

    expect($patientInsurance->insuranceScheme)->toBeInstanceOf(InsuranceScheme::class)
        ->and($patientInsurance->insuranceScheme->id)->toBe($scheme->id);
});

test('a patient can hold insurance from more than one provider', function () {
    $patient = Patient::factory()->create();
    $nhif = InsuranceProvider::factory()->create(['name' => 'NHIF']);
    $private = InsuranceProvider::factory()->create(['name' => 'Private Insurer']);

    PatientInsurance::factory()->create(['patient_id' => $patient->id, 'insurance_provider_id' => $nhif->id, 'is_primary' => true]);
    PatientInsurance::factory()->create(['patient_id' => $patient->id, 'insurance_provider_id' => $private->id, 'is_primary' => false]);

    expect($patient->insurances()->count())->toBe(2);
});
