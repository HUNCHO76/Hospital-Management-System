<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\PrescriptionOrder;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\PrescriptionOrderService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for prescription encounter-nested actions', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a nurse cannot write a prescription despite clinical-encounters access', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertForbidden();

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a doctor can write and cancel their own prescription over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.prescription-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'Prescribed in error'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_CANCELLED);
});

test('a doctor cannot write or cancel prescriptions on another doctor\'s consultation', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorB = doctorWithPractitioner($ctx['hospital']);
    $medication = activeMedication();

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user']));

    $this->actingAs($doctorB['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertForbidden();

    $this->actingAs($doctorB['user'])->post(
        route('clinical-encounters.prescription-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'hijacked'],
    )->assertForbidden();

    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
});

test('a doctor with full treatment.* but no prescription-orders.create cannot write a prescription', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $limitedDoctor = User::factory()->create();
    seedRolesAndPermissions();
    $limitedDoctor->givePermissionTo(['treatment.view', 'treatment.create', 'treatment.update', 'treatment.cancel']);
    $staff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id, 'user_id' => $limitedDoctor->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'doctor', 'status' => 'active']);
    $ctx['encounter']->consultation->update(['practitioner_id' => $staff->fresh()->practitioner->id]);

    $this->actingAs($limitedDoctor)->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertForbidden();

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a pharmacist can view any prescription in the pharmacy detail page but cannot create or cancel one', function () {
    $order = activePrescriptionOrderFor();
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.show', $order))->assertOk();

    $this->actingAs($pharmacist)->post(route('clinical-encounters.prescription-orders.store', $order->clinicalEncounter), [
        'items' => [['medication_id' => activeMedication()->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertForbidden();
});

test('a receptionist cannot view a prescription detail page', function () {
    $order = activePrescriptionOrderFor();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('pharmacy.prescriptions.show', $order))->assertForbidden();
});

test('a hospital-admin can act on prescriptions within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $medication = activeMedication();

    [$encounterA, $doctorA] = prescriptionReadyEncounterFor($hospitalA);
    [$encounterB, $doctorB] = prescriptionReadyEncounterFor($hospitalB);

    $orderA = app(PrescriptionOrderService::class)->create($encounterA, [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($doctorA['user']));
    $orderB = app(PrescriptionOrderService::class)->create($encounterB, [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($doctorB['user']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy.prescriptions.show', $orderA))->assertOk();
    $this->actingAs($adminA)->get(route('pharmacy.prescriptions.show', $orderB))->assertForbidden();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.prescription-orders.cancel', [$encounterA, $orderA]),
        ['cancellation_reason' => 'Admin cancel'],
    )->assertRedirect();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.prescription-orders.cancel', [$encounterB, $orderB]),
        ['cancellation_reason' => 'Cross-hospital attempt'],
    )->assertForbidden();
});

test('a prescription order cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = diagnosisReadyEncounter();
    $ctxB = diagnosisReadyEncounter();
    $medication = activeMedication();

    $order = app(PrescriptionOrderService::class)->create($ctxA['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])->post(
        route('clinical-encounters.prescription-orders.cancel', [$ctxB['encounter'], $order]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('a prescription order item accessed through a mismatched order id in the URL is not found', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $orderA = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user']));
    $orderB = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => activeMedication()->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user']));

    $itemB = $orderB->items->sole();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.prescription-orders.items.cancel', [$ctx['encounter'], $orderA, $itemB]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('a super-admin bypasses prescription-order permission checks and hospital/ownership scoping', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertRedirect();

    $this->assertDatabaseCount('prescription_orders', 1);
});

/**
 * Build a prescription-ready encounter (consultation started, no diagnosis)
 * under a specific hospital, returning the encounter and its doctor —
 * needed for cross-hospital tests. Mirrors radiologyReadyEncounterFor()
 * exactly.
 *
 * @return array{0: ClinicalEncounter, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function prescriptionReadyEncounterFor(Hospital $hospital): array
{
    $ctx = clinicalEncounterContext($hospital);
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);

    $triageRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounter, $triageRequest);
    app(TriageService::class)->start($triage, $triageRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $triageRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $triageRequest);
    app(TriageService::class)->complete($triage->fresh(), $triageRequest);

    $doctor = doctorWithPractitioner($hospital);
    $consultationRequest = requestAs($doctor['user']);
    $consultation = app(ConsultationService::class)->create($encounter->fresh(), $consultationRequest);
    app(ConsultationService::class)->start($consultation, $consultationRequest);

    return [$encounter->fresh(), $doctor];
}
