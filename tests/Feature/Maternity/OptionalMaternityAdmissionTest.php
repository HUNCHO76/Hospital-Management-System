<?php

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\FetalAssessment;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\PartographEntry;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;
use App\Models\VitalSign;

function optionalAdmissionTestContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'MAIN']);

    $maternityDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'name' => 'Maternity Department',
        'code' => 'MATERNITY',
        'status' => 'active',
    ]);

    $patient = Patient::factory()->create([
        'gender' => 'female',
        'first_name' => 'Zuhura',
        'last_name' => 'Rashid',
    ]);

    $doctor = userWithRole('doctor');
    Staff::factory()->create([
        'user_id' => $doctor->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Obstetrician',
        'status' => 'active',
    ]);

    $midwife = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $midwife->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Registered Midwife',
        'status' => 'active',
    ]);

    $visit = ReceptionVisit::create([
        'patient_id' => $patient->id,
        'department_id' => $maternityDept->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'maternity_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    return compact('hospital', 'maternityDept', 'patient', 'doctor', 'midwife', 'visit');
}

test('authorized clinician can initiate optional maternity admission preserving all outpatient records without duplicates', function () {
    $ctx = optionalAdmissionTestContext();

    // 1. Mother assessment performed as outpatient
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));
    $profile = MaternityProfile::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->care_pathway)->toBe(MaternityProfile::CARE_PATHWAY_OUTPATIENT);
    expect($profile->admission_id)->toBeNull();

    $encounter = $ctx['visit']->clinicalEncounter;
    expect($encounter)->not->toBeNull();

    // 2. Outpatient vitals and fetal assessment documented
    $this->actingAs($ctx['midwife'])->post(route('maternity.vital-signs.store', $ctx['visit']->id), [
        'systolic_mmhg' => 145,
        'diastolic_mmhg' => 95,
        'pulse_bpm' => 84,
        'temperature_c' => 36.8,
        'urine_protein' => '2+',
    ]);

    $this->actingAs($ctx['midwife'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'fetal_heart_rate' => 136,
        'fetal_movements' => 'normal',
        'fundal_height_cm' => 38,
        'presentation' => 'cephalic',
        'lie' => 'longitudinal',
        'number_of_fetuses' => 1,
        'fetus_identifier' => 'Fetus 1',
    ]);

    expect(VitalSign::where('reception_visit_id', $ctx['visit']->id)->count())->toBe(1);
    expect(FetalAssessment::where('reception_visit_id', $ctx['visit']->id)->count())->toBe(1);
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);

    // 3. Clinical decision to admit (e.g. Pre-eclampsia / Impending labour)
    $response = $this->actingAs($ctx['doctor'])->post(route('admissions.store'), [
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['maternityDept']->id,
        'clinical_encounter_id' => $encounter->id,
        'reception_visit_id' => $ctx['visit']->id,
        'admission_type' => 'emergency',
        'diagnosis_at_admission' => 'Moderate pre-eclampsia with proteinuria (2+) at 38 weeks. Inpatient maternal-fetal monitoring required.',
        'notes' => 'Admit to Labour/Maternity Ward for BP control and fetal NST monitoring.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    // 4. Verify admission created and linked
    $admission = Admission::where('patient_id', $ctx['patient']->id)->first();
    expect($admission)->not->toBeNull();
    expect($admission->clinical_encounter_id)->toBe($encounter->id);
    expect($admission->diagnosis_at_admission)->toContain('pre-eclampsia');
    expect($admission->status)->toBe(Admission::STATUS_PENDING);

    // 5. Verify NO duplicate patient or encounter was created
    expect(Patient::where('id', $ctx['patient']->id)->count())->toBe(1);
    expect(ClinicalEncounter::where('patient_id', $ctx['patient']->id)->count())->toBe(1);

    // 6. Verify existing maternity profile is now linked to admission
    $profile->refresh();
    expect($profile->admission_id)->toBe($admission->id);
    expect($profile->care_pathway)->toBe(MaternityProfile::CARE_PATHWAY_INPATIENT);

    // 7. Verify visit queue status updated to admitted
    $ctx['visit']->refresh();
    expect($ctx['visit']->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_ADMITTED);

    // 8. Verify historical outpatient assessments remain intact
    expect(VitalSign::where('reception_visit_id', $ctx['visit']->id)->count())->toBe(1);
    expect(FetalAssessment::where('reception_visit_id', $ctx['visit']->id)->count())->toBe(1);
});

test('outpatient maternity visit can proceed to completion with follow up plan without requiring admission', function () {
    $ctx = optionalAdmissionTestContext();

    // Mother assessment as normal outpatient ANC
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // Vital signs normal
    $this->actingAs($ctx['midwife'])->post(route('maternity.vital-signs.store', $ctx['visit']->id), [
        'systolic_mmhg' => 112,
        'diastolic_mmhg' => 70,
        'pulse_bpm' => 74,
        'temperature_c' => 36.5,
    ]);

    // Complete outpatient visit with follow-up plan
    $response = $this->actingAs($ctx['doctor'])->post(route('maternity.queue.status.update', $ctx['visit']->id), [
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_COMPLETED,
        'notes' => 'Routine ANC visit complete. Normal maternal-fetal parameters. Return in 2 weeks for follow-up.',
    ]);

    $response->assertSessionHasNoErrors();
    $ctx['visit']->refresh();
    expect($ctx['visit']->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_COMPLETED);

    // ZERO admissions created
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('unauthorized user cannot initiate admission', function () {
    $ctx = optionalAdmissionTestContext();
    $unauthorizedUser = User::factory()->create();

    $response = $this->actingAs($unauthorizedUser)->post(route('admissions.store'), [
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'diagnosis_at_admission' => 'Clinical admission request',
    ]);

    $response->assertForbidden();
});
