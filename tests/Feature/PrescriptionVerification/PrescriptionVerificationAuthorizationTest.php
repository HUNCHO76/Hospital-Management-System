<?php

use App\Models\PrescriptionVerification;
use App\Models\Staff;
use App\Models\User;
use App\Services\PrescriptionVerificationService;

test('a guest is redirected to login for the verification worklist and every mutation', function () {
    $order = activePrescriptionOrderFor();

    $this->get(route('pharmacy-verification.index'))->assertRedirect(route('login'));
    $this->get(route('pharmacy-verification.review', $order))->assertRedirect(route('login'));
    $this->post(route('pharmacy-verification.verify', $order))->assertRedirect(route('login'));
    $this->post(route('pharmacy-verification.return', $order), ['return_reason' => 'x'])->assertRedirect(route('login'));
    $this->post(route('pharmacy-verification.resubmit', $order))->assertRedirect(route('login'));
});

test('a doctor cannot view or act on the verification worklist', function () {
    $order = activePrescriptionOrderFor();
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('pharmacy-verification.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('pharmacy-verification.review', $order))->assertForbidden();
    $this->actingAs($doctor)->post(route('pharmacy-verification.verify', $order))->assertForbidden();
});

test('the prescriber cannot verify or return their own submitted prescription', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    // Grant the prescriber's own doctor role the verification permissions
    // directly, proving the block below is the self-verification identity
    // check itself, not merely a missing pharmacy-verification permission.
    $ctx['doctor']['user']->givePermissionTo(['pharmacy-verification.view', 'pharmacy-verification.review', 'pharmacy-verification.verify', 'pharmacy-verification.return']);

    $this->actingAs($ctx['doctor']['user'])->get(route('pharmacy-verification.review', $order))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.verify', $order))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.return', $order), ['return_reason' => 'x'])->assertForbidden();

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('a permission-less user cannot view or act on the verification worklist', function () {
    $order = activePrescriptionOrderFor();
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('pharmacy-verification.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy-verification.review', $order))->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-verification.verify', $order))->assertForbidden();
});

test('a pharmacist can view, verify, and return prescriptions', function () {
    $order = activePrescriptionOrderFor();
    $verifier = userWithRole('pharmacist');

    $this->actingAs($verifier)->get(route('pharmacy-verification.index'))->assertOk();
    $this->actingAs($verifier)->get(route('pharmacy-verification.review', $order))->assertOk();

    $this->actingAs($verifier)->post(route('pharmacy-verification.verify', $order), [
        'verification_notes' => 'Concur.',
    ])->assertRedirect();

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);
});

test('a hospital-admin can verify within their own hospital but not another', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);

    $ctxB = diagnosisReadyEncounter();
    $orderB = activePrescriptionOrderFor($ctxB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('pharmacy-verification.review', $orderA))->assertOk();
    $this->actingAs($adminA)->post(route('pharmacy-verification.verify', $orderA))->assertRedirect();

    // IDOR: hospital B's prescription must never be reachable by hospital A's admin.
    $this->actingAs($adminA)->get(route('pharmacy-verification.review', $orderB))->assertForbidden();
    $this->actingAs($adminA)->post(route('pharmacy-verification.verify', $orderB))->assertForbidden();

    expect($orderB->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('a super-admin bypasses prescription verification permission checks', function () {
    $order = activePrescriptionOrderFor();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('pharmacy-verification.verify', $order))->assertRedirect();

    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_VERIFIED);
});

test('only the prescriber (or a hospital-admin) can resubmit a returned prescription', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);
    $verifier = userWithRole('pharmacist');

    app(PrescriptionVerificationService::class)->returnForCorrection($order, ['return_reason' => 'x'], requestAs($verifier));

    // The verifying pharmacist is not the prescriber and cannot resubmit.
    $this->actingAs($verifier)->post(route('pharmacy-verification.resubmit', $order))->assertForbidden();

    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.resubmit', $order))->assertRedirect();
    expect($order->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});
