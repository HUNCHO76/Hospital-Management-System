<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §27/§41): a batch's quantity must never
 * go negative regardless of how many overlapping requests attempt to
 * deduct from it, and every generated business number must stay unique.
 * Each row-locked write happens inside its own committed transaction here
 * (mirrors DispensingConcurrencyTest's own "simulate repeated attempts"
 * approach — PHP/SQLite/MySQL test runs are single-threaded, so true
 * parallel races are simulated by repeated sequential attempts against the
 * same freshly-reloaded row, which still exercises the same lock-and-check
 * code path a real race would hit).
 */
test('several back-to-back adjustment-out attempts against the same batch can never drive its quantity negative', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->adjust($batch->fresh(), StockTransaction::TYPE_ADJUSTMENT_OUT, 3, 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(3);
    expect($rejections)->toBe(2);
    expect($batch->fresh()->quantity)->toBe(1);
    expect($batch->fresh()->quantity)->toBeGreaterThanOrEqual(0);
});

test('several back-to-back FEFO allocations against a limited batch can never oversell it', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    receiveStock($medication, $hospital, 10);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->allocateFefo($hospital->id, $medication->id, 3, 'dispensing_item', $i + 1, requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(3);
    expect($rejections)->toBe(2);

    $batch = StockBatch::query()->whereHas('stockItem', fn ($query) => $query->where('medication_id', $medication->id))->sole();
    expect($batch->quantity)->toBe(1);
    expect($batch->quantity)->toBeGreaterThanOrEqual(0);
});

test('several stock transactions created back-to-back all receive unique transaction numbers', function () {
    $hospital = Hospital::factory()->create();
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $batch = $service->receive($hospital->id, [
            'medication_id' => activeMedication()->id,
            'batch_number' => 'BATCH-'.$i,
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => 10,
        ], requestAs($actor));

        $numbers[] = StockTransaction::query()->where('stock_batch_id', $batch->id)->sole()->transaction_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
});

test('only one of several back-to-back attempts to quarantine the same batch succeeds', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->quarantine($batch->fresh(), 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});
