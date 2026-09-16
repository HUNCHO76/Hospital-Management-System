<?php

use App\Models\Hospital;
use App\Services\StockService;

/**
 * Ledger reconciliation (Phase 6, Step 49, spec §47): the sum of a batch's
 * own transaction history must always equal its recorded quantity, across
 * every kind of movement — receipts, adjustments in both directions, and
 * expired/damaged write-offs (quarantine/release move no quantity, so they
 * do not affect this sum).
 */
test('a batch\'s ledger reconciles against its recorded quantity across a full mix of movements', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $service->adjust($batch, 'adjustment_in', 20, 'Recount found more', requestAs($actor));
    $service->adjust($batch->fresh(), 'adjustment_out', 15, 'Recount discrepancy', requestAs($actor));
    $service->adjust($batch->fresh(), 'expired', 5, 'Found expired on shelf', requestAs($actor));
    $service->quarantine($batch->fresh(), 'Investigation', requestAs($actor));
    $service->release($batch->fresh(), null, requestAs($actor));

    $result = $service->reconcile($batch->fresh());

    expect($result['reconciled'])->toBeTrue();
    expect($result['recorded_quantity'])->toBe(100);
    expect($result['ledger_balance'])->toBe($result['recorded_quantity']);
});

test('reconciliation flags a mismatch rather than silently correcting it when the batch row is changed outside the ledger', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $service = app(StockService::class);

    // Simulate exactly the kind of out-of-band write this mechanism exists
    // to catch — never something StockService itself would do.
    $batch->update(['quantity' => 999]);

    $result = $service->reconcile($batch->fresh());

    expect($result['reconciled'])->toBeFalse();
    expect($result['recorded_quantity'])->toBe(999);
    expect($result['ledger_balance'])->toBe(100);
});
