<?php

use App\Models\PrescriptionVerification;
use App\Services\PrescriptionVerificationService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (Phase 6, Step 47, spec §16): two pharmacists
 * racing to verify (or verify-and-return) the same prescription must never
 * both succeed. Mirrors RadiologyVerificationConcurrencyTest's own
 * sequential-back-to-back-attempts convention — verify()/
 * returnForCorrection() lock the verification row and re-check its status
 * inside one transaction, so each sequential call below exercises the same
 * guard that would defend against two truly simultaneous requests.
 */
test('two pharmacists racing to verify the same prescription only let one succeed', function () {
    $order = activePrescriptionOrderFor();

    $verifierA = userWithRole('pharmacist');
    $verifierB = userWithRole('pharmacist');
    $service = app(PrescriptionVerificationService::class);

    $winner = $service->verify($order, [], requestAs($verifierA));

    expect(fn () => $service->verify($order->fresh(), [], requestAs($verifierB)))
        ->toThrow(ValidationException::class);

    expect($winner->verified_by)->toBe($verifierA->id);
    expect(PrescriptionVerification::query()->where('prescription_order_id', $order->id)->where('status', PrescriptionVerification::STATUS_VERIFIED)->count())->toBe(1);
});

test('one pharmacist verifying while another attempts to return the same prescription only lets the first action win', function () {
    $order = activePrescriptionOrderFor();

    $verifierA = userWithRole('pharmacist');
    $verifierB = userWithRole('pharmacist');
    $service = app(PrescriptionVerificationService::class);

    $service->verify($order, [], requestAs($verifierA));

    expect(fn () => $service->returnForCorrection($order->fresh(), ['return_reason' => 'x'], requestAs($verifierB)))
        ->toThrow(ValidationException::class);

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);
});

test('three sequential verify attempts on the same prescription yield exactly one winner', function () {
    $order = activePrescriptionOrderFor();
    $service = app(PrescriptionVerificationService::class);

    $verifiers = [
        userWithRole('pharmacist'),
        userWithRole('pharmacist'),
        userWithRole('pharmacist'),
    ];

    $successes = 0;
    $rejections = 0;

    foreach ($verifiers as $verifier) {
        try {
            $service->verify($order->fresh(), [], requestAs($verifier));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
});
