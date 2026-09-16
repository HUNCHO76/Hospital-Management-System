<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockItem;

/**
 * Security-focused validation for the stock module (Phase 6, Step 49, spec
 * §40): mass assignment, quantity manipulation, and status manipulation are
 * all rejected server-side, never relying on the frontend's own constraints.
 * CSRF/authentication are inherited unchanged from this application's
 * existing global 'web' middleware group and Laravel session guard — no
 * stock-specific test is needed for those (no other module in this suite
 * tests them individually either; they are cross-cutting framework
 * guarantees, not something this step's own code could bypass).
 */
test('a client-supplied hospital_id, status, balance, or transaction_type is never mass-assignable through the receive request', function () {
    $hospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $medication = activeMedication();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => $medication->id,
        'batch_number' => 'X-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 100,
        // Attempted injection — none of these are accepted fields.
        'status' => 'quarantined',
        'balance_before' => 999,
        'balance_after' => 999,
        'transaction_type' => 'adjustment_in',
        'created_by' => 999999,
    ]);

    $batch = StockBatch::query()->where('batch_number', 'X-001')->sole();
    expect($batch->status)->toBe(StockBatch::STATUS_ACTIVE);
    expect($batch->createdBy->id)->toBe($pharmacist->id);
    $this->assertDatabaseHas('stock_transactions', ['stock_batch_id' => $batch->id, 'balance_before' => 0, 'balance_after' => 100]);

    expect(StockItem::query()->where('hospital_id', $otherHospital->id)->exists())->toBeFalse();
});

test('a client-supplied status, performed_by, or balance is never mass-assignable through the adjust or quarantine requests', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $pharmacist = userWithRole('pharmacist');
    $otherUser = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => 10,
        'reason' => 'Count correction',
        'status' => 'expired',
        'balance_before' => 0,
        'balance_after' => 0,
        'performed_by' => $otherUser->id,
    ]);

    $adjusted = $batch->fresh();
    expect($adjusted->status)->toBe(StockBatch::STATUS_ACTIVE);
    expect($adjusted->quantity)->toBe(90);
    $this->assertDatabaseHas('stock_transactions', ['stock_batch_id' => $batch->id, 'transaction_type' => 'adjustment_out', 'performed_by' => $pharmacist->id]);

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.quarantine', $batch), [
        'reason' => 'Suspected tampering',
        'status' => 'active',
    ]);

    expect($batch->fresh()->status)->toBe(StockBatch::STATUS_QUARANTINED);
});

test('negative or zero quantities are rejected on both receive and adjust', function () {
    $hospital = Hospital::factory()->create();
    $batch = receiveStock(activeMedication(), $hospital, 100);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => activeMedication()->id,
        'batch_number' => 'X-002',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => -5,
    ])->assertSessionHasErrors('quantity');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => -5,
        'reason' => 'x',
    ])->assertSessionHasErrors('quantity');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => 0,
        'reason' => 'x',
    ])->assertSessionHasErrors('quantity');

    expect($batch->fresh()->quantity)->toBe(100);
});

test('an adjustment quantity that would exceed the batch balance is rejected over HTTP, never trusting the client\'s own number', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 10);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out',
        'quantity' => 999999,
        'reason' => 'x',
    ])->assertSessionHasErrors('quantity');

    expect($batch->fresh()->quantity)->toBe(10);
});

test('reasons and notes containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $pharmacist = userWithRole('pharmacist');
    $payload = '<script>alert(1)</script>';

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.quarantine', $batch), ['reason' => $payload]);

    $this->assertDatabaseHas('stock_transactions', ['stock_batch_id' => $batch->id, 'transaction_type' => 'quarantine', 'reason' => $payload]);
});

test('an invalid adjustment type is rejected rather than silently falling back to a default', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'set_balance',
        'quantity' => 10,
        'reason' => 'x',
    ])->assertSessionHasErrors('type');

    expect($batch->fresh()->quantity)->toBe(100);
});
