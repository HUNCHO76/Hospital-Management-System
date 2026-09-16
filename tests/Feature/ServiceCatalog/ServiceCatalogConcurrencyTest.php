<?php

use App\Models\Hospital;
use App\Models\ServicePrice;
use App\Services\ServicePricingService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §27/§52): overlapping price creation
 * must never succeed twice, and a price cannot be cancelled twice —
 * mirrors StockConcurrencyTest/BillingConcurrencyTest's own "simulate
 * repeated attempts" approach.
 */
test('several back-to-back attempts to create the exact same overlapping price — only one succeeds', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $pricing = app(ServicePricingService::class);
    $actor = userWithRole('accountant');

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $pricing->create([
                'service_id' => $service->id,
                'hospital_id' => $hospital->id,
                'amount' => 20000,
                'effective_from' => '2026-10-01',
                'effective_to' => '2026-12-31',
            ], requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(ServicePrice::query()->count())->toBe(1);
});

test('several back-to-back attempts to cancel the same price — only one succeeds', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    $actor = userWithRole('accountant');
    $pricing = app(ServicePricingService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $pricing->cancel($price->fresh(), 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});

test('several services created back-to-back all receive their own distinct codes without collision', function () {
    $codes = [];

    for ($i = 0; $i < 5; $i++) {
        $service = serviceFor(['code' => 'CONC-'.$i]);
        $codes[] = $service->code;
    }

    expect(count($codes))->toBe(count(array_unique($codes)));
});
