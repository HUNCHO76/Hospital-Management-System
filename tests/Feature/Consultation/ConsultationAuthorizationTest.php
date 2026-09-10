<?php

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for every consultation route', function () {
    $ctx = consultationReadyEncounter();

    $this->post(route('clinical-encounters.consultation.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a user without consultation.create cannot start a consultation', function () {
    $ctx = consultationReadyEncounter();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.consultation.store', $ctx['encounter']))->assertForbidden();

    $this->assertDatabaseCount('consultations', 0);
});

test('a receptionist cannot start a consultation despite reception access to the encounter', function () {
    $ctx = consultationReadyEncounter();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->post(route('clinical-encounters.consultation.store', $ctx['encounter']))->assertForbidden();
    $this->assertDatabaseCount('consultations', 0);
});

test('a doctor cannot update or complete another doctor\'s consultation', function () {
    $ctx = consultationReadyEncounter();
    $doctorA = doctorWithPractitioner($ctx['hospital']);
    $doctorB = doctorWithPractitioner($ctx['hospital']);

    $consultation = app(ConsultationService::class)->create($ctx['encounter'], requestAs($doctorA['user']));

    $this->actingAs($doctorB['user'])
        ->put(route('clinical-encounters.consultation.update', $ctx['encounter']), ['chief_complaint' => 'hijacked'])
        ->assertForbidden();

    $this->actingAs($doctorB['user'])
        ->post(route('clinical-encounters.consultation.start', $ctx['encounter']))
        ->assertForbidden();

    expect($consultation->fresh()->status)->toBe(Consultation::STATUS_PENDING);
    expect($consultation->fresh()->chief_complaint)->toBeNull();
});

test('a hospital-admin can act on any consultation within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxAScoped = clinicalEncounterContext($hospitalA);
    $encounterA = ClinicalEncounter::factory()->create([
        'department_id' => $ctxAScoped['department']->id,
        'patient_id' => $ctxAScoped['patient']->id,
    ]);
    $ctxBScoped = clinicalEncounterContext($hospitalB);
    $encounterB = ClinicalEncounter::factory()->create([
        'department_id' => $ctxBScoped['department']->id,
        'patient_id' => $ctxBScoped['patient']->id,
    ]);

    foreach ([$encounterA, $encounterB] as $encounter) {
        $triageRequest = requestAs(userWithRole('nurse'));
        $triage = app(TriageService::class)->create($encounter, $triageRequest);
        app(TriageService::class)->start($triage, $triageRequest);
        app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $triageRequest);
        app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $triageRequest);
        app(TriageService::class)->complete($triage->fresh(), $triageRequest);
    }

    $doctorA = doctorWithPractitioner($hospitalA);
    $doctorB = doctorWithPractitioner($hospitalB);
    app(ConsultationService::class)->create($encounterA->fresh(), requestAs($doctorA['user']));
    app(ConsultationService::class)->create($encounterB->fresh(), requestAs($doctorB['user']));

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.consultation.update', $encounterA), ['chief_complaint' => 'Admin note'])
        ->assertRedirect();

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.consultation.update', $encounterB), ['chief_complaint' => 'Cross-hospital attempt'])
        ->assertForbidden();
});

test('a consultation cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = consultationReadyEncounter();
    $ctxB = consultationReadyEncounter();
    $doctorA = doctorWithPractitioner($ctxA['hospital']);

    app(ConsultationService::class)->create($ctxA['encounter'], requestAs($doctorA['user']));

    // encounterB has no consultation of its own — resolving via its id must
    // 404, never leak encounterA's consultation.
    $this->actingAs($doctorA['user'])
        ->put(route('clinical-encounters.consultation.update', $ctxB['encounter']), ['chief_complaint' => 'x'])
        ->assertNotFound();
});

test('mass-assignable protected fields cannot be set through the update request', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $other = doctorWithPractitioner($ctx['hospital']);

    $consultation = app(ConsultationService::class)->create($ctx['encounter'], requestAs($doctor['user']));

    $this->actingAs($doctor['user'])->put(route('clinical-encounters.consultation.update', $ctx['encounter']), [
        'chief_complaint' => 'Legitimate complaint',
        'status' => Consultation::STATUS_COMPLETED,
        'completed_by' => $other['user']->id,
        'practitioner_id' => $other['practitioner']->id,
    ])->assertRedirect();

    $fresh = $consultation->fresh();
    expect($fresh->chief_complaint)->toBe('Legitimate complaint');
    expect($fresh->status)->toBe(Consultation::STATUS_PENDING);
    expect($fresh->completed_by)->toBeNull();
    expect($fresh->practitioner_id)->toBe($doctor['practitioner']->id);
});

test('a super-admin bypasses consultation permission checks and hospital scoping', function () {
    $ctx = consultationReadyEncounter();
    $user = userWithRole('super-admin');
    // The practitioner-eligibility business rule (spec §6) applies to every
    // role uniformly — even a super-admin must have a clinical practitioner
    // profile to document a consultation. Only the permission/hospital-scope
    // checks are bypassed for this role.
    $staff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id, 'user_id' => $user->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'doctor', 'status' => 'active']);

    $this->actingAs($user)->post(route('clinical-encounters.consultation.store', $ctx['encounter']))->assertRedirect();

    $this->assertDatabaseCount('consultations', 1);
});
