<?php

use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use App\Services\PatientInsuranceService;

test('a matching provider and membership number triggers a duplicate warning', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();
    PatientInsurance::factory()->create(['insurance_provider_id' => $provider->id, 'membership_number' => 'MEM-999']);

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-999',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertRedirect(route('patients.insurance.create', $patient));
    $response->assertSessionHas('possibleDuplicates');
    $this->assertDatabaseMissing('patient_insurances', ['patient_id' => $patient->id, 'membership_number' => 'MEM-999']);
});

test('the same membership number under a different provider is not a duplicate', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $providerA = InsuranceProvider::factory()->create();
    $providerB = InsuranceProvider::factory()->create();
    PatientInsurance::factory()->create(['insurance_provider_id' => $providerA->id, 'membership_number' => 'MEM-777']);

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $providerB->id,
        'membership_number' => 'MEM-777',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionMissing('possibleDuplicates');
    $this->assertDatabaseHas('patient_insurances', ['patient_id' => $patient->id, 'membership_number' => 'MEM-777', 'insurance_provider_id' => $providerB->id]);
});

test('different patients may legitimately share the same insurance provider', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create();
    Patient::factory()->create()->insurances()->create([
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-AAA',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $newPatient = Patient::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $newPatient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-BBB',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionMissing('possibleDuplicates');
    $this->assertDatabaseHas('patient_insurances', ['patient_id' => $newPatient->id, 'membership_number' => 'MEM-BBB']);
});

test('confirming past a duplicate warning creates the record anyway', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();
    PatientInsurance::factory()->create(['insurance_provider_id' => $provider->id, 'membership_number' => 'MEM-CONFIRM']);

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-CONFIRM',
        'status' => 'active',
        'verification_status' => 'pending',
        'confirm_duplicate' => true,
    ]);

    $response->assertRedirect(route('patients.show', $patient));
    $this->assertDatabaseHas('patient_insurances', ['patient_id' => $patient->id, 'membership_number' => 'MEM-CONFIRM']);
});

test('the duplicate check returns nothing when no membership number or provider is given', function () {
    $service = new PatientInsuranceService(new AuditLogService);

    expect($service->findPossibleDuplicates(1, []))->toBeEmpty();
});
