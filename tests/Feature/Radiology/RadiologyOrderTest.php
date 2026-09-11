<?php

use App\Models\AuditLog;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\RadiologyOrderService;
use Illuminate\Validation\ValidationException;

test('a radiology order can be placed with multiple procedures and a generated order number', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $service = app(RadiologyOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $order = $service->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
        'priority' => 'urgent',
    ], $request);

    expect($order->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($order->priority)->toBe('urgent');
    expect($order->status)->toBe(RadiologyOrder::STATUS_ORDERED);
    expect($order->order_number)->toStartWith('RAD-'.now()->year.'-');
    expect($order->ordered_by)->toBe($ctx['doctor']['user']->id);
    expect($order->items)->toHaveCount(2);
    expect($order->items->pluck('radiology_procedure_id')->sort()->values()->all())
        ->toBe(collect([$procedureA->id, $procedureB->id])->sort()->values()->all());
});

test('placing a radiology order does not require a diagnosis to exist', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    expect($ctx['encounter']->diagnoses()->exists())->toBeFalse();

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(RadiologyOrder::STATUS_ORDERED);
});

test('order items snapshot the procedure code and name at order time', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure(['code' => 'XRAY_CHEST', 'name' => 'X-Ray Chest']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    expect($item->procedure_code)->toBe('XRAY_CHEST');
    expect($item->procedure_name)->toBe('X-Ray Chest');

    $procedure->update(['code' => 'XRAY_CHEST2', 'name' => 'Renamed Procedure']);

    expect($item->fresh()->procedure_code)->toBe('XRAY_CHEST');
    expect($item->fresh()->procedure_name)->toBe('X-Ray Chest');
});

test('duplicate procedures within one order are rejected, not silently deduplicated', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    expect(fn () => app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id, $procedure->id],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('creating an order records an audit event and one item-added event per procedure', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_created', 'auditable_id' => $order->id]);
    expect(AuditLog::query()->where('action', 'like', 'radiology_order%')->count())->toBe(3); // 1 order_created + 2 item_added
});

test('a radiology order defaults to routine priority', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->priority)->toBe(RadiologyOrder::PRIORITY_ROUTINE);
});

test('updating priority is audited distinctly from other field updates', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);

    app(RadiologyOrderService::class)->update($order, ['priority' => 'stat', 'notes' => 'Please expedite'], $request);

    expect($order->fresh()->priority)->toBe('stat');
    expect($order->fresh()->notes)->toBe('Please expedite');
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_priority_changed', 'auditable_id' => $order->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_updated', 'auditable_id' => $order->id]);
});

test('updating an order can add new procedures without touching existing items', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedureA->id]], $request);

    app(RadiologyOrderService::class)->update($order, ['procedure_ids' => [$procedureA->id, $procedureB->id]], $request);

    expect($order->fresh()->items)->toHaveCount(2);
});

test('an order cannot be updated once no longer ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);
    app(RadiologyOrderService::class)->cancel($order, 'no longer needed', $request);

    expect(fn () => app(RadiologyOrderService::class)->update($order->fresh(), ['priority' => 'stat'], $request))
        ->toThrow(ValidationException::class);
});

test('cancelling a radiology order cascades to every non-cancelled item', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedureA->id, $procedureB->id]], $request);

    app(RadiologyOrderService::class)->cancel($order, 'Ordered in error', $request);

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_CANCELLED);
    expect($order->fresh()->cancellation_reason)->toBe('Ordered in error');
    $order->fresh()->items->each(fn (RadiologyOrderItem $item) => expect($item->status)->toBe(RadiologyOrderItem::STATUS_CANCELLED));
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_cancelled', 'auditable_id' => $order->id]);
});

test('a radiology order cannot be cancelled twice', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);
    app(RadiologyOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(RadiologyOrderService::class)->cancel($order->fresh(), 'again', $request))
        ->toThrow(ValidationException::class);
});

test('a single item can be removed from an order while it is still ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedureA->id, $procedureB->id]], $request);
    $item = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);

    app(RadiologyOrderService::class)->cancelItem($item, 'Not needed', $request);

    expect($item->fresh()->status)->toBe(RadiologyOrderItem::STATUS_CANCELLED);
    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_item_removed', 'auditable_id' => $item->id]);
});

test('the worklist can be filtered by modality', function () {
    $ctx = diagnosisReadyEncounter();
    $xray = activeRadiologyProcedure(['modality' => 'x_ray']);
    $ct = activeRadiologyProcedure(['modality' => 'ct']);
    $request = requestAs($ctx['doctor']['user']);

    $xrayOrder = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$xray->id]], $request);
    app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$ct->id]], $request);

    $technician = userWithRole('radiology-technician');
    $response = $this->actingAs($technician)->get(route('radiology.orders.index', ['modality' => 'x_ray']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.id', $xrayOrder->id));
});

test('an item cannot be removed once the parent order is no longer ordered', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);
    $item = $order->items->sole();
    app(RadiologyOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(RadiologyOrderService::class)->cancelItem($item->fresh(), 'too late', $request))
        ->toThrow(ValidationException::class);
});
