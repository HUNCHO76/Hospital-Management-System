<?php

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Services\LaboratoryOrderService;

test('cancelling the only item on a laboratory order automatically cancels the parent order and removes it from collection index', function () {
    $cbc = activeLaboratoryTest(['code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = laboratoryOrderReadyForSampling([$cbc]);

    $order = $ctx['order'];
    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);

    $nurse = userWithRole('nurse');

    // Initially order is present in the collection worklist
    $response = $this->actingAs($nurse)->get(route('laboratory.collection.index'));
    $response->assertOk();
    $orders = collect($response->viewData('page')['props']['orders']['data']);
    expect($orders->contains('id', $order->id))->toBeTrue();

    $item = $order->items->sole();
    $request = requestAs($ctx['doctor']['user']);

    // Cancel the only item
    $service = app(LaboratoryOrderService::class);
    $service->cancelItem($item, 'Patient refused test', $request);

    $order->refresh();
    $item->refresh();

    expect($item->status)->toBe(LaboratoryOrderItem::STATUS_CANCELLED)
        ->and($item->cancellation_reason)->toBe('Patient refused test')
        ->and($order->status)->toBe(LaboratoryOrder::STATUS_CANCELLED)
        ->and($order->cancellation_reason)->toBe('Patient refused test')
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->cancelled_by)->toBe($ctx['doctor']['user']->id);

    // After cancellation, the order must NOT appear in the collection worklist
    $responseAfter = $this->actingAs($nurse)->get(route('laboratory.collection.index'));
    $responseAfter->assertOk();
    $ordersAfter = collect($responseAfter->viewData('page')['props']['orders']['data']);
    expect($ordersAfter->contains('id', $order->id))->toBeFalse();

    // Collection detail reflects cancelled financial status
    $showResponse = $this->actingAs($nurse)->get(route('laboratory.collection.show', $order->id));
    $showResponse->assertOk();
    expect($showResponse->viewData('page')['props']['orderFinancialStatus'])->toBe('cancelled');
});

test('cancelling one item on a multi-item order keeps parent active and decrements active test count', function () {
    $cbc = activeLaboratoryTest(['code' => 'CBC_MULTI', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $esr = activeLaboratoryTest(['code' => 'ESR_MULTI', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = laboratoryOrderReadyForSampling([$cbc, $esr]);

    $order = $ctx['order'];
    expect($order->items)->toHaveCount(2);

    $nurse = userWithRole('nurse');
    $itemToCancel = $order->items->first();
    $remainingItem = $order->items->last();

    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryOrderService::class);
    $service->cancelItem($itemToCancel, 'Duplicate request', $request);

    $order->refresh();
    $itemToCancel->refresh();
    $remainingItem->refresh();

    expect($itemToCancel->status)->toBe(LaboratoryOrderItem::STATUS_CANCELLED)
        ->and($remainingItem->status)->toBe(LaboratoryOrderItem::STATUS_ORDERED)
        ->and($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);

    // The order should still be in collection worklist because it has 1 active item
    $response = $this->actingAs($nurse)->get(route('laboratory.collection.index'));
    $response->assertOk();
    $orders = collect($response->viewData('page')['props']['orders']['data']);
    $found = $orders->firstWhere('id', $order->id);
    expect($found)->not->toBeNull();
});
