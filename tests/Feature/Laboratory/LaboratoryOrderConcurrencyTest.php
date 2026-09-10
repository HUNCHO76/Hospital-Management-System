<?php

use App\Models\LaboratoryOrder;
use App\Services\LaboratoryOrderService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §43): order numbers must never collide,
 * and only one of several attempts to cancel the same order may succeed.
 * order_number uniqueness is guaranteed by GeneratesBusinessNumber's
 * lockForUpdate()+last-value+1-inside-a-transaction strategy — never a bare
 * count()+1 — the same mechanism already proven for Patient/ClinicalEncounter
 * numbers.
 */
test('several laboratory orders created back-to-back all receive unique order numbers', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $service = app(LaboratoryOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $orderNumbers = [];
    for ($i = 0; $i < 5; $i++) {
        $order = $service->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
        $orderNumbers[] = $order->order_number;
    }

    expect(count($orderNumbers))->toBe(count(array_unique($orderNumbers)));
    expect(LaboratoryOrder::query()->count())->toBe(5);
});

test('only one of several back-to-back attempts to cancel the same laboratory order succeeds', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            app(LaboratoryOrderService::class)->cancel($order->fresh(), 'reason', $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_CANCELLED);
});
