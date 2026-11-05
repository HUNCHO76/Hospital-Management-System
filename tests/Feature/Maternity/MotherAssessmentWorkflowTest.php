<?php

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\VitalSign;

function motherAssessmentContext(): array
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
        'first_name' => 'Fatma',
        'last_name' => 'Juma',
    ]);

    $midwife = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $midwife->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Nurse Midwife',
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
        'visit_purpose' => 'maternity_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    return compact('hospital', 'maternityDept', 'patient', 'midwife', 'doctor', 'visit');
}

test('midwife can view mother assessment screen and initializes active profile and encounter', function () {
    $ctx = motherAssessmentContext();

    $response = $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('maternity/mother-assessment')
        ->where('visit.id', $ctx['visit']->id)
        ->has('profile')
        ->has('vitalSigns')
        ->has('riskClassifications')
    );

    // Profile and encounter are created and linked to the visit
    $profile = MaternityProfile::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->patient_id)->toBe($ctx['patient']->id);
    expect($profile->care_pathway)->toBe(MaternityProfile::CARE_PATHWAY_OUTPATIENT);

    $encounter = ClinicalEncounter::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($encounter)->not->toBeNull();
    expect($encounter->patient_id)->toBe($ctx['patient']->id);

    // ZERO admissions created
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('midwife can record mother pregnancy details, parity, and obstetric history', function () {
    $ctx = motherAssessmentContext();

    // First visit initializes profile
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.mother-profile.update', $ctx['visit']->id), [
        'gravida' => 3,
        'para' => 2,
        'living_children' => 2,
        'abortions' => 0,
        'last_menstrual_period' => now()->subWeeks(24)->toDateString(),
        'risk_classification' => 'moderate_risk',
        'risk_factors' => ['Previous Caesarean Section', 'History of Pre-eclampsia/Eclampsia'],
        'previous_pregnancy_history' => '1st pregnancy (2020) SVD at term, male 3.2kg. 2nd pregnancy (2022) Emergency C/S for fetal distress, female 3.5kg.',
        'medical_history' => 'Known mild chronic hypertension on diet control, no known asthma or diabetes.',
        'obstetric_history' => 'Scarred uterus, uneventful recovery post-CS.',
        'allergies' => 'Penicillin (skin rash)',
        'current_medications' => 'Ferrous sulphate 200mg od, Folic acid 5mg od',
        'presentation' => 'cephalic',
        'membrane_status' => 'intact',
        'blood_group' => 'O',
        'rhesus' => '+',
        'hiv_status' => 'negative',
        'vdrl_rpr_status' => 'non_reactive',
        'notes' => 'Mother is in good general condition. Fetal movements felt well.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $profile = MaternityProfile::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->gravida)->toBe(3);
    expect($profile->para)->toBe(2);
    expect($profile->risk_classification)->toBe('moderate_risk');
    expect($profile->previous_pregnancy_history)->toContain('1st pregnancy');
    expect($profile->medical_history)->toContain('mild chronic hypertension');
    expect($profile->allergies)->toContain('Penicillin');
    expect($profile->current_medications)->toContain('Ferrous sulphate');

    // Auto calculated EDD (LMP + 280 days)
    expect($profile->estimated_date_of_delivery)->not->toBeNull();
    expect($profile->gestational_age_weeks)->toBeGreaterThanOrEqual(23.5);

    // Queue status automatically advanced to assessment_in_progress
    expect($ctx['visit']->fresh()->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS);
});

