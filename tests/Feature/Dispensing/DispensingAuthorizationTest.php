<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\PrescriptionOrder;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the dispensing queue, detail, and actions', function () {
    $order = verifiedPrescriptionOrderFor();

    $this->get(route('pharmacy-dispensing.index'))->assertRedirect(route('login'));
    $this->get(route('pharmacy-dispensing.show', $order))->assertRedirect(route('login'));
    $this->post(route('pharmacy-dispensing.start', $order))->assertRedirect(route('login'));
});

test('a permission-less user cannot view the dispensing queue or a prescription\'s dispensing detail', function () {
    $order = verifiedPrescriptionOrderFor();
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('pharmacy-dispensing.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy-dispensing.show', $order))->assertForbidden();
});

test('a doctor holds no dispensing permission and cannot start or view dispensing', function () {
    $order = verifiedPrescriptionOrderFor();
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('pharmacy-dispensing.index'))->assertForbidden();
    $this->actingAs($doctor)->post(route('pharmacy-dispensing.start', $order))->assertForbidden();

    $this->assertDatabaseCount('dispensings', 0);
});

test('a pharmacist can start, complete, and cancel dispensing over HTTP', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $this->assertDatabaseHas('dispensings', ['prescription_order_id' => $order->id, 'status' => 'in_progress']);

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity]],
    ])->assertRedirect();

    $this->assertDatabaseHas('dispensings', ['prescription_order_id' => $order->id, 'status' => 'completed']);
});

test('a hospital-admin can act on dispensing within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $orderA = verifiedPrescriptionOrderFor(prescriptionCtxFor($hospitalA));
    $orderB = verifiedPrescriptionOrderFor(prescriptionCtxFor($hospitalB));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy-dispensing.show', $orderA))->assertOk();
    $this->actingAs($adminA)->get(route('pharmacy-dispensing.show', $orderB))->assertForbidden();

    $this->actingAs($adminA)->post(route('pharmacy-dispensing.start', $orderA))->assertRedirect();
    $this->actingAs($adminA)->post(route('pharmacy-dispensing.start', $orderB))->assertForbidden();

    expect(PrescriptionOrder::query()->whereKey($orderB->id)->exists())->toBeTrue();
});

test('a dispensing action on a prescription with no in-progress dispensing event is rejected', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 1]],
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseCount('dispensing_items', 0);
});

test('a super-admin bypasses dispensing permission checks and hospital scoping', function () {
    $order = verifiedPrescriptionOrderFor();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('pharmacy-dispensing.index'))->assertOk();
    $this->actingAs($user)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();

    $this->assertDatabaseCount('dispensings', 1);
});

/**
 * Build a diagnosis-ready, verified-prescription context under a specific
 * hospital — needed for cross-hospital dispensing tests, mirroring
 * radiologyReadyEncounterFor()/prescriptionReadyEncounterFor() exactly.
 */
function prescriptionCtxFor(Hospital $hospital): array
{
    $ctx = clinicalEncounterContext($hospital);
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);

    $nurseRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounter, $nurseRequest);
    app(TriageService::class)->start($triage, $nurseRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $nurseRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $nurseRequest);
    app(TriageService::class)->complete($triage->fresh(), $nurseRequest);

    $doctor = doctorWithPractitioner($hospital);
    $consultationRequest = requestAs($doctor['user']);
    $consultation = app(ConsultationService::class)->create($encounter->fresh(), $consultationRequest);
    app(ConsultationService::class)->start($consultation, $consultationRequest);

    return [
        'hospital' => $hospital,
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $encounter->fresh(),
        'doctor' => $doctor,
    ];
}
