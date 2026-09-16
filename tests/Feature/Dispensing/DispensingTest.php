<?php

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Services\DispensingService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

test('starting dispensing creates an in-progress event with a generated dispensing number', function () {
    $order = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    expect($dispensing->prescription_order_id)->toBe($order->id);
    expect($dispensing->status)->toBe(Dispensing::STATUS_IN_PROGRESS);
    expect($dispensing->dispensing_number)->toStartWith('DSP-'.now()->year.'-');
    expect($dispensing->dispensed_by)->toBe($pharmacist->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_started', 'auditable_id' => $dispensing->id]);
});

test('dispensing cannot be started for a prescription that is not verified', function () {
    $order = activePrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    expect(fn () => app(DispensingService::class)->start($order, requestAs($pharmacist)))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('dispensings', 0);
});

test('dispensing cannot be started twice for the same prescription while one is already in progress', function () {
    $order = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    app(DispensingService::class)->start($order, requestAs($pharmacist));

    expect(fn () => app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist)))
        ->toThrow(ValidationException::class);

    expect(Dispensing::query()->count())->toBe(1);
});

test('completing dispensing with the full prescribed quantity marks the prescription fully dispensed', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    $service = app(DispensingService::class);
    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity]],
    ], requestAs($pharmacist));

    expect($dispensing->fresh()->status)->toBe(Dispensing::STATUS_COMPLETED);
    expect($service->fulfillmentStatus($order->fresh()))->toBe('dispensed');
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_completed', 'auditable_id' => $dispensing->id]);
});

test('completing dispensing with less than the prescribed quantity marks the prescription partially dispensed', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    expect($item->quantity)->toBe(15);
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    $service = app(DispensingService::class);
    $service->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 10]],
    ], requestAs($pharmacist));

    expect($service->remainingQuantity($item->fresh()))->toBe(5);
    expect($service->fulfillmentStatus($order->fresh()))->toBe('partially_dispensed');
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_partially_completed', 'auditable_id' => $dispensing->id]);
});

test('a second dispensing event can fulfill the remaining quantity, preserving both events in history', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);

    $first = $service->start($order, requestAs($pharmacist));
    $service->complete($first, ['items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 10]]], requestAs($pharmacist));

    $second = $service->start($order->fresh(), requestAs($pharmacist));
    $service->complete($second, ['items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 5]]], requestAs($pharmacist));

    expect(Dispensing::query()->count())->toBe(2);
    expect($service->remainingQuantity($item->fresh()))->toBe(0);
    expect($service->fulfillmentStatus($order->fresh()))->toBe('dispensed');
    expect(DispensingItem::query()->count())->toBe(2);
});

test('completing dispensing rejects a quantity greater than what remains', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    expect(fn () => app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity + 1]],
    ], requestAs($pharmacist)))->toThrow(ValidationException::class);

    expect($dispensing->fresh()->status)->toBe(Dispensing::STATUS_IN_PROGRESS);
    $this->assertDatabaseCount('dispensing_items', 0);
});

test('completing dispensing rejects an item that does not belong to the prescription', function () {
    $order = verifiedPrescriptionOrderFor();
    $otherOrder = verifiedPrescriptionOrderFor();
    $otherItem = $otherOrder->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    expect(fn () => app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $otherItem->id, 'dispensed_quantity' => 1]],
    ], requestAs($pharmacist)))->toThrow(ValidationException::class);
});

test('marking a medication unavailable records zero dispensed with the unavailable status, distinctly from a partial quantity', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 0, 'status' => 'unavailable']],
    ], requestAs($pharmacist));

    $recorded = DispensingItem::query()->where('dispensing_id', $dispensing->id)->sole();
    expect($recorded->status)->toBe(DispensingItem::STATUS_UNAVAILABLE);
    expect($recorded->dispensed_quantity)->toBe(0);
    expect(app(DispensingService::class)->fulfillmentStatus($order->fresh()))->toBe('pending');
});

test('cancelling an in-progress dispensing event never counts toward dispensed quantity', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($pharmacist));

    $service->cancel($dispensing, 'Patient did not arrive', requestAs($pharmacist));

    expect($dispensing->fresh()->status)->toBe(Dispensing::STATUS_CANCELLED);
    expect($service->remainingQuantity($item->fresh()))->toBe($item->quantity);
    $this->assertDatabaseHas('audit_logs', ['action' => 'dispensing_cancelled', 'auditable_id' => $dispensing->id]);
});

test('a dispensing event cannot be completed once already cancelled', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($pharmacist));
    $service->cancel($dispensing, 'reason', requestAs($pharmacist));

    expect(fn () => $service->complete($dispensing->fresh(), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 1]],
    ], requestAs($pharmacist)))->toThrow(ValidationException::class);
});

test('a fully dispensed prescription cannot have a new dispensing event started', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);
    $dispensing = $service->start($order, requestAs($pharmacist));
    $service->complete($dispensing, ['items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity]]], requestAs($pharmacist));

    expect(fn () => $service->start($order->fresh(), requestAs($pharmacist)))->toThrow(ValidationException::class);
});

test('a cancelled prescription order cannot be dispensed even if it was already verified', function () {
    $order = verifiedPrescriptionOrderFor();
    app(PrescriptionOrderService::class)->cancel($order, 'Discontinued', requestAs($order->prescriber));
    $pharmacist = userWithRole('pharmacist');

    expect(fn () => app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist)))
        ->toThrow(ValidationException::class);
});
