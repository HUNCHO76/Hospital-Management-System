<?php

use App\Models\Dispensing;
use App\Services\DispensingService;
use App\Services\PrescriptionVerificationService;

test('a dispensing cannot be started for a still-pending-verification prescription over HTTP', function () {
    $order = activePrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertSessionHasErrors('status');

    $this->assertDatabaseCount('dispensings', 0);
});

test('a dispensing cannot be started for a returned prescription over HTTP', function () {
    $order = activePrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->returnForCorrection($order, ['return_reason' => 'Missing dose'], requestAs($pharmacist));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertSessionHasErrors('status');

    $this->assertDatabaseCount('dispensings', 0);
});

test('a negative dispensed quantity is rejected over HTTP', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => -1]],
    ])->assertSessionHasErrors('items.0.dispensed_quantity');

    $this->assertDatabaseCount('dispensing_items', 0);
});

test('an empty item list is rejected over HTTP when completing dispensing', function () {
    $order = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [],
    ])->assertSessionHasErrors('items');
});

test('quantity exceeding the remaining amount is rejected over HTTP, never trusting the client\'s own number', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 999999]],
    ])->assertSessionHasErrors('items');

    $this->assertDatabaseCount('dispensing_items', 0);
});

test('cancellation without a reason is rejected over HTTP', function () {
    $order = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.cancel', $order), [])
        ->assertSessionHasErrors('cancellation_reason');

    expect(Dispensing::query()->where('prescription_order_id', $order->id)->sole()->status)->toBe(Dispensing::STATUS_IN_PROGRESS);
});

test('a client-supplied dispensed_by, status, or prescription_order_id is never mass-assignable through the complete request', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $otherUser = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity]],
        'dispensed_by' => $otherUser->id,
        'status' => 'completed',
        'prescription_order_id' => 999999,
    ])->assertRedirect();

    $dispensing = Dispensing::query()->where('prescription_order_id', $order->id)->sole();
    expect($dispensing->dispensed_by)->toBe($pharmacist->id);
});

test('instructions and dispensing notes containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $payload = '<script>alert(1)</script>';
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    $completed = app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 1, 'notes' => $payload]],
        'notes' => $payload,
    ], requestAs($pharmacist));

    expect($completed->notes)->toBe($payload);
    expect($completed->items->sole()->notes)->toBe($payload);
});

test('a dispensing event is always attached to its own prescription — there is no input path to attach it to another', function () {
    $orderA = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    $dispensing = app(DispensingService::class)->start($orderA, requestAs($pharmacist));

    expect($dispensing->prescriptionOrder->id)->toBe($orderA->id);
});
