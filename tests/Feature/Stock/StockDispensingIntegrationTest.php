<?php

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Services\DispensingService;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * The dispensing → stock integration (Phase 6, Step 49, spec §14/§28):
 * FEFO allocation, multi-batch splitting, expired-stock protection, and
 * atomicity between DispensingItem creation and stock deduction.
 */
test('completing dispensing deducts stock from the earliest-expiring batch first (FEFO)', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $medication = $item->medication;

    // The shared helper already gave this medication one far-future batch
    // (100000 units); layer a smaller, sooner-expiring batch on top so FEFO
    // has something to prefer.
    $soonBatch = receiveStock($medication, $ctx['hospital'], 10, now()->addMonths(6)->toDateString());

    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($pharmacist));

    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 15]],
    ], requestAs($pharmacist));

    expect($soonBatch->fresh()->quantity)->toBe(0);
    expect($soonBatch->fresh()->status)->toBe(StockBatch::STATUS_DEPLETED);

    $farBatch = StockBatch::query()->where('stock_item_id', $soonBatch->stock_item_id)->where('id', '!=', $soonBatch->id)->sole();
    expect($farBatch->quantity)->toBe(100000 - 5);
});

test('a single dispensing item fulfilled from multiple batches records each batch movement separately and traceably', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $medication = $item->medication;
    $actor = userWithRole('pharmacist');

    $autoBatch = StockBatch::query()->whereHas('stockItem', fn ($query) => $query->where('medication_id', $medication->id))->sole();
    app(StockService::class)->adjust($autoBatch, StockTransaction::TYPE_ADJUSTMENT_OUT, $autoBatch->quantity, 'Reset for test', requestAs($actor));

    $batchA = receiveStock($medication, $ctx['hospital'], 10, now()->addMonths(3)->toDateString());
    $batchB = receiveStock($medication, $ctx['hospital'], 20, now()->addMonths(6)->toDateString());

    $service = app(DispensingService::class);
    $dispensing = $service->start($order->fresh(), requestAs($actor));

    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 15]],
    ], requestAs($actor));

    expect($batchA->fresh()->quantity)->toBe(0);
    expect($batchA->fresh()->status)->toBe(StockBatch::STATUS_DEPLETED);
    expect($batchB->fresh()->quantity)->toBe(15);

    $dispensingItem = DispensingItem::query()->where('dispensing_id', $dispensing->id)->sole();
    $transactions = StockTransaction::query()->where('reference_type', 'dispensing_item')->where('reference_id', $dispensingItem->id)->get();

    expect($transactions)->toHaveCount(2);
    expect((int) $transactions->sum(fn (StockTransaction $t) => -$t->quantity))->toBe(15);
});

test('an already-expired batch is never used to fulfill dispensing, even when it would otherwise sort first', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $medication = $item->medication;
    $actor = userWithRole('pharmacist');

    $stockItem = StockItem::query()->where('medication_id', $medication->id)->where('hospital_id', $ctx['hospital']->id)->sole();

    $expiredBatch = StockBatch::factory()->create([
        'stock_item_id' => $stockItem->id,
        'expiry_date' => now()->subDay()->toDateString(),
        'quantity' => 50,
        'status' => StockBatch::STATUS_ACTIVE,
        'created_by' => $actor->id,
    ]);

    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($actor));
    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 15]],
    ], requestAs($actor));

    expect($expiredBatch->fresh()->quantity)->toBe(50);
});

test('a quarantined batch is never used to fulfill dispensing', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $medication = $item->medication;
    $actor = userWithRole('pharmacist');

    $autoBatch = StockBatch::query()->whereHas('stockItem', fn ($query) => $query->where('medication_id', $medication->id))->sole();
    app(StockService::class)->quarantine($autoBatch, 'Investigation', requestAs($actor));

    $service = app(DispensingService::class);
    $dispensing = $service->start($order->fresh(), requestAs($actor));

    expect(fn () => $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 15]],
    ], requestAs($actor)))->toThrow(ValidationException::class);

    expect($autoBatch->fresh()->quantity)->toBe(100000);
});

test('dispensing is rejected atomically when stock is insufficient — no dispensing item or deduction is recorded', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $medication = $item->medication;
    $actor = userWithRole('pharmacist');

    $autoBatch = StockBatch::query()->whereHas('stockItem', fn ($query) => $query->where('medication_id', $medication->id))->sole();
    app(StockService::class)->adjust($autoBatch, StockTransaction::TYPE_ADJUSTMENT_OUT, $autoBatch->quantity, 'Reset for test', requestAs($actor));

    $service = app(DispensingService::class);
    $dispensing = $service->start($order->fresh(), requestAs($actor));

    expect(fn () => $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 15]],
    ], requestAs($actor)))->toThrow(ValidationException::class);

    expect($dispensing->fresh()->status)->toBe(Dispensing::STATUS_IN_PROGRESS);
    $this->assertDatabaseCount('dispensing_items', 0);
    $this->assertDatabaseHas('stock_batches', ['id' => $autoBatch->id, 'quantity' => 0]);
});

test('allocateFefo rejects outright when no stock item exists at all for the medication', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $actor = userWithRole('pharmacist');

    expect(fn () => app(StockService::class)->allocateFefo($hospital->id, $medication->id, 5, 'dispensing_item', 1, requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('marking a medication unavailable during dispensing never touches stock', function () {
    $ctx = diagnosisReadyEncounter();
    $order = verifiedPrescriptionOrderFor($ctx);
    $item = $order->items->sole();
    $actor = userWithRole('pharmacist');

    $before = StockTransaction::query()->count();

    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($actor));
    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 0, 'status' => 'unavailable']],
    ], requestAs($actor));

    expect(StockTransaction::query()->count())->toBe($before);
});
