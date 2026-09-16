<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Corrective stock adjustments — count corrections and expired/damaged
 * write-offs (Phase 6, Step 49, spec §19-20). Every adjustment is ledgered
 * and reason-required; the resulting balance can never go negative.
 */
test('a positive count correction increases the batch quantity and is ledgered', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_IN, 20, 'Found additional stock during count', requestAs($actor));

    expect($updated->quantity)->toBe(120);
    $this->assertDatabaseHas('stock_transactions', [
        'stock_batch_id' => $batch->id,
        'transaction_type' => 'adjustment_in',
        'quantity' => 20,
        'balance_before' => 100,
        'balance_after' => 120,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_adjusted']);
});

test('a negative count correction decreases the batch quantity and is ledgered', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_OUT, 30, 'Count discrepancy', requestAs($actor));

    expect($updated->quantity)->toBe(70);
    expect($updated->status)->toBe(StockBatch::STATUS_ACTIVE);
});

test('an adjustment can never take a batch below zero', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $actor = userWithRole('pharmacist');

    expect(fn () => app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_OUT, 11, 'reason', requestAs($actor)))
        ->toThrow(ValidationException::class);

    expect($batch->fresh()->quantity)->toBe(10);
});

test('a partial expired write-off reduces quantity but leaves the batch active, matching the spec example exactly', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_EXPIRED, 10, 'Found expired on shelf', requestAs($actor));

    expect($updated->quantity)->toBe(90);
    expect($updated->status)->toBe(StockBatch::STATUS_ACTIVE);
    $this->assertDatabaseHas('stock_transactions', ['transaction_type' => 'expired', 'quantity' => -10, 'balance_after' => 90]);
});

test('writing off a batch\'s full remaining quantity as expired marks it expired', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 30);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_EXPIRED, 30, 'Fully expired', requestAs($actor));

    expect($updated->quantity)->toBe(0);
    expect($updated->status)->toBe(StockBatch::STATUS_EXPIRED);
});

test('writing off a batch\'s full remaining quantity as damaged marks it damaged', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 30);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_DAMAGED, 30, 'Dropped and broken', requestAs($actor));

    expect($updated->quantity)->toBe(0);
    expect($updated->status)->toBe(StockBatch::STATUS_DAMAGED);
});

test('a plain count correction that empties a batch marks it depleted, not expired or damaged', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 30);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_OUT, 30, 'Count correction', requestAs($actor));

    expect($updated->quantity)->toBe(0);
    expect($updated->status)->toBe(StockBatch::STATUS_DEPLETED);
});

test('a depleted batch can be topped back up by a positive adjustment, returning it to active', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $depleted = $service->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_OUT, 10, 'Count correction', requestAs($actor));
    expect($depleted->status)->toBe(StockBatch::STATUS_DEPLETED);

    $revived = $service->adjust($depleted, StockTransaction::TYPE_ADJUSTMENT_IN, 5, 'Found more on recount', requestAs($actor));
    expect($revived->status)->toBe(StockBatch::STATUS_ACTIVE);
    expect($revived->quantity)->toBe(5);
});

test('a batch already written off as expired or damaged can no longer be adjusted', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $written = $service->adjust($batch, StockTransaction::TYPE_DAMAGED, 10, 'Broken', requestAs($actor));

    expect(fn () => $service->adjust($written, StockTransaction::TYPE_ADJUSTMENT_IN, 5, 'reason', requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('adjustment quantity must be a positive magnitude', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $actor = userWithRole('pharmacist');

    expect(fn () => app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_IN, 0, 'reason', requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('a pharmacist can submit a stock adjustment over HTTP with a required reason', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => 10,
    ])->assertSessionHasErrors('reason');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => 10,
        'reason' => 'Damaged stock',
    ])->assertRedirect();

    expect($batch->fresh()->quantity)->toBe(90);
});
