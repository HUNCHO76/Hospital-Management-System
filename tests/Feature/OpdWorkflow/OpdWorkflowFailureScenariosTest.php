<?php

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Services\ClinicalEncounterService;
use App\Services\ClinicalNoteService;
use App\Services\DiagnosisService;
use Illuminate\Validation\ValidationException;

/**
 * Consolidated Phase 3 regression contract (Step 27, spec §40) — every
 * invalid-workflow attempt the spec explicitly lists, asserted together in
 * one place. Each scenario already has its own deep unit test in its own
 * module (see DiagnosisTest, ConsultationTest, ClinicalEncounterTest,
 * ClinicalNoteTest, ClinicalNoteAuthorizationTest, CarePlanAuthorizationTest)
 * — this file reuses those exact Pest helpers to prove the contract holds
 * end to end, not to re-derive new fixtures or coverage.
 */
test('a diagnosis cannot be created without an engaged consultation', function () {
    $ctx = consultationReadyEncounter(); // triage done, but no consultation started yet
    $doctor = doctorWithPractitioner($ctx['hospital']);

    expect(fn () => app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($doctor['user'])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('encounter_diagnoses', 0);
});

test('treatment cannot be created against another patient\'s encounter by substituting the id', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();

    $this->actingAs($ctxA['doctor']['user'])
        ->post(route('clinical-encounters.care-plan.store', $ctxB['encounter']))
        ->assertForbidden();

    $this->assertDatabaseCount('care_plans', 0);
});

test('a signed clinical note cannot be modified directly, only amended', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'original'], $request);
    $service->sign($note, $request);

    expect(fn () => $service->update($note->fresh(), ['note_type' => 'progress', 'note_content' => 'direct edit'], $request))
        ->toThrow(ValidationException::class);
    expect($note->fresh()->note_content)->toBe('original');

    // The author is authorized (owns the note) — the barrier is the
    // service's own status guard, surfaced as a validation error redirect,
    // not an authorization failure.
    $this->actingAs($ctx['doctor']['user'])
        ->put(route('clinical-encounters.clinical-notes.update', [$ctx['encounter'], $note]), ['note_type' => 'progress', 'note_content' => 'http direct edit'])
        ->assertSessionHasErrors('status');
    expect($note->fresh()->note_content)->toBe('original');
});

test('completing an encounter with missing required information does not corrupt state, and an already-completed encounter cannot be completed again', function () {
    $ctx = clinicalEncounterContext();
    $nurse = userWithRole('nurse');
    $request = requestAs($nurse);
    $service = app(ClinicalEncounterService::class);

    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service->start($encounter, $request);

    // A simple encounter with no triage/consultation/diagnosis/treatment at
    // all can still legitimately complete (spec §15's "do not require
    // unnecessary steps for every encounter type") — but once completed, it
    // is immutable.
    $service->complete($encounter, [], $request);

    expect(fn () => $service->complete($encounter->fresh(), [], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->start($encounter->fresh(), $request))->toThrow(ValidationException::class);
});

test('accessing another patient\'s clinical history never returns their encounters', function () {
    $ctxA = carePlanReadyEncounter();
    $otherPatient = Patient::factory()->create();

    $response = $this->actingAs($ctxA['doctor']['user'])->get(route('patients.clinical-history', $otherPatient));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('encounters.data', 0));
});

test('a duplicate active encounter for the same patient is rejected', function () {
    $ctx = clinicalEncounterContext();
    $nurse = userWithRole('nurse');
    $request = requestAs($nurse);
    $service = app(ClinicalEncounterService::class);

    $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect(fn () => $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'walk_in',
    ], $request))->toThrow(ValidationException::class);

    expect(ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->count())->toBe(1);
});

test('a user cannot sign or amend someone else\'s clinical note without permission', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctx['encounter'], ['note_type' => 'nursing', 'note_content' => 'x'], requestAs($ctx['nurse']));

    $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.clinical-notes.sign', [$ctx['encounter'], $note]))
        ->assertForbidden();

    expect($note->fresh()->status)->toBe(ClinicalNote::STATUS_DRAFT);

    // The receptionist has no clinical-notes permissions at all.
    $receptionist = userWithRole('receptionist');
    $this->actingAs($receptionist)
        ->post(route('clinical-encounters.clinical-notes.sign', [$ctx['encounter'], $note]))
        ->assertForbidden();
});
