<?php

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;

test('an authorized user can add insurance to a patient', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();
    $scheme = InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id]);

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'insurance_scheme_id' => $scheme->id,
        'membership_number' => 'MEM-001',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertRedirect(route('patients.show', $patient));
    $response->assertSessionHas('success');
    $this->assertDatabaseHas('patient_insurances', [
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'insurance_scheme_id' => $scheme->id,
        'membership_number' => 'MEM-001',
    ]);
});

test('an unauthorized user cannot add insurance', function () {
    $user = userWithRole('pharmacist');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-002',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('patient_insurances', ['membership_number' => 'MEM-002']);
});

test('a nonexistent provider is rejected', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => 999999,
        'membership_number' => 'MEM-003',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionHasErrors('insurance_provider_id');
});

test('a scheme belonging to a different provider is rejected', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $providerA = InsuranceProvider::factory()->create();
    $providerB = InsuranceProvider::factory()->create();
    $schemeB = InsuranceScheme::factory()->create(['insurance_provider_id' => $providerB->id]);

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $providerA->id,
        'insurance_scheme_id' => $schemeB->id,
        'membership_number' => 'MEM-004',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionHasErrors('insurance_scheme_id');
    $this->assertDatabaseMissing('patient_insurances', ['membership_number' => 'MEM-004']);
});

test('a missing membership number is rejected', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionHasErrors('membership_number');
});

test('an effective_to date before effective_from is rejected', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('patients.insurance.store', $patient), [
        'insurance_provider_id' => $provider->id,
        'membership_number' => 'MEM-005',
        'effective_from' => '2026-06-01',
        'effective_to' => '2026-01-01',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertSessionHasErrors('effective_to');
});

test('an authorized user can update patient insurance', function () {
    $user = userWithRole('super-admin');
    $insurance = PatientInsurance::factory()->create(['card_number' => 'OLD-CARD']);

    $response = $this->actingAs($user)->put(route('patients.insurance.update', [$insurance->patient_id, $insurance->id]), [
        'insurance_provider_id' => $insurance->insurance_provider_id,
        'membership_number' => $insurance->membership_number,
        'card_number' => 'NEW-CARD',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertRedirect(route('patients.show', $insurance->patient_id));
    expect($insurance->fresh()->card_number)->toBe('NEW-CARD');
});

test('an unauthorized user cannot update patient insurance', function () {
    $user = userWithRole('pharmacist');
    $insurance = PatientInsurance::factory()->create(['card_number' => 'UNTOUCHED']);

    $response = $this->actingAs($user)->put(route('patients.insurance.update', [$insurance->patient_id, $insurance->id]), [
        'insurance_provider_id' => $insurance->insurance_provider_id,
        'membership_number' => $insurance->membership_number,
        'card_number' => 'HACKED',
        'status' => 'active',
        'verification_status' => 'pending',
    ]);

    $response->assertForbidden();
    expect($insurance->fresh()->card_number)->toBe('UNTOUCHED');
});

test('a patient insurance record from a different patient in the url is rejected', function () {
    $user = userWithRole('super-admin');
    $insurance = PatientInsurance::factory()->create();
    $otherPatient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.insurance.show', [$otherPatient, $insurance]))->assertNotFound();
});

test('an authorized user can deactivate patient insurance without permanently destroying it', function () {
    $user = userWithRole('super-admin');
    $insurance = PatientInsurance::factory()->create(['status' => 'active', 'is_primary' => true]);

    $response = $this->actingAs($user)->delete(route('patients.insurance.destroy', [$insurance->patient_id, $insurance->id]));

    $response->assertRedirect(route('patients.show', $insurance->patient_id));

    $insurance->refresh();
    expect($insurance->status)->toBe('inactive')
        ->and($insurance->is_primary)->toBeFalse();
    $this->assertDatabaseHas('patient_insurances', ['id' => $insurance->id]);
});

test('a guest is redirected to login for patient insurance routes', function () {
    $patient = Patient::factory()->create();

    $this->get(route('patients.insurance.create', $patient))->assertRedirect(route('login'));
});

test('a user without patient-insurance.view receives a 403', function () {
    $user = User::factory()->create();
    $insurance = PatientInsurance::factory()->create();

    $this->actingAs($user)->get(route('patients.insurance.show', [$insurance->patient_id, $insurance->id]))->assertForbidden();
});

test('an authorized user can verify patient insurance internally', function () {
    $user = userWithRole('super-admin');
    $insurance = PatientInsurance::factory()->create(['verification_status' => 'pending']);

    $response = $this->actingAs($user)->patch(route('patients.insurance.verify', [$insurance->patient_id, $insurance->id]), [
        'verification_status' => 'verified',
    ]);

    $response->assertRedirect(route('patients.show', $insurance->patient_id));

    $insurance->refresh();
    expect($insurance->verification_status)->toBe('verified')
        ->and($insurance->verified_at)->not->toBeNull();
});