test('mother vital signs and biochemical observations can be recorded repeatedly without overwriting', function () {
    $ctx = motherAssessmentContext();

    // Initialize profile & encounter
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // First observation: baseline vitals at triage
    $response1 = $this->actingAs($ctx['midwife'])->post(route('maternity.vital-signs.store', $ctx['visit']->id), [
        'systolic_mmhg' => 125,
        'diastolic_mmhg' => 82,
        'pulse_bpm' => 76,
        'temperature_c' => 36.6,
        'respiratory_rate_bpm' => 18,
        'oxygen_saturation_pct' => 99,
        'weight_kg' => 68.0,
        'height_cm' => 160.0,
        'urine_protein' => 'nil',
        'urine_glucose' => 'nil',
        'urine_acetone' => 'nil',
        'blood_glucose_mmol' => 5.2,
        'observation_notes' => 'Baseline booking vitals.',
        'measured_at' => now()->subHours(2)->toDateTimeString(),
    ]);

    $response1->assertSessionHasNoErrors();
    $vitalsCount = VitalSign::where('reception_visit_id', $ctx['visit']->id)->count();
    expect($vitalsCount)->toBe(1);

    $v1 = VitalSign::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($v1->systolic_mmhg)->toBe(125);
    expect($v1->diastolic_mmhg)->toBe(82);
    expect((float) $v1->bmi)->toBe(26.56); // 68 / (1.6^2)
    expect($v1->urine_protein)->toBe('nil');
    expect((float) $v1->blood_glucose_mmol)->toBe(5.2);

    // Second repeated observation: 2 hours later
    $response2 = $this->actingAs($ctx['midwife'])->post(route('maternity.vital-signs.store', $ctx['visit']->id), [
        'systolic_mmhg' => 128,
        'diastolic_mmhg' => 84,
        'pulse_bpm' => 80,
        'temperature_c' => 36.8,
        'respiratory_rate_bpm' => 19,
        'oxygen_saturation_pct' => 98,
        'urine_protein' => 'trace',
        'urine_glucose' => 'nil',
        'observation_notes' => 'Repeat vitals before doctor review.',
        'measured_at' => now()->toDateTimeString(),
    ]);

    $response2->assertSessionHasNoErrors();

    // CRITICAL: Historical measurement must NOT be overwritten!
    $vitalsList = VitalSign::where('reception_visit_id', $ctx['visit']->id)->orderBy('id')->get();
    expect($vitalsList)->toHaveCount(2);

    // First vital intact
    expect($vitalsList[0]->systolic_mmhg)->toBe(125);
    expect($vitalsList[0]->urine_protein)->toBe('nil');

    // Second vital recorded as independent row
    expect($vitalsList[1]->systolic_mmhg)->toBe(128);
    expect($vitalsList[1]->urine_protein)->toBe('trace');

    // Still ZERO admissions
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('unauthorized user cannot record mother assessment details', function () {
    $ctx = motherAssessmentContext();
    $accountant = userWithRole('accountant');

    $response = $this->actingAs($accountant)->get(route('maternity.mother-assessment', $ctx['visit']->id));
    $response->assertForbidden();

    $postResponse = $this->actingAs($accountant)->post(route('maternity.mother-profile.update', $ctx['visit']->id), [
        'gravida' => 2,
        'para' => 1,
        'risk_classification' => 'low_risk',
    ]);
    $postResponse->assertForbidden();
});

test('existing patient blood group is automatically parsed and prefilled on mother assessment', function () {
    $ctx = motherAssessmentContext();
    $ctx['patient']->update(['blood_group' => 'A-']);

    $response = $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('maternity/mother-assessment')
        ->where('profile.blood_group', 'A')
        ->where('profile.rhesus', '-')
    );
});

test('recording blood group in mother assessment synchronizes back to master patient record', function () {
    $ctx = motherAssessmentContext();
    $ctx['patient']->update(['blood_group' => null]);

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.mother-profile.update', $ctx['visit']->id), [
        'gravida' => 1,
        'para' => 0,
        'risk_classification' => 'low_risk',
        'blood_group' => 'O',
        'rhesus' => '+',
    ]);

    $response->assertSessionHasNoErrors();
    $ctx['patient']->refresh();

    expect($ctx['patient']->blood_group)->toBe('O+');
});

