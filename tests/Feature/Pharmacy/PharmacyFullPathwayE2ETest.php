<?php

use App\Models\DispensingItem;
use App\Models\StockTransaction;
use App\Services\DispensingService;
use App\Services\PharmacyReportService;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use App\Services\StockService;

/**
 * Phase 6, Step 51, spec §35 — the mandatory realistic end-to-end scenario,
 * reproduced with its exact numbers: Paracetamol 500mg x100 prescribed,
 * Batch A (50 units, expiring earlier) + Batch B (100 units, expiring
 * later), dispensed as 80 then 20. Verifies every downstream surface
 * (dispensing records, stock quantities, stock transactions, audit logs,
 * reports, patient medication history) agrees with the same transaction —
 * never a second, independently-computed view that could silently drift.
 */
test('the full patient journey (prescribe 100, dispense 80 then 20 across two FEFO batches) is consistent everywhere', function () {
    $ctx = diagnosisReadyEncounter();
    $hospital = $ctx['hospital'];
    $medication = activeMedication(['generic_name' => 'Paracetamol', 'strength' => '500mg']);
    $pharmacist = userWithRole('pharmacist');

    // Batch A expires sooner, Batch B later — FEFO must exhaust A first.
    $batchA = app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'E2E-A', 'expiry_date' => now()->addMonths(3)->toDateString(), 'quantity' => 50,
    ], requestAs($pharmacist));

    $batchB = app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'E2E-B', 'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => 100,
    ], requestAs($pharmacist));

    // Doctor prescribes Paracetamol 500mg x100. Built directly through
    // PrescriptionOrderService (not the activePrescriptionOrderFor() helper,
    // whose own unconditional auto-stock-receiving side effect would add an
    // unwanted third batch/receipt and break this scenario's exact numbers).
    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], ['items' => [[
        'medication_id' => $medication->id, 'dose' => '500', 'dose_unit' => 'mg', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 100,
    ]]], requestAs($ctx['doctor']['user']));
    $item = $order->items->sole();

    // Pharmacist verifies.
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));

    // First dispensing: 80 units.
    $dispensing1 = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing1, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 80]],
    ], requestAs($pharmacist));

    // Batch A (50) exhausted first, then 30 from Batch B, leaving Batch B at 70.
    expect($batchA->fresh()->quantity)->toBe(0);
    expect($batchB->fresh()->quantity)->toBe(70);
    expect(app(DispensingService::class)->remainingQuantity($item->fresh()))->toBe(20);
    expect(app(DispensingService::class)->fulfillmentStatus($order->fresh()))->toBe('partially_dispensed');

    // Second dispensing: the remaining 20 units, all from Batch B.
    $dispensing2 = app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing2, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 20]],
    ], requestAs($pharmacist));

    expect($batchA->fresh()->quantity)->toBe(0);
    expect($batchB->fresh()->quantity)->toBe(50);
    expect(app(DispensingService::class)->remainingQuantity($item->fresh()))->toBe(0);
    expect(app(DispensingService::class)->fulfillmentStatus($order->fresh()))->toBe('dispensed');

    // Dispensing records: two events, both completed, totalling exactly 100.
    $totalDispensed = (int) DispensingItem::query()->where('prescription_order_item_id', $item->id)->sum('dispensed_quantity');
    expect($totalDispensed)->toBe(100);

    // Stock transactions: 2 receipts + at least 3 dispense movements (A once, B twice), all balances consistent.
    $transactions = StockTransaction::query()
        ->whereHas('stockItem', fn ($query) => $query->where('hospital_id', $hospital->id)->where('medication_id', $medication->id))
        ->orderBy('id')
        ->get();

    foreach ($transactions as $transaction) {
        expect($transaction->balance_after)->toBe($transaction->balance_before + $transaction->quantity);
    }

    expect($transactions->where('transaction_type', StockTransaction::TYPE_RECEIPT)->count())->toBe(2);
    expect($transactions->where('transaction_type', StockTransaction::TYPE_DISPENSE)->count())->toBeGreaterThanOrEqual(3);
    expect((int) $transactions->where('transaction_type', StockTransaction::TYPE_DISPENSE)->sum('quantity'))->toBe(-100);

    // Audit logs: creation, verification, and both dispensing completions were all recorded.
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_order_created', 'auditable_id' => $order->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_verified']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_partially_completed']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_completed']);

    // Reconciliation report: both batches' ledgers agree with their recorded quantity.
    $reconciliation = app(PharmacyReportService::class)->reconciliation(collect([$batchA->fresh(), $batchB->fresh()]));
    expect($reconciliation->pluck('reconciled')->unique()->all())->toBe([true]);

    // Medication utilization report: 100 units of Paracetamol consumed.
    $utilization = app(PharmacyReportService::class)->medicationUtilization([], $hospital->id);
    expect((int) $utilization->sum('total_quantity'))->toBe(100);

    // Patient medication history: shows the prescription as fully dispensed, not partial.
    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.id', $order->id)
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', 'dispensed'));
});
