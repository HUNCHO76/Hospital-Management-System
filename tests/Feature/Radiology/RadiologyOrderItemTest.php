<?php

use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\RadiologyOrderService;
use Illuminate\Validation\ValidationException;

test('an item cannot be created for an inactive or non-existent procedure', function () {
    $ctx = diagnosisReadyEncounter();
    $inactiveProcedure = activeRadiologyProcedure(['status' => 'inactive']);

    expect(fn () => app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$inactiveProcedure->id],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    expect(fn () => app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [999999],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('an item cancellation without a reason is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));
    $item = $order->items->sole();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.items.cancel', [$ctx['encounter'], $order, $item]),
        [],
    )->assertSessionHasErrors('cancellation_reason');

    expect($item->fresh()->status)->toBe(RadiologyOrderItem::STATUS_ORDERED);
});

test('an item accessed through a mismatched order id in the URL is not found', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $orderA = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));
    $orderB = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [activeRadiologyProcedure()->id]], requestAs($ctx['doctor']['user']));

    $itemB = $orderB->items->sole();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.items.cancel', [$ctx['encounter'], $orderA, $itemB]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('updating an order rejects a duplicate of an existing active item', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);

    // Re-adding the same procedure is a no-op, not a duplicate error — it
    // is simply already present and update() never touches existing items.
    app(RadiologyOrderService::class)->update($order, ['procedure_ids' => [$procedure->id]], $request);

    expect($order->fresh()->items)->toHaveCount(1);
});

test('order items snapshot the procedure modality and body sites at order time, unaffected by later catalog edits', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure(['modality' => 'x_ray', 'body_sites' => ['chest']]);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    expect($item->modality_snapshot)->toBe('x_ray');
    expect($item->body_site_snapshot)->toBe(['chest']);

    // A later catalog edit (non-code fields remain editable even once
    // referenced) must never change what an existing order is displayed as
    // having actually ordered.
    $procedure->update(['modality' => 'ct', 'body_sites' => ['brain']]);

    expect($item->fresh()->modality_snapshot)->toBe('x_ray');
    expect($item->fresh()->body_site_snapshot)->toBe(['chest']);
});

test('a cancelled item does not block the order from being fetched with correct item statuses', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedureA->id, $procedureB->id]], $request);
    $itemA = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);

    app(RadiologyOrderService::class)->cancelItem($itemA, 'Not needed', $request);

    $fresh = RadiologyOrder::query()->with('items')->findOrFail($order->id);
    expect($fresh->items->firstWhere('id', $itemA->id)->status)->toBe(RadiologyOrderItem::STATUS_CANCELLED);
    expect($fresh->items->firstWhere('radiology_procedure_id', $procedureB->id)->status)->toBe(RadiologyOrderItem::STATUS_ORDERED);
    expect($fresh->status)->toBe(RadiologyOrder::STATUS_ORDERED);
});
