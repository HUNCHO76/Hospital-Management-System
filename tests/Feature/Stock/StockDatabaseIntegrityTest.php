<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Database integrity for the stock module (Phase 6, Step 49, spec §39),
 * mirroring MigrationIntegrityTest's own style.
 */
test('the stock tables exist with their expected columns', function () {
    expect(Schema::hasTable('stock_items'))->toBeTrue();
    expect(Schema::hasTable('stock_batches'))->toBeTrue();
    expect(Schema::hasTable('stock_transactions'))->toBeTrue();

    expect(Schema::hasColumns('stock_items', ['hospital_id', 'medication_id', 'reorder_level', 'maximum_level', 'status']))->toBeTrue();
    expect(Schema::hasColumns('stock_batches', ['stock_item_id', 'batch_number', 'expiry_date', 'quantity', 'unit_cost', 'status']))->toBeTrue();
    expect(Schema::hasColumns('stock_transactions', ['stock_item_id', 'stock_batch_id', 'transaction_type', 'quantity', 'balance_before', 'balance_after']))->toBeTrue();
});

test('the append-only stock transaction ledger has no updated_at column', function () {
    expect(Schema::hasColumn('stock_transactions', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('stock_transactions', 'updated_at'))->toBeFalse();
});

test('a hospital may only have one stock item per medication', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();

    receiveStock($medication, $hospital, 10);

    expect(fn () => StockItem::factory()->create(['hospital_id' => $hospital->id, 'medication_id' => $medication->id]))
        ->toThrow(QueryException::class);
});

test('a stock item may only have one batch per batch number', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);

    expect(fn () => StockBatch::factory()->create(['stock_item_id' => $batch->stock_item_id, 'batch_number' => $batch->batch_number]))
        ->toThrow(QueryException::class);
});

test('every generated transaction number is globally unique', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $number = $batch->transactions->sole()->transaction_number;

    expect(fn () => StockTransaction::factory()->create(['transaction_number' => $number]))
        ->toThrow(QueryException::class);
});
