<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;

function maternityWorkflowContext(): array
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
    ]);

    $receptionist = userWithRole('receptionist');
    Staff::factory()->create([
        'user_id' => $receptionist->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'status' => 'active',
    ]);

    $midwife = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $midwife->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Senior Midwife',
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

    return compact('hospital', 'maternityDept', 'patient', 'receptionist', 'midwife', 'doctor');
}

test('reception registers maternity walk-in with dedicated visit purpose without creating admission', function () {
    $ctx = maternityWorkflowContext();

    $response = $this->actingAs($ctx['receptionist'])->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['maternityDept']->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'antenatal_care',
        'clinical_notes' => 'Patient presented for routine ANC second trimester check-up.',
    ]);

    $response->assertSessionHasNoErrors();
    $visit = ReceptionVisit::where('patient_id', $ctx['patient']->id)->latest()->first();

    expect($visit)->not->toBeNull();
    expect($visit->department_id)->toBe($ctx['maternityDept']->id);
    expect($visit->visit_type)->toBe('walk_in');
    expect($visit->visit_purpose)->toBe('antenatal_care');
    expect($visit->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_WAITING);

    // CRITICAL: Maternity outpatient visit must NOT automatically create an inpatient admission!
    $admissionCount = Admission::where('patient_id', $ctx['patient']->id)->count();
    expect($admissionCount)->toBe(0);
});

test('maternity outpatient visit is listed in dedicated maternity queue with accurate statuses', function () {
    $ctx = maternityWorkflowContext();

    $visit = ReceptionVisit::create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['maternityDept']->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'labour_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    $response = $this->actingAs($ctx['midwife'])->get(route('maternity.queue'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('maternity/queue')
        ->has('visits.data', 1)
        ->where('visits.data.0.id', $visit->id)
        ->where('visits.data.0.visit_purpose', 'labour_assessment')
        ->where('visits.data.0.maternity_status', 'waiting_for_assessment')
    );
});

test('midwife can update maternity queue status through outpatient care progression', function () {
    $ctx = maternityWorkflowContext();

    $visit = ReceptionVisit::create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['maternityDept']->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'antenatal_care',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    // Transition to assessment_in_progress
    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.queue.status.update', $visit->id), [
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS,
        'notes' => 'Commencing maternal vitals and abdominal palpation.',
    ]);

    $response->assertSessionHasNoErrors();
    expect($visit->fresh()->maternity_status)->toBe('assessment_in_progress');

    // Transition to awaiting_clinician
    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.queue.status.update', $visit->id), [
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_AWAITING_CLINICIAN,
        'notes' => 'Assessment done, BP 118/74, awaiting doctor check.',
    ]);

    $response->assertSessionHasNoErrors();
    expect($visit->fresh()->maternity_status)->toBe('awaiting_clinician');

    // Transition to completed closes the visit without admission
    $response = $this->actingAs($ctx['doctor'])->post(route('maternity.queue.status.update', $visit->id), [
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_COMPLETED,
        'notes' => 'Prescribed prenatal vitamins and scheduled 4-week follow-up.',
    ]);

    $response->assertSessionHasNoErrors();
    $freshVisit = $visit->fresh();
    expect($freshVisit->maternity_status)->toBe('completed');
    expect($freshVisit->status)->toBe(ReceptionVisit::STATUS_COMPLETED);
    expect($freshVisit->completed_at)->not->toBeNull();

    // Verify still zero admissions created
    expect(Admission::where('patient_id', $ctx['patient']->id)->count())->toBe(0);
});

test('unauthorized user without maternity permission cannot view or update queue', function () {
    $ctx = maternityWorkflowContext();
    $accountant = userWithRole('accountant');

    $visit = ReceptionVisit::create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['maternityDept']->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'maternity_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
    ]);

    $this->actingAs($accountant)->get(route('maternity.queue'))->assertForbidden();

    $this->actingAs($accountant)->post(route('maternity.queue.status.update', $visit->id), [
        'maternity_status' => 'assessment_in_progress',
    ])->assertForbidden();
});
