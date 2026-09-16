<?php

use App\Models\Hospital;
use App\Models\User;
use App\Services\StockService;

/**
 * The stock dashboard and searchable list (Phase 6, Step 49, spec §17/§23-
 * §25). Every classification (low/out-of-stock/expiring/expired/quarantined)
 * is computed server-side by StockSearchService, never in the browser.
 */
test('the dashboard reports real, non-fabricated counts across each classification', function () {
    $hospital = Hospital::factory()->create();
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    // Low stock: 2 available, reorder level 5.
    $lowMed = activeMedication();
    $service->receive($hospital->id, ['medication_id' => $lowMed->id, 'batch_number' => 'LOW-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 2, 'reorder_level' => 5], requestAs($actor));

    // Out of stock: fully consumed.
    $outMed = activeMedication();
    $outBatch = $service->receive($hospital->id, ['medication_id' => $outMed->id, 'batch_number' => 'OUT-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10], requestAs($actor));
    $service->adjust($outBatch, 'adjustment_out', 10, 'Consumed', requestAs($actor));

    // Expiring soon: within the default 90-day warning window.
    $expiringMed = activeMedication();
    $service->receive($hospital->id, ['medication_id' => $expiringMed->id, 'batch_number' => 'EXP-1', 'expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 50], requestAs($actor));

    // Quarantined.
    $quarantinedMed = activeMedication();
    $qBatch = $service->receive($hospital->id, ['medication_id' => $quarantinedMed->id, 'batch_number' => 'QTN-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 5], requestAs($actor));
    $service->quarantine($qBatch, 'Investigation', requestAs($actor));

    // A healthy item that should not appear in any problem bucket.
    $healthyMed = activeMedication();
    $service->receive($hospital->id, ['medication_id' => $healthyMed->id, 'batch_number' => 'OK-1', 'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => 500, 'reorder_level' => 10], requestAs($actor));

    $pharmacist = userWithRole('pharmacist');
    $response = $this->actingAs($pharmacist)->get(route('pharmacy-stock.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('counts.total_stock_items', 5)
        // Zero *available* stock is always "at or below" any non-negative
        // reorder level, so low_stock and out_of_stock are supersets, not
        // disjoint buckets: a fully-quarantined batch has zero available
        // quantity too (it is physically present but not dispensable),
        // so it counts toward both — lowMed (2/5) + outMed (0) +
        // quarantinedMed (0) = 3 low_stock; outMed + quarantinedMed = 2
        // out_of_stock.
        ->where('counts.low_stock', 3)
        ->where('counts.out_of_stock', 2)
        ->where('counts.expiring_soon', 1)
        ->where('counts.quarantined', 1));
});

test('the stock item list filters to low-stock, out-of-stock, expiring-soon, expired, and quarantined items only', function () {
    $hospital = Hospital::factory()->create();
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $lowMed = activeMedication();
    $service->receive($hospital->id, ['medication_id' => $lowMed->id, 'batch_number' => 'LOW-1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 2, 'reorder_level' => 5], requestAs($actor));

    $healthyMed = activeMedication();
    $service->receive($hospital->id, ['medication_id' => $healthyMed->id, 'batch_number' => 'OK-1', 'expiry_date' => now()->addYears(2)->toDateString(), 'quantity' => 500, 'reorder_level' => 10], requestAs($actor));

    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.index', ['status' => 'low_stock']))
        ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.medication.id', $lowMed->id));

    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.index'))
        ->assertInertia(fn ($page) => $page->has('items.data', 2));
});

test('the stock item list searches by medication name and code', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication(['generic_name' => 'Paracetamol', 'code' => 'PARA-500']);
    receiveStock($medication, $hospital, 100);
    receiveStock(activeMedication(['generic_name' => 'Ibuprofen', 'code' => 'IBU-200']), $hospital, 100);

    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.index', ['search' => 'Paracetamol']))
        ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.medication.code', 'PARA-500'));
});

test('the stock item detail page shows every batch and its transaction ledger', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $batch = receiveStock($medication, $hospital, 100);
    $pharmacist = userWithRole('pharmacist');

    app(StockService::class)->adjust($batch, 'adjustment_in', 5, 'Recount', requestAs($pharmacist));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.show', $batch->stock_item_id));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('item.total_quantity', 105)
        ->where('item.available_quantity', 105)
        ->has('item.batches', 1)
        ->has('transactions', 2)
        ->where('canViewTransactions', true));
});

test('a stock item detail page hides the transaction ledger from a user without pharmacy-stock.view_transactions', function () {
    $hospital = Hospital::factory()->create();
    $batch = receiveStock(activeMedication(), $hospital, 100);

    seedRolesAndPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo('pharmacy-stock.view');

    $this->actingAs($user)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))
        ->assertInertia(fn ($page) => $page->where('canViewTransactions', false)->where('transactions', []));
});
