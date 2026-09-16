<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\DispensingService;
use App\Services\PrescriptionVerificationService;
use App\Services\StockService;

/**
 * Content/correctness for each pharmacy report (Phase 6, Step 50) — filters,
 * pagination, and that every figure is a real, live aggregation over the
 * authoritative Prescription/Verification/Dispensing/Stock tables (never a
 * fabricated or stale statistic).
 */
test('the dashboard metrics reflect real, current-state counts', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');

    // One pending, one verified, one returned.
    activePrescriptionOrderFor($ctx);
    $verified = activePrescriptionOrderFor($ctx);
    app(PrescriptionVerificationService::class)->verify($verified, [], requestAs($pharmacist));
    $returned = activePrescriptionOrderFor($ctx);
    app(PrescriptionVerificationService::class)->returnForCorrection($returned, ['return_reason' => 'x'], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('metrics.prescriptions.today', 3)
        ->where('metrics.verification.pending', 1)
        ->where('metrics.verification.verified', 1)
        ->where('metrics.verification.returned', 1));
});

test('the dashboard dispensing buckets reuse DispensingService::fulfillmentStatus() correctly', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');

    $fullyDispensed = activePrescriptionOrderFor($ctx);
    app(PrescriptionVerificationService::class)->verify($fullyDispensed, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($fullyDispensed, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $fullyDispensed->items->sole()->id, 'dispensed_quantity' => $fullyDispensed->items->sole()->quantity]],
    ], requestAs($pharmacist));

    $pendingDispensing = activePrescriptionOrderFor($ctx);
    app(PrescriptionVerificationService::class)->verify($pendingDispensing, [], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('metrics.dispensing.dispensed', 1)
        ->where('metrics.dispensing.pending', 1));
});

test('the prescription report filters by verification status', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');

    $verified = activePrescriptionOrderFor($ctx);
    app(PrescriptionVerificationService::class)->verify($verified, [], requestAs($pharmacist));
    activePrescriptionOrderFor($ctx);

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.prescriptions', ['verification_status' => 'verified']));

    $response->assertInertia(fn ($page) => $page
        ->has('prescriptions.data', 1)
        ->where('prescriptions.data.0.id', $verified->id)
        ->where('prescriptions.data.0.dispensing_status', 'pending'));
});

test('the dispensing report shows prescribed/dispensed quantities correctly for a partial dispense', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');

    $order = activePrescriptionOrderFor($ctx, ['items' => [[
        'medication_id' => activeMedication()->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 10,
    ]]]);
    receiveStock($order->items->sole()->medication, $ctx['hospital']);
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $order->items->sole()->id, 'dispensed_quantity' => 4]],
    ], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.dispensing'));

    $response->assertInertia(fn ($page) => $page
        ->has('items.data', 1)
        ->where('items.data.0.dispensed_quantity', 4)
        ->where('items.data.0.prescribed_quantity', 10));
});

test('the low-stock report only shows items at or below their reorder level', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $lowMedication = activeMedication(['generic_name' => 'Low Stock Drug']);
    $healthyMedication = activeMedication(['generic_name' => 'Healthy Stock Drug']);

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $lowMedication->id, 'batch_number' => 'LOW-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 5, 'reorder_level' => 50,
    ], requestAs($pharmacist));

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $healthyMedication->id, 'batch_number' => 'HEALTHY-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 500, 'reorder_level' => 10,
    ], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.low-stock'));

    $response->assertInertia(fn ($page) => $page
        ->has('items.data', 1)
        ->where('items.data.0.medication.generic_name', 'Low Stock Drug'));
});

test('the expiry report separates expiring-soon from expired batches', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $expiringSoonMedication = activeMedication();
    $farFutureMedication = activeMedication();

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $expiringSoonMedication->id, 'batch_number' => 'SOON-1', 'expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 10,
    ], requestAs($pharmacist));

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $farFutureMedication->id, 'batch_number' => 'FAR-1', 'expiry_date' => now()->addYears(3)->toDateString(), 'quantity' => 10,
    ], requestAs($pharmacist));

    $soonResponse = $this->actingAs($pharmacist)->get(route('pharmacy-reports.expiry', ['view' => 'expiring_soon']));
    $soonResponse->assertInertia(fn ($page) => $page->has('items.data', 1));

    $expiredResponse = $this->actingAs($pharmacist)->get(route('pharmacy-reports.expiry', ['view' => 'expired']));
    $expiredResponse->assertInertia(fn ($page) => $page->has('items.data', 0));
});

test('the stock movement report filters by transaction type', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();

    $batch = app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'MOVE-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 100,
    ], requestAs($pharmacist));

    app(StockService::class)->adjust($batch, StockTransaction::TYPE_ADJUSTMENT_OUT, 10, 'Count correction', requestAs($pharmacist));

    $receiptsResponse = $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-movements', ['transaction_type' => 'receipt']));
    $receiptsResponse->assertInertia(fn ($page) => $page->has('transactions.data', 1));

    $adjustmentsResponse = $this->actingAs($pharmacist)->get(route('pharmacy-reports.stock-movements', ['transaction_type' => 'adjustment_out']));
    $adjustmentsResponse->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.medication_name', $medication->generic_name));
});

test('the medication utilization report only counts actually-dispensed quantity, grouped by medication', function () {
    $ctx = diagnosisReadyEncounter();
    $pharmacist = userWithRole('pharmacist');
    $medication = activeMedication();

    $order = activePrescriptionOrderFor($ctx, ['items' => [[
        'medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 20,
    ]]]);
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $order->items->sole()->id, 'dispensed_quantity' => 20]],
    ], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.medication-utilization', ['group_by' => 'month']));

    $response->assertInertia(fn ($page) => $page
        ->has('rows', 1)
        ->where('rows.0.medication_name', $medication->generic_name)
        ->where('rows.0.total_quantity', 20)
        ->where('rows.0.patient_count', 1));
});

test('the reconciliation report flags a batch whose quantity was written to outside the ledger', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();

    $batch = app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'RECON-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 100,
    ], requestAs($pharmacist));

    // Simulate an out-of-band write directly to the batch's quantity,
    // bypassing StockService entirely — exactly the scenario reconcile()
    // exists to catch, never silently correct.
    StockBatch::query()->whereKey($batch->id)->update(['quantity' => 999]);

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.reconciliation'));

    $response->assertInertia(fn ($page) => $page
        ->has('rows', 1)
        ->where('rows.0.reconciled', false)
        ->where('rows.0.ledger_balance', 100)
        ->where('rows.0.recorded_quantity', 999));
});

test('a reconciled batch is not flagged', function () {
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id, 'batch_number' => 'RECON-OK-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 100,
    ], requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-reports.reconciliation'));

    $response->assertInertia(fn ($page) => $page->where('rows.0.reconciled', true));
});
