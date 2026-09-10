<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every clinical encounter route', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'created_by' => userWithRole('nurse')->id,
    ]);

    $this->get(route('clinical-encounters.index'))->assertRedirect(route('login'));
    $this->get(route('clinical-encounters.create'))->assertRedirect(route('login'));
    $this->get(route('clinical-encounters.show', $encounter))->assertRedirect(route('login'));
});

test('a user without clinical-encounters.view receives a 403', function () {
    $user = userWithRole('accountant');

    $this->actingAs($user)->get(route('clinical-encounters.index'))->assertForbidden();
});

test('a user without clinical-encounters.create receives a 403', function () {
    $user = userWithRole('accountant');
    $ctx = clinicalEncounterContext();

    $this->actingAs($user)->get(route('clinical-encounters.create'))->assertForbidden();

    $this->actingAs($user)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ])->assertForbidden();

    $this->assertDatabaseCount('clinical_encounters', 0);
});

/**
 * Mandatory privacy boundary (spec §27): a receptionist creating/progressing
 * a reception visit must not automatically gain access to clinical encounter
 * details — the two are separate permission domains.
 */
test('a receptionist cannot view or act on clinical encounters despite having reception permissions', function () {
    $user = userWithRole('receptionist');
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'created_by' => userWithRole('nurse')->id,
    ]);

    $this->actingAs($user)->get(route('clinical-encounters.index'))->assertForbidden();
    $this->actingAs($user)->get(route('clinical-encounters.show', $encounter))->assertForbidden();
    $this->actingAs($user)->post(route('clinical-encounters.start', $encounter))->assertForbidden();

    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);
});

test('a user without the required permission cannot perform a status action', function (string $routeName) {
    $user = userWithRole('accountant');
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'created_by' => userWithRole('nurse')->id,
    ]);

    $this->actingAs($user)->post(route($routeName, $encounter))->assertForbidden();
})->with([
    'start' => ['clinical-encounters.start'],
    'complete' => ['clinical-encounters.complete'],
]);

test('a user without clinical-encounters.cancel cannot cancel an encounter', function () {
    $user = userWithRole('accountant');
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'created_by' => userWithRole('nurse')->id,
    ]);

    $this->actingAs($user)->post(route('clinical-encounters.cancel', $encounter), [
        'cancellation_reason' => 'Should not be allowed',
    ])->assertForbidden();

    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);
});

/**
 * Mandatory cross-hospital / IDOR security test: a hospital-admin for
 * Hospital A must never be able to view or act on a clinical encounter
 * opened under Hospital B's department, including by direct URL access with
 * a guessed/incremented id.
 */
test('a hospital-admin cannot access another hospital clinical encounter', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = clinicalEncounterContext($hospitalA);
    $ctxB = clinicalEncounterContext($hospitalB);

    $creator = userWithRole('nurse');

    $encounterA = ClinicalEncounter::factory()->create([
        'department_id' => $ctxA['department']->id,
        'patient_id' => $ctxA['patient']->id,
        'created_by' => $creator->id,
    ]);
    $encounterB = ClinicalEncounter::factory()->create([
        'department_id' => $ctxB['department']->id,
        'patient_id' => $ctxB['patient']->id,
        'created_by' => $creator->id,
    ]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('clinical-encounters.show', $encounterA))->assertOk();

    $this->actingAs($adminUser)->get(route('clinical-encounters.show', $encounterB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('clinical-encounters.start', $encounterB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('clinical-encounters.complete', $encounterB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('clinical-encounters.cancel', $encounterB), [
        'cancellation_reason' => 'Hijack attempt',
    ])->assertForbidden();

    expect($encounterB->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);
});

test('a hospital-admin clinical encounter list only shows their own hospital', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = clinicalEncounterContext($hospitalA);
    $ctxB = clinicalEncounterContext($hospitalB);
    $creator = userWithRole('nurse');

    ClinicalEncounter::factory()->create([
        'department_id' => $ctxA['department']->id,
        'patient_id' => $ctxA['patient']->id,
        'created_by' => $creator->id,
    ]);
    ClinicalEncounter::factory()->create([
        'department_id' => $ctxB['department']->id,
        'patient_id' => $ctxB['patient']->id,
        'created_by' => $creator->id,
    ]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->get(route('clinical-encounters.index'))
        ->assertInertia(fn ($page) => $page->where('encounters.total', 1));
});

test('a super-admin bypasses clinical encounter permission checks and hospital scoping', function () {
    $user = userWithRole('super-admin');
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('clinical-encounters.index'))->assertOk();
    $this->actingAs($user)->get(route('clinical-encounters.create'))->assertOk();
    $this->actingAs($user)->get(route('clinical-encounters.show', $encounter))->assertOk();
});

test('a clinical encounter id that does not exist returns a 404, not an information leak', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('clinical-encounters.show', ['clinicalEncounter' => 999999]))->assertNotFound();
});
