<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\LaboratoryOrderService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the laboratory dashboard, order detail, and encounter-nested actions', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    $this->get(route('laboratory.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory.orders.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory.orders.show', $order))->assertRedirect(route('login'));
    $this->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a nurse cannot create a laboratory order despite clinical-encounters access', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertForbidden();

    $this->assertDatabaseCount('laboratory_orders', 0);
});

test('a doctor can create and cancel their own laboratory order over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertRedirect();

    $order = LaboratoryOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.laboratory-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'Ordered in error'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_CANCELLED);
});

test('a doctor cannot create or cancel laboratory orders on another doctor\'s consultation', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorB = doctorWithPractitioner($ctx['hospital']);
    $test = activeLaboratoryTest();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    $this->actingAs($doctorB['user'])->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertForbidden();

    $this->actingAs($doctorB['user'])->post(
        route('clinical-encounters.laboratory-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'hijacked'],
    )->assertForbidden();

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
});

test('a doctor with full treatment.* but no laboratory-orders.create cannot order a lab test', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    $limitedDoctor = User::factory()->create();
    seedRolesAndPermissions();
    $limitedDoctor->givePermissionTo(['treatment.view', 'treatment.create', 'treatment.update', 'treatment.cancel']);
    $staff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id, 'user_id' => $limitedDoctor->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'doctor', 'status' => 'active']);
    $ctx['encounter']->consultation->update(['practitioner_id' => $staff->fresh()->practitioner->id]);

    $this->actingAs($limitedDoctor)->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertForbidden();

    $this->assertDatabaseCount('laboratory_orders', 0);
});

test('a laboratory technician can view the dashboard, worklist, and any order but cannot create or cancel one', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->get(route('laboratory.index'))->assertOk();
    $this->actingAs($technician)->get(route('laboratory.orders.index'))->assertOk();
    $this->actingAs($technician)->get(route('laboratory.orders.show', $order))->assertOk();

    $this->actingAs($technician)->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertForbidden();
});

test('a receptionist cannot view the laboratory dashboard, worklist, or any order', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));

    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('laboratory.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.orders.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.orders.show', $order))->assertForbidden();
});

test('a hospital-admin can act on laboratory orders within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $test = activeLaboratoryTest();

    [$encounterA, $doctorA] = laboratoryReadyEncounterFor($hospitalA);
    [$encounterB, $doctorB] = laboratoryReadyEncounterFor($hospitalB);

    $orderA = app(LaboratoryOrderService::class)->create($encounterA, ['test_ids' => [$test->id]], requestAs($doctorA['user']));
    $orderB = app(LaboratoryOrderService::class)->create($encounterB, ['test_ids' => [$test->id]], requestAs($doctorB['user']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.orders.show', $orderA))->assertOk();
    $this->actingAs($adminA)->get(route('laboratory.orders.show', $orderB))->assertForbidden();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.laboratory-orders.cancel', [$encounterA, $orderA]),
        ['cancellation_reason' => 'Admin cancel'],
    )->assertRedirect();

    $this->actingAs($adminA)->post(
        route('clinical-encounters.laboratory-orders.cancel', [$encounterB, $orderB]),
        ['cancellation_reason' => 'Cross-hospital attempt'],
    )->assertForbidden();
});

test('a laboratory order cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = diagnosisReadyEncounter();
    $ctxB = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    $order = app(LaboratoryOrderService::class)->create($ctxA['encounter'], ['test_ids' => [$test->id]], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])->post(
        route('clinical-encounters.laboratory-orders.cancel', [$ctxB['encounter'], $order]),
        ['cancellation_reason' => 'x'],
    )->assertNotFound();
});

test('a super-admin bypasses laboratory-order permission checks and hospital/ownership scoping', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('clinical-encounters.laboratory-orders.store', $ctx['encounter']), [
        'test_ids' => [$test->id],
    ])->assertRedirect();

    $this->assertDatabaseCount('laboratory_orders', 1);
});

/**
 * Build a laboratory-order-ready encounter (consultation started, no
 * diagnosis) under a specific hospital, returning the encounter and its
 * doctor — needed for cross-hospital tests.
 *
 * @return array{0: ClinicalEncounter, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function laboratoryReadyEncounterFor(Hospital $hospital): array
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
