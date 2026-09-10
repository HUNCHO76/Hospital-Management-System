<?php

use App\Models\LaboratorySample;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §45): sample numbers must never
 * collide, and two attempts to register a specimen for the same test must
 * never both succeed. sample_number uniqueness is guaranteed by
 * GeneratesBusinessNumber's lockForUpdate()+last-value+1-inside-a-
 * transaction strategy — never a bare count()+1 — the same mechanism
 * already proven for Patient/ClinicalEncounter/LaboratoryOrder numbers.
 */
test('several specimens registered back-to-back all receive unique sample numbers', function () {
    $service = app(LaboratorySampleService::class);

    $numbers = [];
    for ($i = 0; $i < 5; $i++) {
        $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
        $ctx = laboratoryOrderReadyForSampling([$test]);
        $item = $ctx['order']->items->sole();

        $sample = $service->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'whole_blood'], requestAs($ctx['doctor']['user']));
        $numbers[] = $sample->sample_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
    expect(LaboratorySample::query()->count())->toBe(5);
});

test('only one of several back-to-back attempts to register the same test succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratorySampleService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'serum'], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(LaboratorySample::query()->where('laboratory_order_id', $ctx['order']->id)->count())->toBe(1);
});

test('only one of several back-to-back attempts to collect the same specimen succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratorySampleService::class);

    $sample = $service->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'plasma'], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->collect($sample->fresh(), [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($sample->fresh()->status)->toBe(LaboratorySample::STATUS_COLLECTED);
});

test('only one of several back-to-back attempts to recollect the same rejected specimen succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratorySampleService::class);

    $sample = $service->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'tissue'], $request);
    $sample = $service->collect($sample, [], $request);
    $sample = $service->reject($sample, ['rejection_reason' => 'contaminated'], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->recollect($sample->fresh(), [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});
