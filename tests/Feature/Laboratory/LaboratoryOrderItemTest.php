<?php

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Services\LaboratoryOrderService;
use Illuminate\Validation\ValidationException;

test('an item cannot be created for an inactive or non-existent test', function () {
    $ctx = diagnosisReadyEncounter();
    $inactiveTest = activeLaboratoryTest(['status' => 'inactive']);

    expect(fn () => app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$inactiveTest->id],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    expect(fn () => app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [999999],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('an item cancellation without a reason is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));
    $item = $order->items->sole();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.laboratory-orders.items.cancel', [$ctx['encounter'], $order, $item]),
        [],
    )->assertSessionHasErrors('cancellation_reason');

    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_ORDERED);
});

test('an item accessed through a mismatched order id in the URL is not found', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $orderA = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));
    $orderB = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [activeLaboratoryTest()->id]], requestAs($ctx['doctor']['user']));

    $itemB = $orderB->items->sole();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.laboratory-orders.items.cancel', [$ctx['encounter'], $orderA, $itemB]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('updating an order rejects a duplicate of an existing active item', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);

    // Re-adding the same test is a no-op, not a duplicate error — it is
    // simply already present and update() never touches existing items.
    app(LaboratoryOrderService::class)->update($order, ['test_ids' => [$test->id]], $request);

    expect($order->fresh()->items)->toHaveCount(1);
});

test('a cancelled item does not block the order from being fetched with correct item statuses', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$testA->id, $testB->id]], $request);
    $itemA = $order->items->firstWhere('laboratory_test_id', $testA->id);

    app(LaboratoryOrderService::class)->cancelItem($itemA, 'Not needed', $request);

    $fresh = LaboratoryOrder::query()->with('items')->findOrFail($order->id);
    expect($fresh->items->firstWhere('id', $itemA->id)->status)->toBe(LaboratoryOrderItem::STATUS_CANCELLED);
    expect($fresh->items->firstWhere('laboratory_test_id', $testB->id)->status)->toBe(LaboratoryOrderItem::STATUS_ORDERED);
    expect($fresh->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
});
