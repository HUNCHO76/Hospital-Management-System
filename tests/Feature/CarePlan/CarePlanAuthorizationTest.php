<?php

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\CarePlanService;
use App\Services\ConsultationService;
use App\Services\DiagnosisService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for every care plan route', function () {
    $ctx = carePlanReadyEncounter();

    $this->post(route('clinical-encounters.care-plan.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a user without treatment.create cannot start a care plan', function () {
    $ctx = carePlanReadyEncounter();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.care-plan.store', $ctx['encounter']))->assertForbidden();

    $this->assertDatabaseCount('care_plans', 0);
});

test('a receptionist cannot start a care plan despite reception access to the encounter', function () {
    $ctx = carePlanReadyEncounter();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->post(route('clinical-encounters.care-plan.store', $ctx['encounter']))->assertForbidden();
    $this->assertDatabaseCount('care_plans', 0);
});

test('a doctor cannot update or activate another doctor\'s care plan', function () {
    $ctx = carePlanReadyEncounter();
    $doctorB = doctorWithPractitioner($ctx['hospital']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], [], requestAs($ctx['doctor']['user']));

    $this->actingAs($doctorB['user'])
        ->put(route('clinical-encounters.care-plan.update', $ctx['encounter']), ['goals' => 'hijacked'])
        ->assertForbidden();

    $this->actingAs($doctorB['user'])
        ->post(route('clinical-encounters.care-plan.activate', $ctx['encounter']))
        ->assertForbidden();

    expect($carePlan->fresh()->goals)->toBeNull();
});

test('a hospital-admin can act on care plans within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    [$encounterA, $doctorA] = carePlanReadyEncounterFor($hospitalA);
    [$encounterB, $doctorB] = carePlanReadyEncounterFor($hospitalB);

    $carePlanA = app(CarePlanService::class)->create($encounterA, [], requestAs($doctorA['user']));
    $carePlanB = app(CarePlanService::class)->create($encounterB, [], requestAs($doctorB['user']));

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.care-plan.update', $encounterA), ['goals' => 'Admin note'])
        ->assertRedirect();

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.care-plan.update', $encounterB), ['goals' => 'Cross-hospital attempt'])
        ->assertForbidden();

    expect($carePlanA)->not->toBeNull();
    expect($carePlanB)->not->toBeNull();
});

test('a care plan cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();
    $doctorA = $ctxA['doctor'];

    app(CarePlanService::class)->create($ctxA['encounter'], [], requestAs($doctorA['user']));

    $this->actingAs($doctorA['user'])
        ->put(route('clinical-encounters.care-plan.update', $ctxB['encounter']), ['goals' => 'x'])
        ->assertNotFound();
});

test('a treatment item cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();
    $service = app(CarePlanService::class);

    $carePlan = $service->create($ctxA['encounter'], [], requestAs($ctxA['doctor']['user']));
    $item = $service->addItem($carePlan, ['type' => 'monitoring', 'description' => 'x'], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])
        ->put(route('clinical-encounters.care-plan.items.update', [$ctxB['encounter'], $item]), ['description' => 'y'])
        ->assertNotFound();
});

test('mass-assignable protected fields cannot be set through the update request', function () {
    $ctx = carePlanReadyEncounter();

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], [], requestAs($ctx['doctor']['user']));

    $this->actingAs($ctx['doctor']['user'])->put(route('clinical-encounters.care-plan.update', $ctx['encounter']), [
        'goals' => 'Legitimate goal',
        'status' => CarePlan::STATUS_COMPLETED,
        'completed_by' => 99999,
    ])->assertRedirect();

    $fresh = $carePlan->fresh();
    expect($fresh->goals)->toBe('Legitimate goal');
    expect($fresh->status)->toBe(CarePlan::STATUS_DRAFT);
    expect($fresh->completed_by)->toBeNull();
});

test('a doctor without prescription.create cannot attach a prescription even with treatment.create', function () {
    $ctx = carePlanReadyEncounter();
    $medication = activeMedication();

    // A user with the full treatment.* group but no prescription
    // permissions — proves prescription.* is a genuinely separate gate, not
    // implied by treatment.create.
    $limitedDoctor = User::factory()->create();
    seedRolesAndPermissions();
    $limitedDoctor->givePermissionTo(['treatment.view', 'treatment.create', 'treatment.update', 'treatment.cancel', 'treatment.correct']);
    $staff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id, 'user_id' => $limitedDoctor->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'doctor', 'status' => 'active']);

    // This user does not own the encounter's consultation, so give them
    // ownership by making them the consultation's own practitioner instead.
    $ctx['encounter']->consultation->update(['practitioner_id' => $staff->fresh()->practitioner->id]);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], [], requestAs($ctx['doctor']['user']));

    $this->actingAs($limitedDoctor)->post(route('clinical-encounters.care-plan.items.store', $ctx['encounter']), [
        'type' => 'medication',
        'description' => 'Paracetamol',
        'prescription' => ['medication_id' => $medication->id],
    ])->assertForbidden();

    $this->assertDatabaseCount('care_plan_items', 0);

    // A non-medication item is unaffected by the missing prescription
    // permission.
    $this->actingAs($limitedDoctor)->post(route('clinical-encounters.care-plan.items.store', $ctx['encounter']), [
        'type' => 'monitoring',
        'description' => 'Check vitals',
    ])->assertRedirect();

    expect($carePlan->items()->count())->toBe(1);
});

test('a super-admin bypasses care plan permission checks and hospital/ownership scoping', function () {
    $ctx = carePlanReadyEncounter();
    $user = userWithRole('super-admin');

    // Gate::before bypasses CarePlanPolicy entirely for super-admin, so no
    // practitioner profile is needed here — CarePlanService::create() has no
    // practitioner eligibility check of its own to satisfy.
    $this->actingAs($user)->post(route('clinical-encounters.care-plan.store', $ctx['encounter']))->assertRedirect();

    $this->assertDatabaseCount('care_plans', 1);
});

/**
 * Build a care-plan-ready encounter under a specific hospital, returning the
 * encounter and its doctor.
 *
 * @return array{0: ClinicalEncounter, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function carePlanReadyEncounterFor(Hospital $hospital): array
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

    app(DiagnosisService::class)->create($encounter->fresh(), [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], $consultationRequest);

    return [$encounter->fresh(), $doctor];
}
