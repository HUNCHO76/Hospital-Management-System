<?php

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\TriageAssessment;
use App\Services\TriageService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (spec §43): two attempts to start triage for
 * the same encounter must never both succeed.
 *
 * As with the equivalent tests in Steps 18-21, the suite runs against a
 * single in-memory SQLite connection, which cannot host two genuinely
 * simultaneous transactions and has no FOR UPDATE clause at all
 * (lockForUpdate() is a no-op there). What this proves is the invariant
 * TriageService::create() guarantees: even back-to-back calls with no
 * externally-visible gap between them, only the first ever succeeds —
 * because the duplicate check happens *inside* the same transaction as the
 * encounter-row lock and the insert, not before it. Under the production
 * database (MySQL, where lockForUpdate() takes a real row lock), that same
 * ordering is what serializes two genuinely concurrent requests.
 */
test('only one of several back-to-back attempts to start triage for the same encounter succeeds', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create($encounter, $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->count())->toBe(1);
});

test('two different encounters can each start triage without conflicting', function () {
    $ctx = clinicalEncounterContext();
    $encounterA = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $encounterB = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => Patient::factory()->create(['status' => Patient::STATUS_ACTIVE])->id,
    ]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $service->create($encounterA, $request);
    $triageB = $service->create($encounterB, $request);

    expect($triageB->status)->toBe(TriageAssessment::STATUS_PENDING);
    expect(TriageAssessment::query()->count())->toBe(2);
});
