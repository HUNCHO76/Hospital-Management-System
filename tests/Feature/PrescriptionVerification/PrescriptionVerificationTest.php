<?php

use App\Models\AuditLog;
use App\Models\PrescriptionVerification;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use Illuminate\Validation\ValidationException;

/**
 * Core PrescriptionVerificationService behavior (Phase 6, Step 47): a
 * PrescriptionOrder is created already PENDING_VERIFICATION (creation and
 * submission are the same moment in this architecture), then verify,
 * return-for-correction, the self-verification rule, and the
 * corrected-order resubmission cycle.
 */
test('creating a prescription order automatically enters the verification queue as pending', function () {
    $order = activePrescriptionOrderFor();

    expect($order->verification)->not->toBeNull();
    expect($order->verification->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
    expect($order->verification->submitted_by)->toBe($order->prescriber_id);
    expect($order->verification->submitted_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_submitted', 'auditable_id' => $order->verification->id]);
});

test('a pending prescription can be verified by an independent pharmacist, recording verifier metadata', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $verification = app(PrescriptionVerificationService::class)->verify($order, ['verification_notes' => 'Reviewed and concur.'], requestAs($verifier));

    expect($verification->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);
    expect($verification->verified_by)->toBe($verifier->id);
    expect($verification->verified_at)->not->toBeNull();
    expect($verification->verification_notes)->toBe('Reviewed and concur.');
});

test('a prescriber cannot verify their own prescription by default', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    expect(fn () => app(PrescriptionVerificationService::class)->verify($order, [], requestAs($ctx['doctor']['user'])))
        ->toThrow(ValidationException::class);

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('self-verification is allowed when hospital policy explicitly enables it', function () {
    config(['pharmacy.allow_self_verification' => true]);

    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    $verification = app(PrescriptionVerificationService::class)->verify($order, [], requestAs($ctx['doctor']['user']));

    expect($verification->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);
});

test('a verified prescription cannot be verified again', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');
    $service = app(PrescriptionVerificationService::class);

    $service->verify($order, [], requestAs($verifier));

    expect(fn () => $service->verify($order->fresh(), [], requestAs($verifier)))
        ->toThrow(ValidationException::class);
});

test('a pending prescription can be returned for correction, requiring a reason', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $returned = app(PrescriptionVerificationService::class)->returnForCorrection($order, [
        'return_reason' => 'Quantity does not match prescribed duration.',
    ], requestAs($verifier));

    expect($returned->status)->toBe(PrescriptionVerification::STATUS_RETURNED);
    expect($returned->returned_by)->toBe($verifier->id);
    expect($returned->returned_at)->not->toBeNull();
    expect($returned->return_reason)->toBe('Quantity does not match prescribed duration.');
});

test('a returned prescription can be corrected and resubmitted, and verified on the second review', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);
    $verifier = userWithRole('pharmacist');
    $service = app(PrescriptionVerificationService::class);

    $service->returnForCorrection($order, ['return_reason' => 'Dose looks too high for this patient.'], requestAs($verifier));
    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_RETURNED);

    // The prescriber corrects the order through the ordinary update path.
    app(PrescriptionOrderService::class)->update($order, [
        'clinical_notes' => 'Dose corrected per pharmacy feedback.',
    ], requestAs($ctx['doctor']['user']));

    $resubmitted = $service->resubmit($order->fresh(), requestAs($ctx['doctor']['user']));
    expect($resubmitted->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
    // Return history remains visible even after resubmission.
    expect($resubmitted->return_reason)->toBe('Dose looks too high for this patient.');

    $verified = $service->verify($order->fresh(), [], requestAs($verifier));
    expect($verified->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);

    // No duplicate verification row was ever created for this order.
    expect(PrescriptionVerification::query()->where('prescription_order_id', $order->id)->count())->toBe(1);
});

test('a verified prescription cannot be edited through the normal order update route', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($verifier));

    expect(fn () => app(PrescriptionOrderService::class)->update($order->fresh(), ['clinical_notes' => 'tampered'], requestAs($verifier)))
        ->toThrow(ValidationException::class);
});

test('reviewing a prescription opened for verification only logs an audit event when it is still pending', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');
    $service = app(PrescriptionVerificationService::class);

    $service->recordReviewStarted($order->verification, requestAs($verifier));
    expect(AuditLog::query()->where('action', 'prescription_review_started')->count())->toBe(1);

    $verified = $service->verify($order->fresh(), [], requestAs($verifier));

    // Re-opening an already-decided prescription does not add another entry.
    $service->recordReviewStarted($verified, requestAs($verifier));
    expect(AuditLog::query()->where('action', 'prescription_review_started')->count())->toBe(1);
});

test('cancelling the underlying order prevents any further verification action', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);
    $verifier = userWithRole('pharmacist');

    app(PrescriptionOrderService::class)->cancel($order, 'Patient allergic reaction discovered', requestAs($ctx['doctor']['user']));

    expect(fn () => app(PrescriptionVerificationService::class)->verify($order->fresh(), [], requestAs($verifier)))
        ->toThrow(ValidationException::class);
});
