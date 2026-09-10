<?php

use App\Models\AuditLog;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Services\LaboratoryOrderService;
use Illuminate\Validation\ValidationException;

test('a laboratory order can be placed with multiple tests and a generated order number', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $service = app(LaboratoryOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $order = $service->create($ctx['encounter'], [
        'test_ids' => [$testA->id, $testB->id],
        'priority' => 'urgent',
    ], $request);

    expect($order->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($order->priority)->toBe('urgent');
    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
    expect($order->order_number)->toStartWith('LAB-'.now()->year.'-');
    expect($order->ordered_by)->toBe($ctx['doctor']['user']->id);
    expect($order->items)->toHaveCount(2);
    expect($order->items->pluck('laboratory_test_id')->sort()->values()->all())->toBe(collect([$testA->id, $testB->id])->sort()->values()->all());
});

test('placing a laboratory order does not require a diagnosis to exist', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    expect($ctx['encounter']->diagnoses()->exists())->toBeFalse();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
});

test('order items snapshot the test code and name at order time', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest(['code' => 'FBC', 'name' => 'Full Blood Count']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    expect($item->test_code)->toBe('FBC');
    expect($item->test_name)->toBe('Full Blood Count');

    $test->update(['code' => 'FBC2', 'name' => 'Renamed Test']);

    expect($item->fresh()->test_code)->toBe('FBC');
    expect($item->fresh()->test_name)->toBe('Full Blood Count');
});

test('duplicate tests within one order are rejected, not silently deduplicated', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    expect(fn () => app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id, $test->id],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('creating an order records an audit event and one item-added event per test', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$testA->id, $testB->id],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_created', 'auditable_id' => $order->id]);
    expect(AuditLog::query()->where('action', 'like', 'laboratory_order%')->count())->toBe(3); // 1 order_created + 2 item_added
});

test('a laboratory order defaults to routine priority', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->priority)->toBe(LaboratoryOrder::PRIORITY_ROUTINE);
});

test('updating priority is audited distinctly from other field updates', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);

    app(LaboratoryOrderService::class)->update($order, ['priority' => 'stat', 'notes' => 'Please expedite'], $request);

    expect($order->fresh()->priority)->toBe('stat');
    expect($order->fresh()->notes)->toBe('Please expedite');
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_priority_changed', 'auditable_id' => $order->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_updated', 'auditable_id' => $order->id]);
});

test('updating an order can add new tests without touching existing items', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$testA->id]], $request);

    app(LaboratoryOrderService::class)->update($order, ['test_ids' => [$testA->id, $testB->id]], $request);

    expect($order->fresh()->items)->toHaveCount(2);
});

test('an order cannot be updated once no longer ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
    app(LaboratoryOrderService::class)->cancel($order, 'no longer needed', $request);

    expect(fn () => app(LaboratoryOrderService::class)->update($order->fresh(), ['priority' => 'stat'], $request))
        ->toThrow(ValidationException::class);
});

test('cancelling a laboratory order cascades to every non-cancelled item', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$testA->id, $testB->id]], $request);

    app(LaboratoryOrderService::class)->cancel($order, 'Ordered in error', $request);

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_CANCELLED);
    expect($order->fresh()->cancellation_reason)->toBe('Ordered in error');
    $order->fresh()->items->each(fn (LaboratoryOrderItem $item) => expect($item->status)->toBe(LaboratoryOrderItem::STATUS_CANCELLED));
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_cancelled', 'auditable_id' => $order->id]);
});

test('a laboratory order cannot be cancelled twice', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
    app(LaboratoryOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(LaboratoryOrderService::class)->cancel($order->fresh(), 'again', $request))
        ->toThrow(ValidationException::class);
});

test('a single item can be removed from an order while it is still ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$testA->id, $testB->id]], $request);
    $item = $order->items->firstWhere('laboratory_test_id', $testA->id);

    app(LaboratoryOrderService::class)->cancelItem($item, 'Not needed', $request);

    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_CANCELLED);
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_item_removed', 'auditable_id' => $item->id]);
});

test('an item cannot be removed once the parent order is no longer ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
    $item = $order->items->sole();
    app(LaboratoryOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(LaboratoryOrderService::class)->cancelItem($item->fresh(), 'too late', $request))
        ->toThrow(ValidationException::class);
});
