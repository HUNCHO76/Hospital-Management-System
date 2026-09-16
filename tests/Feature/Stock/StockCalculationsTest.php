<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Services\StockService;

/**
 * Pure calculation coverage for StockService's read-only helpers (spec §41):
 * available/total quantity, low-stock detection, and expiring-soon
 * detection against the configurable warning window.
 */
test('availableQuantity sums only active, unexpired batches, excluding quarantined and expired ones', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $active = $service->receive($hospital->id, ['medication_id' => $medication->id, 'batch_number' => 'A', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 40], requestAs($actor));
    $quarantined = $service->receive($hospital->id, ['medication_id' => $medication->id, 'batch_number' => 'B', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 15], requestAs($actor));
    $service->quarantine($quarantined, 'reason', requestAs($actor));

    $item = $active->stockItem;

    expect($service->availableQuantity($item))->toBe(40);
    expect($service->totalQuantity($item))->toBe(55);
});

test('isLowStock is true exactly when available quantity is at or below the reorder level', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $batch = $service->receive($hospital->id, ['medication_id' => $medication->id, 'batch_number' => 'A', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10, 'reorder_level' => 10], requestAs($actor));
    $item = $batch->stockItem;

    expect($service->isLowStock($item))->toBeTrue();

    $service->adjust($batch, 'adjustment_in', 1, 'reason', requestAs($actor));
    expect($service->isLowStock($item->fresh()))->toBeFalse();
});

test('isExpiringSoon respects the configurable warning window, not a hard-coded number', function () {
    config(['pharmacy.stock_expiry_warning_days' => 30]);

    $withinWindow = StockBatch::factory()->make(['expiry_date' => now()->addDays(29)]);
    $outsideWindow = StockBatch::factory()->make(['expiry_date' => now()->addDays(31)]);
    $alreadyExpired = StockBatch::factory()->make(['expiry_date' => now()->subDay()]);

    $service = app(StockService::class);

    expect($service->isExpiringSoon($withinWindow))->toBeTrue();
    expect($service->isExpiringSoon($outsideWindow))->toBeFalse();
    // Already-expired stock is reported as expired, not "expiring soon" —
    // the two states are mutually exclusive.
    expect($service->isExpiringSoon($alreadyExpired))->toBeFalse();
});

test('a batch is dispensable only when active, unexpired, and quantity is positive', function () {
    $active = StockBatch::factory()->make(['status' => StockBatch::STATUS_ACTIVE, 'expiry_date' => now()->addYear(), 'quantity' => 5]);
    $expired = StockBatch::factory()->make(['status' => StockBatch::STATUS_ACTIVE, 'expiry_date' => now()->subDay(), 'quantity' => 5]);
    $quarantined = StockBatch::factory()->make(['status' => StockBatch::STATUS_QUARANTINED, 'expiry_date' => now()->addYear(), 'quantity' => 5]);
    $empty = StockBatch::factory()->make(['status' => StockBatch::STATUS_ACTIVE, 'expiry_date' => now()->addYear(), 'quantity' => 0]);

    expect($active->isDispensable())->toBeTrue();
    expect($expired->isDispensable())->toBeFalse();
    expect($quarantined->isDispensable())->toBeFalse();
    expect($empty->isDispensable())->toBeFalse();
});
