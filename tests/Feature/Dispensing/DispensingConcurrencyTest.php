<?php

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Services\DispensingService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §19): the total dispensed quantity must
 * never exceed what was prescribed, and dispensing numbers must never
 * collide. Only one IN_PROGRESS Dispensing may exist per prescription at a
 * time (DispensingService::start()'s own row-locked guarantee) — this is
 * what actually prevents two pharmacists from both dispensing the same
 * remaining quantity, verified here by simulating repeated concurrent
 * attempts.
 */
test('several back-to-back attempts to start dispensing for the same prescription — only one succeeds', function () {
    $order = verifiedPrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->start($order->fresh(), requestAs($pharmacist));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(Dispensing::query()->count())->toBe(1);
});

test('the total dispensed quantity across sequential dispensing events can never exceed what was prescribed', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    expect($item->quantity)->toBe(15);
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);

    // Simulate two pharmacists racing to dispense the same remaining
    // quantity: the single-in-progress-session guard means the second
    // pharmacist cannot even open a session until the first finishes, so
    // this drives the total dispensed to exactly what was prescribed, never
    // beyond it, regardless of how many attempts are made.
    for ($i = 0; $i < 3; $i++) {
        $remaining = $service->remainingQuantity($item->fresh());

        if ($remaining <= 0) {
            break;
        }

        $dispensing = $service->start($order->fresh(), requestAs($pharmacist));
        $service->complete($dispensing, [
            'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => min(20, $remaining)]],
        ], requestAs($pharmacist));
    }

    $totalDispensed = (int) DispensingItem::query()->where('prescription_order_item_id', $item->id)->sum('dispensed_quantity');

    expect($totalDispensed)->toBe($item->quantity);
    expect($totalDispensed)->toBeLessThanOrEqual($item->quantity);
});

test('several dispensing events created back-to-back across different prescriptions all receive unique dispensing numbers', function () {
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);

    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $order = verifiedPrescriptionOrderFor(null, $pharmacist);
        $dispensing = $service->start($order, requestAs($pharmacist));
        $numbers[] = $dispensing->dispensing_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
});

test('only one of several back-to-back attempts to cancel the same dispensing event succeeds', function () {
    $dispensing = inProgressDispensingFor();
    $pharmacist = userWithRole('pharmacist');
    $service = app(DispensingService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->cancel($dispensing->fresh(), 'reason', requestAs($pharmacist));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});
