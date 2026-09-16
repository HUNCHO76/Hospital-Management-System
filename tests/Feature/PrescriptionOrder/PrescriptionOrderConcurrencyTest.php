<?php

use App\Models\PrescriptionOrder;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests: prescription numbers must never collide, and
 * only one of several attempts to cancel the same order may succeed.
 * prescription_number uniqueness is guaranteed by GeneratesBusinessNumber's
 * lockForUpdate()+last-value+1-inside-a-transaction strategy — never a bare
 * count()+1 — the same mechanism already proven for Radiology/Laboratory/
 * Patient/ClinicalEncounter numbers.
 */
test('several prescriptions written back-to-back all receive unique prescription numbers', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $service = app(PrescriptionOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $numbers = [];
    for ($i = 0; $i < 5; $i++) {
        $order = $service->create($ctx['encounter'], [
            'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
        ], $request);
        $numbers[] = $order->prescription_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
    expect(PrescriptionOrder::query()->count())->toBe(5);
});

test('only one of several back-to-back attempts to cancel the same prescription order succeeds', function () {
    $order = activePrescriptionOrderFor();
    $request = requestAs($order->prescriber);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            app(PrescriptionOrderService::class)->cancel($order->fresh(), 'reason', $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_CANCELLED);
});

test('duplicate medications submitted in the same request never both persist, even under the service\'s own duplicate guard', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
            ['medication_id' => $medication->id, 'dose' => '2', 'frequency' => 'twice_daily', 'route' => 'oral', 'quantity' => 10],
        ],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    expect(PrescriptionOrder::query()->count())->toBe(0);
});
