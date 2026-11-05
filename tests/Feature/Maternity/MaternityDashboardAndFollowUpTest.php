<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;

function dashboardTestContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'MAIN']);

    $maternityDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'name' => 'Maternity Department',
        'code' => 'MATERNITY',
        'status' => 'active',
    ]);

    $patient = Patient::factory()->create(['gender' => 'female']);

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

    $visit = ReceptionVisit::create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $maternityDept->id,
        'visit_number' => 'V-MAT-DASH-'.rand(1000, 9999),
        'visit_type' => 'walk_in',
        'visit_purpose' => 'antenatal_care',
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
        'status' => ReceptionVisit::STATUS_WAITING,
        'created_at' => now(),
    ]);

    $profile = MaternityProfile::create([
        'patient_id' => $patient->id,
        'reception_visit_id' => $visit->id,
        'gravida' => 2,
        'para' => 1,
        'living_children' => 1,
        'abortions' => 0,
        'risk_classification' => 'low_risk',
        'care_pathway' => MaternityProfile::CARE_PATHWAY_OUTPATIENT,
        'care_stage' => MaternityProfile::CARE_STAGE_ASSESSMENT_IN_PROGRESS,
        'status' => 'active',
        'recorded_by' => $midwife->id,
    ]);

    return compact('hospital', 'maternityDept', 'patient', 'midwife', 'doctor', 'visit', 'profile');
}

test('authorized clinician can view maternity dashboard', function () {
    $ctx = dashboardTestContext();

    $response = $this->actingAs($ctx['doctor'])->get(route('maternity.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('maternity/dashboard')
        ->has('metrics')
        ->has('activeQueue')
        ->has('recentDeliveries')
        ->has('upcomingFollowUps')
    );
});

test('unauthorized user cannot view maternity dashboard', function () {
    $ctx = dashboardTestContext();
    $unprivilegedUser = User::factory()->create();

    $response = $this->actingAs($unprivilegedUser)->get(route('maternity.dashboard'));

    $response->assertForbidden();
});

test('clinician can update follow-up appointment plan and visit status', function () {
    $ctx = dashboardTestContext();

    $followUpDate = Carbon::tomorrow()->toDateString();

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.follow-up.update', $ctx['visit']->id), [
        'follow_up_date' => $followUpDate,
        'follow_up_purpose' => 'anc_routine',
        'follow_up_notes' => 'Review BP and fundal growth at 32 weeks gestation. Advised on danger signs.',
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_FOLLOW_UP_REQUIRED,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $ctx['profile']->refresh();
    $ctx['visit']->refresh();

    expect($ctx['profile']->follow_up_date->toDateString())->toBe($followUpDate);
    expect($ctx['profile']->follow_up_purpose)->toBe('anc_routine');
    expect($ctx['profile']->follow_up_notes)->toContain('Review BP and fundal growth');
    expect($ctx['visit']->maternity_status)->toBe(ReceptionVisit::MATERNITY_STATUS_FOLLOW_UP_REQUIRED);
});

test('follow up schedule appears in upcoming follow ups on dashboard', function () {
    $ctx = dashboardTestContext();

    $followUpDate = Carbon::tomorrow()->toDateString();
    $ctx['profile']->update([
        'follow_up_date' => $followUpDate,
        'follow_up_purpose' => 'pnc_7_day',
        'follow_up_notes' => 'Check neonatal cord healing and maternal perineal wound.',
    ]);

    $response = $this->actingAs($ctx['midwife'])->get(route('maternity.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('upcomingFollowUps.0.patient_id', $ctx['patient']->id)
        ->where('upcomingFollowUps.0.follow_up_purpose', 'pnc_7_day')
    );
});

test('follow up rejects past date', function () {
    $ctx = dashboardTestContext();

    $yesterday = Carbon::yesterday()->toDateString();

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.follow-up.update', $ctx['visit']->id), [
        'follow_up_date' => $yesterday,
        'follow_up_purpose' => 'anc_routine',
    ]);

    $response->assertSessionHasErrors(['follow_up_date']);
});

