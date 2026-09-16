<?php

use App\Models\Hospital;
use App\Models\StockBatch;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Quarantine/release (Phase 6, Step 49, spec §9/§20-21): a reversible hold
 * that removes a batch from dispensable inventory without deleting its
 * history or changing its quantity.
 */
test('quarantining an active batch holds it out of dispensable stock without changing quantity', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');

    $updated = app(StockService::class)->quarantine($batch, 'Suspected tampering', requestAs($actor));

    expect($updated->status)->toBe(StockBatch::STATUS_QUARANTINED);
    expect($updated->quantity)->toBe(100);
    expect($updated->isDispensable())->toBeFalse();
    $this->assertDatabaseHas('stock_transactions', ['stock_batch_id' => $batch->id, 'transaction_type' => 'quarantine', 'quantity' => 0]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_quarantined']);
});

test('only an active batch can be quarantined', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $service->quarantine($batch, 'reason', requestAs($actor));

    expect(fn () => $service->quarantine($batch->fresh(), 'reason again', requestAs($actor)))->toThrow(ValidationException::class);
});

test('releasing a quarantined batch returns it to active, dispensable stock', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $quarantined = $service->quarantine($batch, 'reason', requestAs($actor));
    $released = $service->release($quarantined, 'Investigation cleared', requestAs($actor));

    expect($released->status)->toBe(StockBatch::STATUS_ACTIVE);
    expect($released->isDispensable())->toBeTrue();
    $this->assertDatabaseHas('stock_transactions', ['stock_batch_id' => $batch->id, 'transaction_type' => 'release']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_released']);
});

test('only a quarantined batch can be released', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $actor = userWithRole('pharmacist');

    expect(fn () => app(StockService::class)->release($batch, null, requestAs($actor)))->toThrow(ValidationException::class);
});

test('a batch that expired while quarantined cannot be released back into active stock', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100, now()->addDay()->toDateString());
    $actor = userWithRole('pharmacist');
    $service = app(StockService::class);

    $quarantined = $service->quarantine($batch, 'reason', requestAs($actor));
    $this->travel(2)->days();

    expect(fn () => $service->release($quarantined->fresh(), null, requestAs($actor)))->toThrow(ValidationException::class);

    $this->travelBack();
});

test('a pharmacist can quarantine and release a batch over HTTP', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.quarantine', $batch), [])->assertSessionHasErrors('reason');

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.quarantine', $batch), [
        'reason' => 'Suspected tampering',
    ])->assertRedirect();

    expect($batch->fresh()->status)->toBe(StockBatch::STATUS_QUARANTINED);

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.release', $batch))->assertRedirect();

    expect($batch->fresh()->status)->toBe(StockBatch::STATUS_ACTIVE);
});
