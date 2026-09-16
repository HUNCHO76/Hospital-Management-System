<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Receiving stock into pharmacy inventory (Phase 6, Step 49, spec §12-13).
 */
test('receiving a brand-new medication creates its stock item and first batch with a RECEIPT transaction', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $receiver = userWithRole('pharmacist');

    $batch = app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 500,
        'unit_cost' => 12.50,
        'supplier_name' => 'Acme Pharma',
    ], requestAs($receiver));

    $item = StockItem::query()->where('hospital_id', $hospital->id)->where('medication_id', $medication->id)->sole();

    expect($item->reorder_level)->toBe(0);
    expect($batch->stock_item_id)->toBe($item->id);
    expect($batch->batch_number)->toBe('PAR-2026-001');
    expect($batch->quantity)->toBe(500);
    expect($batch->status)->toBe(StockBatch::STATUS_ACTIVE);

    $this->assertDatabaseHas('stock_transactions', [
        'stock_batch_id' => $batch->id,
        'transaction_type' => 'receipt',
        'quantity' => 500,
        'balance_before' => 0,
        'balance_after' => 500,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_item_created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_batch_created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_received']);
});

test('receiving again with the same batch number tops up the existing batch rather than duplicating it', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $receiver = userWithRole('pharmacist');
    $service = app(StockService::class);

    $expiry = now()->addYear()->toDateString();

    $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => $expiry,
        'quantity' => 500,
    ], requestAs($receiver));

    $batch = $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => $expiry,
        'quantity' => 200,
    ], requestAs($receiver));

    expect(StockBatch::query()->count())->toBe(1);
    expect($batch->quantity)->toBe(700);
    $this->assertDatabaseHas('stock_transactions', [
        'stock_batch_id' => $batch->id,
        'transaction_type' => 'receipt',
        'quantity' => 200,
        'balance_before' => 500,
        'balance_after' => 700,
    ]);
});

test('receiving the same batch number with a conflicting expiry date is rejected', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $receiver = userWithRole('pharmacist');
    $service = app(StockService::class);

    $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 500,
    ], requestAs($receiver));

    expect(fn () => $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYears(2)->toDateString(),
        'quantity' => 200,
    ], requestAs($receiver)))->toThrow(ValidationException::class);

    expect(StockBatch::query()->count())->toBe(1);
});

test('receiving into a batch that is no longer active is rejected', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $receiver = userWithRole('pharmacist');
    $service = app(StockService::class);

    $batch = $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 500,
    ], requestAs($receiver));

    $service->quarantine($batch, 'Suspected tampering', requestAs($receiver));

    expect(fn () => $service->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 50,
    ], requestAs($receiver)))->toThrow(ValidationException::class);
});

test('receiving may configure reorder and maximum levels on the stock item', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $receiver = userWithRole('pharmacist');

    app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 500,
        'reorder_level' => 50,
        'maximum_level' => 2000,
    ], requestAs($receiver));

    $item = StockItem::query()->where('hospital_id', $hospital->id)->where('medication_id', $medication->id)->sole();

    expect($item->reorder_level)->toBe(50);
    expect($item->maximum_level)->toBe(2000);
});

test('a pharmacist can receive stock over HTTP and is redirected to the stock item', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $pharmacist = userWithRole('pharmacist');

    $response = $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 500,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_batches', ['batch_number' => 'PAR-2026-001', 'quantity' => 500]);
});

test('receiving rejects a zero or negative quantity, a missing medication, and an expiry date in the past', function () {
    $hospital = Hospital::factory()->create();
    $medication = activeMedication();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 0,
    ])->assertSessionHasErrors('quantity');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => -10,
    ])->assertSessionHasErrors('quantity');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 100,
    ])->assertSessionHasErrors('medication_id');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => $medication->id,
        'batch_number' => 'PAR-2026-001',
        'expiry_date' => now()->subDay()->toDateString(),
        'quantity' => 100,
    ])->assertSessionHasErrors('expiry_date');

    $this->assertDatabaseCount('stock_batches', 0);
});
