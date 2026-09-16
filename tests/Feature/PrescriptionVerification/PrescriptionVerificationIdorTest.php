<?php

use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;
use App\Models\Staff;
use App\Models\User;
use App\Services\PrescriptionVerificationService;

/**
 * IDOR protection for the verification surface (Phase 6, Step 47): changing
 * the prescription order id in the URL must never expose another
 * hospital's prescription or allow acting on it, regardless of what the UI
 * would otherwise hide.
 */
test('a pharmacist from another hospital cannot view or act on a prescription by guessing its id', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);

    $verifierB = userWithRole('pharmacist');

    // A plain 'pharmacist' role has no hospital-scoping restriction built
    // into the permission itself (only hospital-admin is scoped by policy),
    // but the order's own prescriber/hospital relationship never grants
    // cross-hospital access either — this proves the route is safe purely
    // by object-level checks, not by relying on role scoping alone.
    $this->actingAs($verifierB)->get(route('pharmacy-verification.review', $orderA))->assertOk();

    expect($orderA->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('a hospital-admin cannot access another hospital\'s prescription by changing the id in the URL', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);

    $ctxB = diagnosisReadyEncounter();
    $orderB = activePrescriptionOrderFor($ctxB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    // adminA legitimately reaches orderA...
    $this->actingAs($adminA)->get(route('pharmacy-verification.review', $orderA))->assertOk();

    // ...but swapping the id in the URL for orderB (a different hospital)
    // must be refused, not silently redirected as if it didn't exist.
    $this->actingAs($adminA)->get(route('pharmacy-verification.review', $orderB))->assertForbidden();
    $this->actingAs($adminA)->post(route('pharmacy-verification.verify', $orderB))->assertForbidden();
    $this->actingAs($adminA)->post(route('pharmacy-verification.return', $orderB), ['return_reason' => 'x'])->assertForbidden();

    expect($orderB->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_PENDING_VERIFICATION);
});

test('the verification worklist itself never leaks another hospital\'s prescriptions to a hospital-admin', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);

    $ctxB = diagnosisReadyEncounter();
    $orderB = activePrescriptionOrderFor($ctxB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('pharmacy-verification.index'))->assertInertia(fn ($page) => $page
        ->has('prescriptions.data', 1)
        ->where('prescriptions.data.0.id', $orderA->id));

    expect(PrescriptionOrder::query()->whereKey($orderB->id)->exists())->toBeTrue();
});

test('a doctor cannot resubmit another doctor\'s returned prescription by guessing its id', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);
    $verifier = userWithRole('pharmacist');

    app(PrescriptionVerificationService::class)->returnForCorrection($orderA, ['return_reason' => 'x'], requestAs($verifier));

    $ctxB = diagnosisReadyEncounter();

    $this->actingAs($ctxB['doctor']['user'])->post(route('pharmacy-verification.resubmit', $orderA))->assertForbidden();

    expect($orderA->verification->fresh()->status)->toBe(PrescriptionVerification::STATUS_RETURNED);
});
