<?php

use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use Illuminate\Validation\ValidationException;

test('an unpaid pharmacy charge blocks prescription verification', function () {
    $ctx = diagnosisReadyEncounter();
    // A medication with a price triggers charge capture at order time.
    $medication = activeMedication(['price' => 10000]);
    receiveStock($medication, $ctx['hospital']);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'once_daily',
            'route' => 'oral',
            'duration' => 3,
            'duration_unit' => 'days',
            'quantity' => 3,
            'instructions' => null,
        ]],
    ], requestAs($ctx['doctor']['user']));

    // One PENDING charge captured at order time.
    $this->assertDatabaseCount('charges', 1);

    $pharmacist = userWithRole('pharmacist');

    expect(fn () => app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist)))
        ->toThrow(ValidationException::class, 'Payment is required');
});

test('a prescription with no medication price never blocks verification', function () {
    $ctx = diagnosisReadyEncounter();
    // activeMedication() has no price → no charge captured.
    $medication = activeMedication();
    receiveStock($medication, $ctx['hospital']);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'once_daily',
            'route' => 'oral',
            'duration' => 3,
            'duration_unit' => 'days',
            'quantity' => 3,
            'instructions' => null,
        ]],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseCount('charges', 0);

    $pharmacist = userWithRole('pharmacist');
    $verified = app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));

    expect($verified)->not->toBeNull();
});
