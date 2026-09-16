<?php

use App\Models\Dispensing;
use App\Models\PrescriptionOrder;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Services\StockService;

/**
 * The full Step 49 E2E scenario (spec §42), driven entirely over HTTP:
 * receive stock -> a doctor prescribes -> a pharmacist verifies -> pharmacy
 * dispenses -> stock is deducted via FEFO -> the stock item's own detail
 * page shows the resulting balance and a traceable ledger entry.
 */
test('receiving, prescribing, verifying, and dispensing end to end leaves the pharmacy stock ledger correct and traceable', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['generic_name' => 'Paracetamol', 'code' => 'PARA-500']);
    $pharmacist = userWithRole('pharmacist');

    // 1. Receive 100 units of Paracetamol.
    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $ctx['hospital']->id,
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 100,
    ])->assertRedirect();

    $item = StockItem::query()->where('hospital_id', $ctx['hospital']->id)->where('medication_id', $medication->id)->sole();
    expect(app(StockService::class)->availableQuantity($item))->toBe(100);

    // 2. Doctor prescribes 30 units.
    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '500', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'duration' => 5, 'duration_unit' => 'days', 'quantity' => 30]],
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    $prescriptionItem = $order->items->sole();

    // 3. Pharmacist verifies.
    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order))->assertRedirect();

    // 4. Pharmacy dispenses 30.
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $prescriptionItem->id, 'dispensed_quantity' => 30]],
    ])->assertRedirect();

    $dispensing = Dispensing::query()->where('prescription_order_id', $order->id)->sole();
    expect($dispensing->status)->toBe(Dispensing::STATUS_COMPLETED);

    // 5. Stock is now 70, with a traceable DISPENSE transaction.
    expect(app(StockService::class)->availableQuantity($item->fresh()))->toBe(70);
    $batch = StockBatch::query()->where('stock_item_id', $item->id)->sole();
    expect($batch->quantity)->toBe(70);

    $this->assertDatabaseHas('stock_transactions', [
        'stock_batch_id' => $batch->id,
        'transaction_type' => 'dispense',
        'quantity' => -30,
        'balance_before' => 100,
        'balance_after' => 70,
        'reference_type' => 'dispensing_item',
    ]);

    // 6. The stock item's own detail page shows the resulting balance and
    // the dispense in its transaction history.
    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.show', $item->id))
        ->assertInertia(fn ($page) => $page
            ->where('item.available_quantity', 70)
            ->where('item.total_quantity', 70));

    foreach (['stock_received', 'stock_dispensed'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

test('FEFO is honored end to end: the earliest-expiring valid batch is consumed first, an already-expired batch is skipped entirely', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $ctx['hospital']->id, 'medication_id' => $medication->id,
        'batch_number' => 'SOON-EXPIRED', 'expiry_date' => now()->addDays(2)->toDateString(), 'quantity' => 20,
    ])->assertRedirect();

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $ctx['hospital']->id, 'medication_id' => $medication->id,
        'batch_number' => 'FAR-FUTURE', 'expiry_date' => now()->addMonths(6)->toDateString(), 'quantity' => 50,
    ])->assertRedirect();

    // Let the first batch actually expire before it is ever dispensed from.
    $this->travel(4)->days();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 10]],
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    $prescriptionItem = $order->items->sole();

    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $prescriptionItem->id, 'dispensed_quantity' => 10]],
    ])->assertRedirect();

    expect(StockBatch::query()->where('batch_number', 'SOON-EXPIRED')->sole()->quantity)->toBe(20);
    expect(StockBatch::query()->where('batch_number', 'FAR-FUTURE')->sole()->quantity)->toBe(40);

    $this->travelBack();
});

test('an attempt to dispense more than what is in stock is rejected end to end, leaving both dispensing and stock untouched', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $ctx['hospital']->id, 'medication_id' => $medication->id,
        'batch_number' => 'LIMITED', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 5,
    ])->assertRedirect();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 20]],
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    $prescriptionItem = $order->items->sole();

    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $prescriptionItem->id, 'dispensed_quantity' => 20]],
    ])->assertSessionHasErrors('quantity');

    $dispensing = Dispensing::query()->where('prescription_order_id', $order->id)->sole();
    expect($dispensing->status)->toBe(Dispensing::STATUS_IN_PROGRESS);
    $this->assertDatabaseCount('dispensing_items', 0);
    $this->assertDatabaseHas('stock_batches', ['batch_number' => 'LIMITED', 'quantity' => 5]);
});
