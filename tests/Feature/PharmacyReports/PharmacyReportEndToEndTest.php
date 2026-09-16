<?php

use App\Services\DispensingService;
use App\Services\PrescriptionVerificationService;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * The full Step 50 pathway (spec §28): receive stock -> doctor prescribes ->
 * pharmacist verifies -> pharmacist dispenses -> stock is deducted -> every
 * report reflects the same transaction consistently (spec §28's own
 * "opening stock = 100, dispense = 10, expected current stock = 90"
 * example, reproduced exactly).
 */
test('receiving, prescribing, verifying, and dispensing are all correctly reflected across every report', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');
    $medication = activeMedication(['generic_name' => 'E2E Test Medication']);

    // Receive 100 units.
    $batch = app(StockService::class)->receive($ctx['hospital']->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'E2E-BATCH-1',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 100,
    ], requestAs($pharmacist));

    expect($batch->quantity)->toBe(100);

    // The receipt appears on the movement report.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-movements'))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.transaction_type', 'receipt')
            ->where('transactions.data.0.balance_after', 100));

    // Doctor prescribes 10 units.
    $order = activePrescriptionOrderFor($ctx, ['items' => [[
        'medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 10,
    ]]]);

    $this->actingAs($pharmacist)->get(route('pharmacy-reports.prescriptions'))
        ->assertInertia(fn ($page) => $page->where('prescriptions.data.0.id', $order->id));

    // Pharmacist verifies.
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));

    $this->actingAs($pharmacist)->get(route('pharmacy-reports.prescriptions', ['verification_status' => 'verified']))
        ->assertInertia(fn ($page) => $page->has('prescriptions.data', 1));

    // Pharmacist dispenses 10 units.
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $order->items->sole()->id, 'dispensed_quantity' => 10]],
    ], requestAs($pharmacist));

    // Stock is now 90.
    expect($batch->fresh()->quantity)->toBe(90);

    // The dispensing report shows it.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.dispensing'))
        ->assertInertia(fn ($page) => $page
            ->where('items.data.0.dispensed_quantity', 10)
            ->where('items.data.0.prescribed_quantity', 10));

    // The movement report shows the DISPENSE transaction with the correct
    // before/after balance.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-movements', ['transaction_type' => 'dispense']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.balance_before', 100)
            ->where('transactions.data.0.balance_after', 90)
            ->where('transactions.data.0.quantity', -10));

    // The utilization report shows 10 units consumed this month.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.medication-utilization'))
        ->assertInertia(fn ($page) => $page->where('rows.0.total_quantity', 10));

    // The stock batch report shows the remaining quantity.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-batches'))
        ->assertInertia(fn ($page) => $page->where('batches.data.0.quantity', 90));

    // Reconciliation confirms the ledger and recorded quantity still agree.
    $this->actingAs($pharmacist)->get(route('pharmacy-reports.reconciliation'))
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.ledger_balance', 90)
            ->where('rows.0.recorded_quantity', 90)
            ->where('rows.0.reconciled', true));

    // The dashboard's own KPIs agree too. Two receipts occurred: the
    // explicit 100-unit one above, and the one activePrescriptionOrderFor()
    // itself triggers to guarantee its own order is dispensable.
    $this->actingAs($pharmacist)->get(route('pharmacy.index'))
        ->assertInertia(fn ($page) => $page
            ->where('metrics.dispensing.dispensed', 1)
            ->where('metrics.movements.receipts', 2)
            ->where('metrics.movements.dispense', 1));
});

test('FEFO is reflected in the movement report: the earlier-expiring batch is consumed first', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');
    $medication = activeMedication();

    $batchA = app(StockService::class)->receive($ctx['hospital']->id, [
        'medication_id' => $medication->id, 'batch_number' => 'FEFO-SOON', 'expiry_date' => now()->addMonths(2)->toDateString(), 'quantity' => 50,
    ], requestAs($pharmacist));

    $batchB = app(StockService::class)->receive($ctx['hospital']->id, [
        'medication_id' => $medication->id, 'batch_number' => 'FEFO-LATER', 'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => 50,
    ], requestAs($pharmacist));

    $order = activePrescriptionOrderFor($ctx, ['items' => [[
        'medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 30,
    ]]]);
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $order->items->sole()->id, 'dispensed_quantity' => 30]],
    ], requestAs($pharmacist));

    expect($batchA->fresh()->quantity)->toBe(20);
    expect($batchB->fresh()->quantity)->toBe(50);

    $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-movements', ['transaction_type' => 'dispense']))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('an expired batch is never dispensable and never counted as available in reports', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');
    $medication = activeMedication();

    app(StockService::class)->receive($ctx['hospital']->id, [
        'medication_id' => $medication->id, 'batch_number' => 'EXPIRED-BATCH', 'expiry_date' => now()->subDay()->toDateString(), 'quantity' => 100,
    ], requestAs($pharmacist));

    $this->actingAs($pharmacist)->get(route('pharmacy-reports.expiry', ['view' => 'expired']))
        ->assertInertia(fn ($page) => $page->where('items.data.0.available_quantity', 0));

    // With no OTHER stock, the order cannot even be dispensed against this
    // expired-only supply — proving expired stock is excluded from
    // dispensable inventory server-side, not merely hidden in a report.
    expect(fn () => app(StockService::class)->allocateFefo($ctx['hospital']->id, $medication->id, 1, 'test', 1, requestAs($pharmacist)))
        ->toThrow(ValidationException::class);
});
