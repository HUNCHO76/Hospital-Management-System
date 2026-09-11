<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\RadiologyOrder;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\RadiologyOrderService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the radiology dashboard, order detail, and encounter-nested actions', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    $this->get(route('radiology.index'))->assertRedirect(route('login'));
    $this->get(route('radiology.orders.index'))->assertRedirect(route('login'));
    $this->get(route('radiology.orders.show', $order))->assertRedirect(route('login'));
    $this->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a nurse cannot create a radiology order despite clinical-encounters access', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertForbidden();

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('a doctor can create and cancel their own radiology order over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertRedirect();

    $order = RadiologyOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'Ordered in error'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_CANCELLED);
});

test('a doctor cannot create or cancel radiology orders on another doctor\'s consultation', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorB = doctorWithPractitioner($ctx['hospital']);
    $procedure = activeRadiologyProcedure();

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    $this->actingAs($doctorB['user'])->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertForbidden();

    $this->actingAs($doctorB['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'hijacked'],
    )->assertForbidden();

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);
});

test('a doctor with full treatment.* but no radiology-orders.create cannot order a radiology procedure', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $limitedDoctor = User::factory()->create();
    seedRolesAndPermissions();
    $limitedDoctor->givePermissionTo(['treatment.view', 'treatment.create', 'treatment.update', 'treatment.cancel']);
    $staff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id, 'user_id' => $limitedDoctor->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'doctor', 'status' => 'active']);
    $ctx['encounter']->consultation->update(['practitioner_id' => $staff->fresh()->practitioner->id]);

    $this->actingAs($limitedDoctor)->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertForbidden();

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('a radiology technician can view the dashboard, worklist, and any order but cannot create or cancel one', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->get(route('radiology.index'))->assertOk();
    $this->actingAs($technician)->get(route('radiology.orders.index'))->assertOk();
    $this->actingAs($technician)->get(route('radiology.orders.show', $order))->assertOk();

    $this->actingAs($technician)->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertForbidden();
});

test('a receptionist cannot view the radiology dashboard, worklist, or any order', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctx['doctor']['user']));

    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('radiology.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('radiology.orders.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('radiology.orders.show', $order))->assertForbidden();
});

test('a hospital-admin can act on radiology orders within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $procedure = activeRadiologyProcedure();

    [$encounterA, $doctorA] = radiologyReadyEncounterFor($hospitalA);
    [$encounterB, $doctorB] = radiologyReadyEncounterFor($hospitalB);

    $orderA = app(RadiologyOrderService::class)->create($encounterA, ['procedure_ids' => [$procedure->id]], requestAs($doctorA['user']));
    $orderB = app(RadiologyOrderService::class)->create($encounterB, ['procedure_ids' => [$procedure->id]], requestAs($doctorB['user']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('radiology.orders.show', $orderA))->assertOk();
    $this->actingAs($adminA)->get(route('radiology.orders.show', $orderB))->assertForbidden();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.radiology-orders.cancel', [$encounterA, $orderA]),
        ['cancellation_reason' => 'Admin cancel'],
    )->assertRedirect();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.radiology-orders.cancel', [$encounterB, $orderB]),
        ['cancellation_reason' => 'Cross-hospital attempt'],
    )->assertForbidden();
});

test('a radiology order cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = diagnosisReadyEncounter();
    $ctxB = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $order = app(RadiologyOrderService::class)->create($ctxA['encounter'], ['procedure_ids' => [$procedure->id]], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$ctxB['encounter'], $order]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('a super-admin bypasses radiology-order permission checks and hospital/ownership scoping', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
    ])->assertRedirect();

    $this->assertDatabaseCount('radiology_orders', 1);
});

/**
 * Build a radiology-order-ready encounter (consultation started, no
 * diagnosis) under a specific hospital, returning the encounter and its
 * doctor — needed for cross-hospital tests. Mirrors
 * laboratoryReadyEncounterFor() exactly.
 *
 * @return array{0: ClinicalEncounter, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function radiologyReadyEncounterFor(Hospital $hospital): array
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
