<?php

use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;

/**
 * The full Step 47 pathway (spec §29): doctor writes a prescription ->
 * automatically enters the pharmacy verification queue -> pharmacist opens
 * the queue -> reviews the prescription -> returns it -> doctor sees the
 * return reason -> corrects the order -> resubmits -> pharmacist reviews
 * again -> verifies -> ELIGIBLE FOR DISPENSING. Also covers the successful
 * path with no return, and the self-verification rejection.
 */
test('the full pathway from a submitted prescription through a return-correct-resubmit cycle to VERIFIED', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['generic_name' => 'Paracetamol', 'code' => 'PCM-VER-E2E']);

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '500',
            'dose_unit' => 'mg',
            'frequency' => 'three_times_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
        ]],
        'clinical_notes' => 'For fever.',
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->sole();
    expect($order->verification->status)->toBe('pending_verification');

    $pharmacist = userWithRole('pharmacist');

    // The pharmacist's queue surfaces it as pending verification.
    $this->actingAs($pharmacist)->get(route('pharmacy-verification.index'))
        ->assertInertia(fn ($page) => $page
            ->has('prescriptions.data', 1)
            ->where('prescriptions.data.0.id', $order->id)
            ->where('prescriptions.data.0.verification.status', 'pending_verification'));

    // The pharmacist opens the prescription and reviews the full context.
    $this->actingAs($pharmacist)->get(route('pharmacy-verification.review', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('pharmacy/verification/show')
            ->where('prescription.patient.id', $ctx['patient']->id)
            ->where('prescription.items.0.medication_name_snapshot', 'Paracetamol')
            ->where('canVerify', true)
            ->where('canReturn', true));

    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_review_started', 'auditable_id' => $order->verification->id]);

    // The pharmacist is not satisfied and returns it for correction.
    $this->actingAs($pharmacist)->post(route('pharmacy-verification.return', $order), [
        'return_reason' => 'Quantity does not match prescribed duration.',
    ])->assertRedirect();

    $returned = $order->verification->fresh();
    expect($returned->status)->toBe('returned');
    expect($returned->returned_by)->toBe($pharmacist->id);

    // The doctor sees the return reason on the encounter page.
    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertInertia(fn ($page) => $page
            ->where('prescriptionOrders.0.verification.status', 'returned')
            ->where('prescriptionOrders.0.verification.return_reason', 'Quantity does not match prescribed duration.'));

    // The doctor corrects the order and resubmits it.
    $this->actingAs($ctx['doctor']['user'])->put(route('clinical-encounters.prescription-orders.update', [$ctx['encounter'], $order]), [
        'clinical_notes' => 'Quantity corrected to a 5-day course.',
    ])->assertRedirect();

    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.resubmit', $order))->assertRedirect();
    expect($order->verification->fresh()->status)->toBe('pending_verification');

    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_resubmitted', 'auditable_id' => $order->verification->id]);

    // The pharmacist reviews again and this time approves it.
    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order), [
        'verification_notes' => 'Corrected quantity reviewed and approved.',
    ])->assertRedirect();

    $verified = $order->verification->fresh();
    expect($verified->status)->toBe('verified');
    expect($verified->verified_by)->toBe($pharmacist->id);
    expect($verified->verified_at)->not->toBeNull();

    // No duplicate verification row was ever created across the whole cycle.
    expect(PrescriptionVerification::query()->where('prescription_order_id', $order->id)->count())->toBe(1);

    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_returned', 'auditable_id' => $order->verification->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_verified', 'auditable_id' => $order->verification->id]);

    // Once VERIFIED, the prescription is immutable through the normal edit route.
    $this->actingAs($ctx['doctor']['user'])->put(route('clinical-encounters.prescription-orders.update', [$ctx['encounter'], $order]), [
        'clinical_notes' => 'tampered',
    ])->assertSessionHasErrors('status');
    expect($order->fresh()->clinical_notes)->not->toBe('tampered');
});

test('the successful path with no return: eligible for dispensing straight away', function () {
    $order = activePrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy-verification.review', $order))->assertOk();

    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order))->assertRedirect();

    expect($order->verification->fresh()->status)->toBe('verified');
});

test('self-verification: the same doctor who wrote it cannot verify it', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    // Even if this doctor somehow held the pharmacy-verification.verify
    // permission, the identity check itself must still deny it.
    $ctx['doctor']['user']->givePermissionTo('pharmacy-verification.verify');

    $this->actingAs($ctx['doctor']['user'])->post(route('pharmacy-verification.verify', $order))->assertForbidden();

    expect($order->verification->fresh()->status)->toBe('pending_verification');
});
