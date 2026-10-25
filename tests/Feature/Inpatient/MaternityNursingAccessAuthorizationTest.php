<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\NursingAccessService;

function maternityNursingTestContext(): array
{
    $hospital = Hospital::factory()->create();

    $inpatientDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'code' => 'INPATIENT',
        'name' => 'Inpatient Ward',
    ]);

    $maternityDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'code' => 'MATERNITY',
        'name' => 'Maternity Department',
    ]);

    $otherDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'code' => 'PHARMACY',
        'name' => 'Pharmacy Department',
    ]);

    // Nurse in Inpatient Department
    $inpatientNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $inpatientNurse->id,
        'hospital_id' => $hospital->id,
        'department_id' => $inpatientDept->id,
        'status' => 'active',
    ]);

    // Nurse assigned to Maternity Department
    $maternityNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $maternityNurse->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'status' => 'active',
    ]);

    // Pharmacy staff in same hospital
    $pharmacyUser = userWithRole('pharmacist');
    Staff::factory()->create([
        'user_id' => $pharmacyUser->id,
        'hospital_id' => $hospital->id,
        'department_id' => $otherDept->id,
        'status' => 'active',
    ]);

    // Maternity Patient Admission
    $patient = Patient::factory()->create(['gender' => 'female']);
    $maternityAdmission = Admission::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $maternityDept->id,
        'ward_type' => 'maternity',
        'status' => Admission::STATUS_ACTIVE,
    ]);

    // General Inpatient Admission
    $generalPatient = Patient::factory()->create();
    $inpatientAdmission = Admission::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $generalPatient->id,
        'department_id' => $inpatientDept->id,
        'ward_type' => 'general',
        'status' => Admission::STATUS_ACTIVE,
    ]);

    return compact(
        'hospital',
        'inpatientDept',
        'maternityDept',
        'otherDept',
        'inpatientNurse',
        'maternityNurse',
        'pharmacyUser',
        'maternityAdmission',
        'inpatientAdmission'
    );
}

test('nurse assigned to INPATIENT department can access nursing workspace for patient admitted in MATERNITY', function () {
    $ctx = maternityNursingTestContext();

    $response = $this->actingAs($ctx['inpatientNurse'])->get(route('inpatient.nursing.workspace', $ctx['maternityAdmission']->id));
    $response->assertStatus(200);
});

test('nurse assigned to INPATIENT department can access maternity workspace for patient admitted in MATERNITY', function () {
    $ctx = maternityNursingTestContext();

    $response = $this->actingAs($ctx['inpatientNurse'])->get(route('inpatient.maternity.show', $ctx['maternityAdmission']->id));
    $response->assertStatus(200);
});

test('nurse assigned to MATERNITY department can access nursing and maternity workspaces', function () {
    $ctx = maternityNursingTestContext();

    $response = $this->actingAs($ctx['maternityNurse'])->get(route('inpatient.nursing.workspace', $ctx['maternityAdmission']->id));
    $response->assertStatus(200);

    $response = $this->actingAs($ctx['maternityNurse'])->get(route('inpatient.maternity.show', $ctx['maternityAdmission']->id));
    $response->assertStatus(200);
});

test('nurse assigned to MATERNITY can cross-access general INPATIENT nursing workspace in same hospital', function () {
    $ctx = maternityNursingTestContext();

    $response = $this->actingAs($ctx['maternityNurse'])->get(route('inpatient.nursing.workspace', $ctx['inpatientAdmission']->id));
    $response->assertStatus(200);
});

test('patient with maternity profile in another ward remains accessible to maternity nursing clinicians', function () {
    $ctx = maternityNursingTestContext();

    // Admission is in general inpatient ward, but has an active maternity profile
    MaternityProfile::create([
        'episode_number' => 'MAT-2026-000101',
        'patient_id' => $ctx['inpatientAdmission']->patient_id,
        'admission_id' => $ctx['inpatientAdmission']->id,
        'care_pathway' => 'inpatient',
        'care_stage' => 'awaiting_assessment',
        'gravida' => 1,
        'para' => 0,
        'status' => 'active',
        'recorded_by' => $ctx['inpatientNurse']->id,
    ]);

    $response = $this->actingAs($ctx['maternityNurse'])->get(route('inpatient.maternity.show', $ctx['inpatientAdmission']->id));
    $response->assertStatus(200);
});

test('unrelated department staff without view-all permission is forbidden from nursing workspace', function () {
    $ctx = maternityNursingTestContext();

    $response = $this->actingAs($ctx['pharmacyUser'])->get(route('inpatient.nursing.workspace', $ctx['maternityAdmission']->id));
    $response->assertStatus(403);
});

test('staff from another hospital facility is strictly forbidden from nursing workspace', function () {
    $ctx = maternityNursingTestContext();

    $otherHospital = Hospital::factory()->create();
    $otherHospitalNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $otherHospitalNurse->id,
        'hospital_id' => $otherHospital->id,
        'department_id' => $ctx['inpatientDept']->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherHospitalNurse)->get(route('inpatient.nursing.workspace', $ctx['maternityAdmission']->id));
    $response->assertStatus(403);
});

test('nursing dashboard query scope includes both Inpatient and Maternity admissions for inpatient nurses', function () {
    $ctx = maternityNursingTestContext();

    $service = app(NursingAccessService::class);
    $scopedIds = $service->scope(Admission::query(), $ctx['inpatientNurse'])->pluck('id')->all();

    expect($scopedIds)->toContain($ctx['maternityAdmission']->id);
    expect($scopedIds)->toContain($ctx['inpatientAdmission']->id);
});
