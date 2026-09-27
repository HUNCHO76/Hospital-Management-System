<?php

use App\Models\PrescriptionVerification;
use App\Services\DispensingService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

test('an unpaid pharmacy charge blocks dispensing start', function () {
    $ctx = diagnosisReadyEncounter();
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

    // Bypass the verification gate (tested separately in PharmacyVerificationPaymentGateTest)
    // by directly marking the verification as verified, then test ONLY the dispensing start gate.
    $order->verification->update([
        'status' => PrescriptionVerification::STATUS_VERIFIED,
        'verified_by' => $ctx['doctor']['user']->id,
        'verified_at' => now(),
    ]);

    $this->assertDatabaseCount('charges', 1);

    $pharmacist = userWithRole('pharmacist');

    expect(fn () => app(DispensingService::class)->start($order->fresh(['items', 'verification']), requestAs($pharmacist)))
        ->toThrow(ValidationException::class, 'Payment is required');

    $this->assertDatabaseCount('dispensings', 0);
});

test('a prescription with no medication price never blocks dispensing start', function () {
    // verifiedPrescriptionOrderFor() uses activeMedication() (no price) → no charge captured.
    $order = verifiedPrescriptionOrderFor();
    $this->assertDatabaseCount('charges', 0);

    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));

    expect($dispensing)->not->toBeNull();
    $this->assertDatabaseCount('dispensings', 1);
});
