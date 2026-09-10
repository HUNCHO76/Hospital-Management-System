<?php

use App\Models\CarePlan;
use App\Services\CarePlanService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (spec §35): two attempts to start a care plan
 * for the same encounter must never both succeed. See
 * DiagnosisConcurrencyTest/ConsultationConcurrencyTest for the full
 * rationale — the invariant proven here is the same one, guaranteed by
 * CarePlanService::create() locking the encounter row before its duplicate
 * check and insert.
 */
test('only one of several back-to-back attempts to start a care plan for the same encounter succeeds', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create($ctx['encounter'], [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(CarePlan::query()->where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('two different encounters can each start a care plan without conflicting', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();
    $service = app(CarePlanService::class);

    $service->create($ctxA['encounter'], [], requestAs($ctxA['doctor']['user']));
    $carePlanB = $service->create($ctxB['encounter'], [], requestAs($ctxB['doctor']['user']));

    expect($carePlanB->status)->toBe(CarePlan::STATUS_DRAFT);
    expect(CarePlan::query()->count())->toBe(2);
});
