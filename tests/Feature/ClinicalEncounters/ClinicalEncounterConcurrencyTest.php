<?php

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Services\ClinicalEncounterService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (spec §13): two attempts to open a clinical
 * encounter for the same patient must never both succeed.
 *
 * As with AppointmentConcurrencyTest and ReceptionConcurrencyTest, the test
 * suite runs against a single in-memory SQLite connection, which cannot host
 * two genuinely simultaneous transactions and whose grammar has no FOR
 * UPDATE clause at all (lockForUpdate() is a no-op there). What this proves
 * is the invariant ClinicalEncounterService::create() guarantees: even when
 * a second caller's business logic runs immediately after the first with no
 * externally-visible gap, it still detects the first's committed row and
 * refuses to open a second active encounter — because the active-encounter
 * check happens *inside* the same transaction as the patient-row lock and
 * the insert, not before it. Under the production database (MySQL, where
 * lockForUpdate() takes a real row lock), that same ordering is what
 * serializes two genuinely concurrent requests instead of merely two
 * sequential ones.
 */
test('only one of several back-to-back attempts to open an encounter for the same patient succeeds', function () {
    $user = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $service = app(ClinicalEncounterService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create([
                'patient_id' => $ctx['patient']->id,
                'department_id' => $ctx['department']->id,
                'encounter_type' => 'opd',
            ], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->count())->toBe(1);
});

test('two different patients can each open an encounter without conflicting', function () {
    $user = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $otherPatient = Patient::factory()->create(['status' => Patient::STATUS_ACTIVE]);
    $service = app(ClinicalEncounterService::class);
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    $second = $service->create([
        'patient_id' => $otherPatient->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect($second->status)->toBe(ClinicalEncounter::STATUS_OPEN);
    expect(ClinicalEncounter::query()->count())->toBe(2);
});
