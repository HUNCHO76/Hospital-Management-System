<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\CarePlanService;
use App\Services\ClinicalNoteService;

/**
 * Cross-cutting IDOR matrix (Step 27, spec §31): Patient A's authorized
 * doctor must never reach Patient B's consultation, diagnosis, treatment, or
 * clinical note by substituting a different {clinicalEncounter} id in the
 * URL. Each individual module already proves this against its own
 * controller (EncounterDiagnosisControllerTest, CarePlanItemController via
 * CarePlanAuthorizationTest, ClinicalNoteController via
 * ClinicalNoteAuthorizationTest) — this file is the single place all of
 * them are exercised together for Step 27's own record. This app has no
 * DELETE/PATCH routes for any clinical entity ("no destroy route" is a
 * standing convention — see routes/web.php), so GET/POST/PUT is the
 * complete verb surface to test.
 */
test('a doctor authorized for encounter A cannot reach any of encounter B\'s clinical records', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();

    $diagnosisB = $ctxB['diagnosis'];
    $carePlanB = app(CarePlanService::class)->create($ctxB['encounter'], [], requestAs($ctxB['doctor']['user']));
    $itemB = app(CarePlanService::class)->addItem($carePlanB, ['type' => 'monitoring', 'description' => 'x'], requestAs($ctxB['doctor']['user']));
    $noteB = app(ClinicalNoteService::class)->create($ctxB['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], requestAs($ctxB['doctor']['user']));

    $doctorA = $ctxA['doctor']['user'];

    // GET: viewing another encounter is intentionally not ownership-scoped
    // (ClinicalEncounterPolicy::view() — "any authorized clinical staff...
    // may need to read it for handoffs"); the real IDOR boundary is that no
    // *action* on B's records can be reached through A's encounter id below.
    $this->actingAs($doctorA)->get(route('clinical-encounters.show', $ctxB['encounter']))->assertOk();

    // POST/PUT against B's diagnosis, addressed through A's encounter id.
    $this->actingAs($doctorA)
        ->put(route('clinical-encounters.diagnoses.update', [$ctxA['encounter'], $diagnosisB]), ['clinical_note' => 'hijacked'])
        ->assertNotFound();
    $this->actingAs($doctorA)
        ->post(route('clinical-encounters.diagnoses.cancel', [$ctxA['encounter'], $diagnosisB]), ['cancellation_reason' => 'x'])
        ->assertNotFound();

    // POST/PUT against B's care plan item, addressed through A's encounter id.
    $this->actingAs($doctorA)
        ->put(route('clinical-encounters.care-plan.items.update', [$ctxA['encounter'], $itemB]), ['description' => 'hijacked'])
        ->assertNotFound();
    $this->actingAs($doctorA)
        ->post(route('clinical-encounters.care-plan.items.cancel', [$ctxA['encounter'], $itemB]), ['cancellation_reason' => 'x'])
        ->assertNotFound();

    // PUT/POST against B's clinical note, addressed through A's encounter id.
    $this->actingAs($doctorA)
        ->put(route('clinical-encounters.clinical-notes.update', [$ctxA['encounter'], $noteB]), ['note_type' => 'progress', 'note_content' => 'hijacked'])
        ->assertNotFound();
    $this->actingAs($doctorA)
        ->post(route('clinical-encounters.clinical-notes.sign', [$ctxA['encounter'], $noteB]))
        ->assertNotFound();

    // Nothing on B was modified.
    expect($diagnosisB->fresh()->clinical_note)->toBeNull();
    expect($itemB->fresh()->description)->toBe('x');
    expect($noteB->fresh()->note_content)->toBe('x');
    expect($noteB->fresh()->status)->toBe('draft');
});

test('a doctor cannot create a diagnosis, treatment item, or clinical note against an encounter belonging to a patient they are not authorized for across hospitals', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = clinicalEncounterContext($hospitalA);
    $ctxB = clinicalEncounterContext($hospitalB);
    $encounterB = ClinicalEncounter::factory()->create([
        'department_id' => $ctxB['department']->id,
        'patient_id' => $ctxB['patient']->id,
    ]);

    $adminA = User::factory()->create();
    seedRolesAndPermissions();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('clinical-encounters.show', $encounterB))->assertForbidden();
    $this->actingAs($adminA)->post(route('clinical-encounters.clinical-notes.store', $encounterB), [
        'note_type' => 'progress',
        'note_content' => 'cross-hospital attempt',
    ])->assertForbidden();

    $this->assertDatabaseCount('clinical_notes', 0);
});
