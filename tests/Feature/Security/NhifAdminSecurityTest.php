<?php

use App\Models\Hospital;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\NhifUtilization;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\RadiologyProcedure;
use App\Models\Staff;

function createAdminSecurityContext(): array
{
    $hospital = Hospital::factory()->create();
    $adminUser = userWithRole('hospital-admin');
    Staff::factory()->create([
        'user_id' => $adminUser->id,
        'hospital_id' => $hospital->id,
    ]);

    $unauthorizedUser = userWithRole('receptionist');
    Staff::factory()->create([
        'user_id' => $unauthorizedUser->id,
        'hospital_id' => $hospital->id,
    ]);

    return compact('hospital', 'adminUser', 'unauthorizedUser');
}

test('unauthorized user cannot view NHIF dashboard', function () {
    $ctx = createAdminSecurityContext();

    $this->actingAs($ctx['unauthorizedUser'])
        ->get(route('nhif.dashboard'))
        ->assertForbidden();
});

test('authorized hospital admin can view NHIF dashboard', function () {
    $ctx = createAdminSecurityContext();

    $this->actingAs($ctx['adminUser'])
        ->get(route('nhif.dashboard'))
        ->assertOk();
});

test('unauthorized user cannot view NHIF item mapping index', function () {
    $ctx = createAdminSecurityContext();

    $this->actingAs($ctx['unauthorizedUser'])
        ->get(route('nhif.item-mapping.index'))
        ->assertForbidden();
});

test('unauthorized user cannot update NHIF item mappings', function () {
    $ctx = createAdminSecurityContext();

    $labTest = LaboratoryTest::factory()->create();
    $med = Medication::factory()->create();
    $proc = RadiologyProcedure::factory()->create();

    $this->actingAs($ctx['unauthorizedUser'])
        ->patch(route('nhif.item-mapping.laboratory-tests.update', $labTest), [
            'nhif_code' => 'ATTACK_CODE',
        ])
        ->assertForbidden();

    $this->actingAs($ctx['unauthorizedUser'])
        ->patch(route('nhif.item-mapping.medications.update', $med), [
            'nhif_code' => 'ATTACK_CODE',
        ])
        ->assertForbidden();

    $this->actingAs($ctx['unauthorizedUser'])
        ->patch(route('nhif.item-mapping.radiology-procedures.update', $proc), [
            'nhif_code' => 'ATTACK_CODE',
        ])
        ->assertForbidden();
});

test('authorized user can update NHIF item mappings', function () {
    $ctx = createAdminSecurityContext();

    $labTest = LaboratoryTest::factory()->create();

    $response = $this->actingAs($ctx['adminUser'])
        ->patch(route('nhif.item-mapping.laboratory-tests.update', $labTest), [
            'nhif_code' => 'VALID_NHIF_001',
        ]);

    $response->assertRedirect();
    expect($labTest->fresh()->nhif_code)->toBe('VALID_NHIF_001');
});

test('unauthorized user cannot view utilizations list', function () {
    $ctx = createAdminSecurityContext();

    $this->actingAs($ctx['unauthorizedUser'])
        ->get(route('nhif.utilization.index'))
        ->assertForbidden();
});

test('cross-facility utilization access is denied', function () {
    $ctx1 = createAdminSecurityContext();
    $ctx2 = createAdminSecurityContext();

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    $utilization = NhifUtilization::create([
        'utilization_number' => 'UTZ-TEST-001',
        'idempotency_key' => 'idemp_utz_001',
        'hospital_id' => $ctx1['hospital']->id,
        'patient_id' => $patient->id,
        'patient_insurance_id' => $insurance->id,
        'module' => NhifUtilization::MODULE_LAB,
        'source_type' => 'test',
        'source_id' => 1,
        'service_instance_type' => 'test',
        'service_instance_id' => 1,
        'nhif_item_code' => 'NHIF001',
        'nhif_item_name' => 'Lab Test',
        'quantity' => 1,
        'hms_unit_price' => 100,
        'hms_amount' => 100,
        'service_date' => now()->toDateString(),
        'status' => NhifUtilization::STATUS_PENDING,
    ]);

    // Admin at Hospital 2 cannot access Hospital 1 utilization
    $this->actingAs($ctx2['adminUser'])
        ->get(route('nhif.utilization.show', $utilization))
        ->assertForbidden();
});
