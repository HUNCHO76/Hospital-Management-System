<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\DiagnosisService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for every diagnosis route', function () {
    $ctx = diagnosisReadyEncounter();

    $this->post(route('clinical-encounters.diagnoses.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a user without diagnosis.create cannot record a diagnosis', function () {
    $ctx = diagnosisReadyEncounter();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('clinical-encounters.diagnoses.store', $ctx['encounter']), [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ])->assertForbidden();

    $this->assertDatabaseCount('encounter_diagnoses', 0);
});

test('a receptionist cannot record a diagnosis despite reception access to the encounter', function () {
    $ctx = diagnosisReadyEncounter();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->post(route('clinical-encounters.diagnoses.store', $ctx['encounter']), [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ])->assertForbidden();

    $this->assertDatabaseCount('encounter_diagnoses', 0);
});

test('a doctor cannot record, update, or promote diagnoses on another doctor\'s consultation', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorB = doctorWithPractitioner($ctx['hospital']);

    $diagnosis = app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($ctx['doctor']['user']));

    $this->actingAs($doctorB['user'])->post(route('clinical-encounters.diagnoses.store', $ctx['encounter']), [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'secondary',
    ])->assertForbidden();

    $this->actingAs($doctorB['user'])
        ->put(route('clinical-encounters.diagnoses.update', [$ctx['encounter'], $diagnosis]), ['clinical_note' => 'hijacked'])
        ->assertForbidden();

    expect($diagnosis->fresh()->clinical_note)->toBeNull();
    $this->assertDatabaseCount('encounter_diagnoses', 1);
});

test('a hospital-admin can act on diagnoses within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    [$encounterA, $doctorA] = diagnosisReadyEncounterFor($hospitalA);
    [$encounterB, $doctorB] = diagnosisReadyEncounterFor($hospitalB);

    $diagnosisA = app(DiagnosisService::class)->create($encounterA, [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($doctorA['user']));

    $diagnosisB = app(DiagnosisService::class)->create($encounterB, [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($doctorB['user']));

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.diagnoses.update', [$encounterA, $diagnosisA]), ['clinical_note' => 'Admin note'])
        ->assertRedirect();

    $this->actingAs($adminUser)
        ->put(route('clinical-encounters.diagnoses.update', [$encounterB, $diagnosisB]), ['clinical_note' => 'Cross-hospital attempt'])
        ->assertForbidden();
});

test('a diagnosis cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = diagnosisReadyEncounter();
    $ctxB = diagnosisReadyEncounter();

    $diagnosis = app(DiagnosisService::class)->create($ctxA['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])
        ->put(route('clinical-encounters.diagnoses.update', [$ctxB['encounter'], $diagnosis]), ['clinical_note' => 'x'])
        ->assertNotFound();
});

test('mass-assignable protected fields cannot be set through the update request', function () {
    $ctx = diagnosisReadyEncounter();

    $diagnosis = app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($ctx['doctor']['user']));

    $this->actingAs($ctx['doctor']['user'])->put(route('clinical-encounters.diagnoses.update', [$ctx['encounter'], $diagnosis]), [
        'clinical_note' => 'Legitimate note',
        'status' => 'resolved',
        'diagnosis_type' => 'secondary',
        'diagnosed_by' => 99999,
    ])->assertRedirect();

    $fresh = $diagnosis->fresh();
    expect($fresh->clinical_note)->toBe('Legitimate note');
    expect($fresh->status)->toBe('provisional');
    expect($fresh->diagnosis_type)->toBe('primary');
    expect($fresh->diagnosed_by)->toBe($ctx['doctor']['user']->id);
});

test('a super-admin bypasses diagnosis permission checks and hospital/ownership scoping', function () {
    $ctx = diagnosisReadyEncounter();
    $user = userWithRole('super-admin');

    // Gate::before bypasses EncounterDiagnosisPolicy entirely for
    // super-admin, so no practitioner profile is needed here — unlike
    // ConsultationService, DiagnosisService's create() has no practitioner
    // eligibility check of its own to satisfy.
    $this->actingAs($user)->post(route('clinical-encounters.diagnoses.store', $ctx['encounter']), [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();

    $this->assertDatabaseCount('encounter_diagnoses', 1);
});

/**
 * Build a diagnosis-ready encounter under a specific hospital, returning the
 * encounter and its doctor — a variant of diagnosisReadyEncounter() that
 * pins the hospital, needed for cross-hospital tests.
 *
 * @return array{0: ClinicalEncounter, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function diagnosisReadyEncounterFor(Hospital $hospital): array
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
