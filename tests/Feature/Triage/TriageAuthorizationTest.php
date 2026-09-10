<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\TriageAssessment;
use App\Models\User;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for every triage/vital-sign route', function () {
    $triage = openTriageAssessment();

    $this->post(route('clinical-encounters.triage.start', $triage->clinical_encounter_id))->assertRedirect(route('login'));
    $this->post(route('clinical-encounters.vital-signs.store', $triage->clinical_encounter_id))->assertRedirect(route('login'));
});

test('a user without triage.create cannot start triage', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $user = userWithRole('accountant');

    $this->actingAs($user)->post(route('clinical-encounters.triage.store', $encounter))->assertForbidden();

    $this->assertDatabaseCount('triage_assessments', 0);
});

/**
 * Mandatory role-separation rule (spec §26): a receptionist must not be
 * able to record or view clinical vitals/triage despite holding reception
 * permissions on the very same encounter.
 */
test('a receptionist cannot start triage or record vitals despite reception access to the encounter', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->post(route('clinical-encounters.triage.store', $encounter))->assertForbidden();
    $this->assertDatabaseCount('triage_assessments', 0);

    $triage = app(TriageService::class)->create($encounter, requestAs(userWithRole('nurse')));

    $this->actingAs($receptionist)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertForbidden();
    $this->assertDatabaseCount('vital_signs', 0);

    // Reception also cannot even see the triage screen — clinical-encounters.view
    // is required first, which receptionist deliberately does not hold.
    $this->actingAs($receptionist)->get(route('clinical-encounters.show', $encounter))->assertForbidden();

    expect($triage->fresh()->status)->toBe(TriageAssessment::STATUS_PENDING);
});

test('a doctor can view triage but cannot record vitals or start triage', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('clinical-encounters.show', $encounter))->assertOk();
    $this->actingAs($doctor)->post(route('clinical-encounters.triage.store', $encounter))->assertForbidden();

    $triage = app(TriageService::class)->create($encounter, requestAs(userWithRole('nurse')));

    $this->actingAs($doctor)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertForbidden();

    $this->assertDatabaseCount('triage_assessments', 1);
    $this->assertDatabaseCount('vital_signs', 0);
});

/**
 * Mandatory cross-hospital / IDOR security test.
 */
test('a hospital-admin cannot access another hospital triage assessment', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = clinicalEncounterContext($hospitalA);
    $ctxB = clinicalEncounterContext($hospitalB);

    $encounterA = ClinicalEncounter::factory()->create(['department_id' => $ctxA['department']->id, 'patient_id' => $ctxA['patient']->id]);
    $encounterB = ClinicalEncounter::factory()->create(['department_id' => $ctxB['department']->id, 'patient_id' => $ctxB['patient']->id]);

    $nurse = userWithRole('nurse');
    app(TriageService::class)->create($encounterA, requestAs($nurse));
    app(TriageService::class)->create($encounterB, requestAs($nurse));

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('clinical-encounters.show', $encounterA))->assertOk();
    $this->actingAs($adminUser)->get(route('clinical-encounters.show', $encounterB))->assertForbidden();

    $this->actingAs($adminUser)->post(route('clinical-encounters.triage.start', $encounterB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('clinical-encounters.vital-signs.store', $encounterB), ['pulse_bpm' => 80])->assertForbidden();

    $this->assertDatabaseCount('vital_signs', 0);
});

/**
 * IDOR: a vital sign fetched/corrected through one encounter's URL segment
 * must actually belong to that encounter.
 */
test('a vital sign cannot be corrected through a mismatched encounter id in the URL', function () {
    $ctx = clinicalEncounterContext();
    $encounterA = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $encounterB = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => Patient::factory()->create(['status' => Patient::STATUS_ACTIVE])->id,
    ]);

    $nurse = userWithRole('nurse');
    $triageA = app(TriageService::class)->create($encounterA, requestAs($nurse));
    $vitalSign = app(VitalSignsService::class)->record($triageA, ['pulse_bpm' => 80], requestAs($nurse));

    $this->actingAs($nurse)->post(
        route('clinical-encounters.vital-signs.correct', [$encounterB, $vitalSign]),
        ['pulse_bpm' => 90, 'correction_reason' => 'hijack attempt'],
    )->assertNotFound();

    expect($vitalSign->fresh()->pulse_bpm)->toBe(80);
});

test('a super-admin bypasses triage permission checks and hospital scoping', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $this->actingAs($user)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertRedirect();

    $this->assertDatabaseCount('triage_assessments', 1);
    $this->assertDatabaseCount('vital_signs', 1);
});
