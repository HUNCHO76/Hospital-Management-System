<?php

use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;

test('creating a new primary insurance unmarks the previous primary', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $insuranceA = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => true]);
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-PRIMARY',
        'is_primary' => true,
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertRedirect(route('patients.show', $patient));

    $insuranceB = PatientInsurance::query()->where('membership_number', 'MEM-PRIMARY')->first();

    expect($insuranceA->fresh()->is_primary)->toBeFalse()
        ->and($insuranceB->is_primary)->toBeTrue()
        ->and($patient->insurances()->where('is_primary', true)->count())->toBe(1);
});

test('updating an insurance record to primary unmarks the previous primary', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $insuranceA = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => true]);
    $insuranceB = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => false]);

    $this->actingAs($user)->put(route('patients.insurance.update', [$patient, $insuranceB]), [
        'insurance_provider_id' => $insuranceB->insurance_provider_id,
        'membership_number' => $insuranceB->membership_number,
        'is_primary' => true,
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    expect($insuranceA->fresh()->is_primary)->toBeFalse()
        ->and($insuranceB->fresh()->is_primary)->toBeTrue();
});

test('the make-primary action switches primary insurance safely', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $insuranceA = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => true]);
    $insuranceB = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => false]);

    $response = $this->actingAs($user)->post(route('patients.insurance.make-primary', [$patient, $insuranceB]));

    $response->assertRedirect(route('patients.show', $patient));

    expect($insuranceA->fresh()->is_primary)->toBeFalse()
        ->and($insuranceB->fresh()->is_primary)->toBeTrue()
        ->and($patient->insurances()->where('is_primary', true)->count())->toBe(1);
});

test('multiple insurance records never remain primary at once', function () {
    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => true]);
    PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => true]);

    // The factory does not run through the service, so this simulates a bad
    // state; the service must be what keeps this from happening in practice.
    $user = userWithRole('super-admin');
    $third = PatientInsurance::factory()->create(['patient_id' => $patient->id, 'is_primary' => false]);

    $this->actingAs($user)->post(route('patients.insurance.make-primary', [$patient, $third]));

    expect($patient->insurances()->where('is_primary', true)->count())->toBe(1);
});
