<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\FetalAssessment;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\VitalSign;

function fetalAssessmentTestContext(): array
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
        'first_name' => 'Amina',
        'last_name' => 'Bakari',
    ]);

    $midwife = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $midwife->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Registered Midwife',
        'status' => 'active',
    ]);

    $doctor = userWithRole('doctor');
    Staff::factory()->create([
        'user_id' => $doctor->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Obstetrician',
        'status' => 'active',
    ]);

    $visit = ReceptionVisit::create([
        'patient_id' => $patient->id,
        'department_id' => $maternityDept->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'antenatal_care',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    return compact('hospital', 'maternityDept', 'patient', 'midwife', 'doctor', 'visit');
}

test('midwife can record fetal assessment for singleton pregnancy without storing as maternal vital signs', function () {
    $ctx = fetalAssessmentTestContext();

    // Load mother assessment page to ensure profile and encounter are initialized
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'gestational_age_weeks' => 32.4,
        'number_of_fetuses' => 1,
        'fetus_identifier' => 'Singleton',
        'fetal_heart_rate' => 142,
        'fetal_movements' => 'normal',
        'fundal_height_cm' => 32.0,
        'presentation' => 'cephalic',
        'lie' => 'longitudinal',
        'estimated_fetal_weight_grams' => 1850,
        'ultrasound_findings' => 'Placenta posterior high grade 1, Liquor volume adequate (AFI 13cm).',
        'clinical_notes' => 'Fetal heart sound clear and regular. Normal fetal growth velocity.',
        'assessed_at' => now()->toDateTimeString(),
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $assessment = FetalAssessment::where('patient_id', $ctx['patient']->id)->first();
    expect($assessment)->not->toBeNull();
    expect($assessment->fetus_identifier)->toBe('Singleton');
    expect($assessment->fetal_heart_rate)->toBe(142);
    expect($assessment->fundal_height_cm)->toBe(32.0);
    expect($assessment->presentation)->toBe('cephalic');
    expect($assessment->lie)->toBe('longitudinal');
    expect($assessment->estimated_fetal_weight_grams)->toBe(1850);
    expect($assessment->ultrasound_findings)->toContain('Liquor volume adequate');

    // CRITICAL: Fetal observation must NOT be stored in maternal vital signs!
    $maternalVitalsCount = VitalSign::where('reception_visit_id', $ctx['visit']->id)->count();
    expect($maternalVitalsCount)->toBe(0);

    // CRITICAL: Outpatient visit remains without inpatient admission!
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('midwife can record and distinguish multiple fetuses in twin pregnancy with repeated readings', function () {
    $ctx = fetalAssessmentTestContext();

    // Initialize visit profile
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // Record assessment for Twin 1 (Fetus A)
    $respA = $this->actingAs($ctx['midwife'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'gestational_age_weeks' => 34.0,
        'number_of_fetuses' => 2,
        'fetus_identifier' => 'Fetus A',
        'fetal_heart_rate' => 138,
        'fetal_movements' => 'normal',
        'fundal_height_cm' => 38.0,
        'presentation' => 'cephalic',
        'lie' => 'longitudinal',
        'estimated_fetal_weight_grams' => 2200,
        'clinical_notes' => 'Twin A presenting part engaged.',
        'assessed_at' => now()->subMinutes(10)->toDateTimeString(),
    ]);
    $respA->assertSessionHasNoErrors();

    // Record assessment for Twin 2 (Fetus B)
    $respB = $this->actingAs($ctx['midwife'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'gestational_age_weeks' => 34.0,
        'number_of_fetuses' => 2,
        'fetus_identifier' => 'Fetus B',
        'fetal_heart_rate' => 146,
        'fetal_movements' => 'normal',
        'fundal_height_cm' => 38.0,
        'presentation' => 'breech',
        'lie' => 'longitudinal',
        'estimated_fetal_weight_grams' => 2050,
        'clinical_notes' => 'Twin B presenting breech in upper right quadrant.',
        'assessed_at' => now()->toDateTimeString(),
    ]);
    $respB->assertSessionHasNoErrors();

    $assessments = FetalAssessment::where('patient_id', $ctx['patient']->id)->orderBy('id')->get();
    expect($assessments)->toHaveCount(2);

    // Both fetuses are explicitly distinguished
    expect($assessments[0]->fetus_identifier)->toBe('Fetus A');
    expect($assessments[0]->fetal_heart_rate)->toBe(138);
    expect($assessments[0]->presentation)->toBe('cephalic');

    expect($assessments[1]->fetus_identifier)->toBe('Fetus B');
    expect($assessments[1]->fetal_heart_rate)->toBe(146);
    expect($assessments[1]->presentation)->toBe('breech');

    // Repeated reading for Fetus A later
    $respA2 = $this->actingAs($ctx['doctor'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'gestational_age_weeks' => 34.0,
        'number_of_fetuses' => 2,
        'fetus_identifier' => 'Fetus A',
        'fetal_heart_rate' => 140,
        'fetal_movements' => 'normal',
        'clinical_notes' => 'FHR re-checked, stable reactive trace.',
        'assessed_at' => now()->addMinutes(30)->toDateTimeString(),
    ]);
    $respA2->assertSessionHasNoErrors();

    // Historical progress is retained (3 records now)
    expect(FetalAssessment::where('patient_id', $ctx['patient']->id)->count())->toBe(3);
});

test('fetal assessment requires at least one observation parameter', function () {
    $ctx = fetalAssessmentTestContext();

    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'number_of_fetuses' => 1,
        'fetus_identifier' => 'Singleton',
    ]);

    $response->assertSessionHasErrors(['fetal_observations']);
});

test('unauthorized user cannot record fetal assessment', function () {
    $ctx = fetalAssessmentTestContext();
    $accountant = userWithRole('accountant');

    $response = $this->actingAs($accountant)->post(route('maternity.fetal-assessments.store', $ctx['visit']->id), [
        'number_of_fetuses' => 1,
        'fetus_identifier' => 'Singleton',
        'fetal_heart_rate' => 135,
    ]);

    $response->assertForbidden();
});
