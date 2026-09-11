<?php

use App\Models\RadiologyOrder;
use App\Services\RadiologyOrderService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (Part 37): order numbers must never collide,
 * and only one of several attempts to cancel the same order may succeed.
 * order_number uniqueness is guaranteed by GeneratesBusinessNumber's
 * lockForUpdate()+last-value+1-inside-a-transaction strategy — never a bare
 * count()+1 — the same mechanism already proven for Laboratory/Patient/
 * ClinicalEncounter numbers.
 */
test('several radiology orders created back-to-back all receive unique order numbers', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $service = app(RadiologyOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $orderNumbers = [];
    for ($i = 0; $i < 5; $i++) {
        $order = $service->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);
        $orderNumbers[] = $order->order_number;
    }

    expect(count($orderNumbers))->toBe(count(array_unique($orderNumbers)));
    expect(RadiologyOrder::query()->count())->toBe(5);
});

test('only one of several back-to-back attempts to cancel the same radiology order succeeds', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            app(RadiologyOrderService::class)->cancel($order->fresh(), 'reason', $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_CANCELLED);
});
