<?php

use App\Models\Consultation;
use App\Services\ConsultationService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (spec §35): two attempts to start a
 * consultation for the same encounter must never both succeed. See
 * TriageConcurrencyTest for the full rationale — the invariant proven here
 * is the same one, guaranteed by ConsultationService::create() locking the
 * encounter row before its duplicate check and insert.
 */
test('only one of several back-to-back attempts to start a consultation for the same encounter succeeds', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create($ctx['encounter'], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(Consultation::query()->where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('two different encounters can each start a consultation without conflicting', function () {
    $ctxA = consultationReadyEncounter();
    $ctxB = consultationReadyEncounter();
    $doctorA = doctorWithPractitioner($ctxA['hospital']);
    $doctorB = doctorWithPractitioner($ctxB['hospital']);

    $service = app(ConsultationService::class);

    $service->create($ctxA['encounter'], requestAs($doctorA['user']));
    $consultationB = $service->create($ctxB['encounter'], requestAs($doctorB['user']));

    expect($consultationB->status)->toBe(Consultation::STATUS_PENDING);
    expect(Consultation::query()->count())->toBe(2);
});
