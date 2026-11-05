<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\PartographEntry;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;

function labourMonitoringTestContext(): array
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
        'first_name' => 'Fatuma',
        'last_name' => 'Juma',
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
        'visit_purpose' => 'labour_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    return compact('hospital', 'maternityDept', 'patient', 'midwife', 'doctor', 'visit');
}

test('midwife can update labour onset details and stage of labour on outpatient maternity profile', function () {
    $ctx = labourMonitoringTestContext();

    // Ensure profile is created
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.labour-status.update', $ctx['visit']->id), [
        'labour_stage' => 'active_phase',
        'labour_onset_at' => now()->subHours(3)->toDateTimeString(),
        'labour_onset_type' => 'spontaneous',
        'cervix_effacement_pct' => 80,
        'membrane_status' => 'ruptured',
        'membrane_ruptured_at' => now()->subHours(1)->toDateTimeString(),
        'notes' => 'Active first stage confirmed. Patient managing contractions well with breathing exercises.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $profile = MaternityProfile::where('patient_id', $ctx['patient']->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->labour_stage)->toBe('active_phase');
    expect($profile->labour_onset_type)->toBe('spontaneous');
    expect($profile->cervix_effacement_pct)->toBe(80);
    expect($profile->membrane_status)->toBe('ruptured');
    expect($profile->notes)->toContain('Active first stage confirmed');

    // No admission created
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('midwife can record serial WHO partograph observations during outpatient labour care', function () {
    $ctx = labourMonitoringTestContext();

    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // Entry 1: 4cm dilation (entry into active phase)
    $response1 = $this->actingAs($ctx['midwife'])->post(route('maternity.partograph.store', $ctx['visit']->id), [
        'recorded_at' => now()->subHours(2)->toDateTimeString(),
        'cervical_dilation_cm' => 4.0,
        'descent_of_head' => 4,
        'fetal_heart_rate' => 138,
        'amniotic_fluid' => 'C',
        'moulding' => '0',
        'contractions_per_10min' => 3,
        'contraction_duration' => 'moderate',
        'systolic_bp' => 118,
        'diastolic_bp' => 74,
        'pulse_bpm' => 78,
        'temperature_c' => 36.6,
        'notes' => 'Cervix 4cm, soft, central. Alert line initiated.',
    ]);

    $response1->assertSessionHasNoErrors();

    // Entry 2: 2 hours later, 6cm dilation (following expected curve)
    $response2 = $this->actingAs($ctx['midwife'])->post(route('maternity.partograph.store', $ctx['visit']->id), [
        'recorded_at' => now()->toDateTimeString(),
        'cervical_dilation_cm' => 6.0,
        'descent_of_head' => 3,
        'fetal_heart_rate' => 140,
        'amniotic_fluid' => 'C',
        'moulding' => '+',
        'contractions_per_10min' => 4,
        'contraction_duration' => 'strong',
        'systolic_bp' => 120,
        'diastolic_bp' => 76,
        'pulse_bpm' => 82,
        'notes' => 'Normal progress, head descending, clear liquor.',
    ]);

    $response2->assertSessionHasNoErrors();

    $entries = PartographEntry::where('patient_id', $ctx['patient']->id)->orderBy('recorded_at')->get();
    expect($entries)->toHaveCount(2);

    expect((float) $entries[0]->cervical_dilation_cm)->toBe(4.0);
    expect($entries[0]->fetal_heart_rate)->toBe(138);
    expect((float) $entries[0]->alert_line_dilation_cm)->toBe(4.0);
    expect((bool) $entries[0]->action_line_crossed)->toBeFalse();

    expect((float) $entries[1]->cervical_dilation_cm)->toBe(6.0);
    expect($entries[1]->fetal_heart_rate)->toBe(140);
    expect((bool) $entries[1]->action_line_crossed)->toBeFalse();

    // Outpatient visit remains active without an admission
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('partograph action line crossed flags complication and sets outpatient visit status to admission_recommended without automatic admission', function () {
    $ctx = labourMonitoringTestContext();

    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // Initial entry 5 hours ago at 4cm (sets alert line)
    $this->actingAs($ctx['midwife'])->post(route('maternity.partograph.store', $ctx['visit']->id), [
        'recorded_at' => now()->subHours(5)->toDateTimeString(),
        'cervical_dilation_cm' => 4.0,
        'descent_of_head' => 4,
        'fetal_heart_rate' => 140,
    ]);

    // 5 hours later: cervix still only 4.5cm (WHO active line is alert + 4 hours; 5h delay means action line crossed)
    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.partograph.store', $ctx['visit']->id), [
        'recorded_at' => now()->toDateTimeString(),
        'cervical_dilation_cm' => 4.5,
        'descent_of_head' => 4,
        'fetal_heart_rate' => 150,
        'contractions_per_10min' => 2,
        'contraction_duration' => 'mild',
        'notes' => 'Cervical progress stalled. Secondary arrest of cervical dilation.',
    ]);

    $response->assertSessionHasNoErrors();

    $latestEntry = PartographEntry::where('patient_id', $ctx['patient']->id)
        ->orderByDesc('recorded_at')
        ->first();

    expect((bool) $latestEntry->action_line_crossed)->toBeTrue();

    // Verify visit status was updated to admission_recommended
    $ctx['visit']->refresh();
    expect($ctx['visit']->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_ADMISSION_RECOMMENDED);

    // CRITICAL: Admission must NOT be automatically created; requires deliberate clinical admission workflow!
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('unauthorized user cannot post partograph observations', function () {
    $ctx = labourMonitoringTestContext();

    // Standard user without any staff roles or maternity permissions
    $unauthorizedUser = User::factory()->create();

    $response = $this->actingAs($unauthorizedUser)->post(route('maternity.partograph.store', $ctx['visit']->id), [
        'recorded_at' => now()->toDateTimeString(),
        'cervical_dilation_cm' => 5.0,
        'fetal_heart_rate' => 140,
    ]);

    $response->assertForbidden();
});
