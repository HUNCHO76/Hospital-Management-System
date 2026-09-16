<?php

use App\Models\PrescriptionVerification;

test('returning a prescription without a reason is rejected', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.return', $order), [])
        ->assertSessionHasErrors('return_reason');

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('returning a prescription with a blank reason is rejected', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.return', $order), ['return_reason' => '   '])
        ->assertSessionHasErrors('return_reason');
});

test('returning a prescription with a real reason succeeds', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.return', $order), [
        'return_reason' => 'Quantity does not match prescribed duration.',
    ])->assertRedirect();

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_RETURNED);
});

test('verification_notes exceeding the length limit is rejected', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order), [
        'verification_notes' => str_repeat('a', 1001),
    ])->assertSessionHasErrors('verification_notes');
});

test('a return_reason exceeding the length limit is rejected', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.return', $order), [
        'return_reason' => str_repeat('a', 1001),
    ])->assertSessionHasErrors('return_reason');
});

test('a verified prescription cannot be verified again through the verification route', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order))->assertRedirect();

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order))
        ->assertSessionHasErrors('status');
});

test('a verified prescription cannot be returned through the verification route', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order))->assertRedirect();

    $this->actingAs($verifier)->post(route('pharmacy-verification.return', $order), ['return_reason' => 'x'])
        ->assertSessionHasErrors('status');
});

test('a pending prescription cannot be resubmitted', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.resubmit', $order))
        ->assertSessionHasErrors('status');
});

test('a client-supplied verifier id or status is ignored', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');
    $otherUser = userWithRole('pharmacist');

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order), [
        'verified_by' => $otherUser->id,
        'status' => 'verified',
    ])->assertRedirect();

    expect($order->verification->fresh()->verified_by)->toBe($verifier->id);
});
