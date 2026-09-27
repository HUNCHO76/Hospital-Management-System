<?php

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Models\VitalSign;
use App\Services\AdmissionService;
use App\Services\ClinicalEncounterService;
use App\Services\InpatientClinicalService;
use App\Services\VitalSignsService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// 1. Opening an inpatient encounter
// ---------------------------------------------------------------------------

test('opening an inpatient encounter creates a ClinicalEncounter linked to the admission', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];
    $user = userWithRole('doctor');

    $encountersBefore = ClinicalEncounter::count();

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($user),
    );

    expect(ClinicalEncounter::count())->toBe($encountersBefore + 1);
    expect($encounter->encounter_type)->toBe('inpatient');
    expect($encounter->admission_id)->toBe($admission->id);
    expect($encounter->patient_id)->toBe($admission->patient_id);
    expect($encounter->status)->toBe(ClinicalEncounter::STATUS_IN_PROGRESS);
});

test('opening an inpatient encounter on a non-active admission is rejected', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];
    $user = userWithRole('doctor');

    // Discharge the admission first
    app(AdmissionService::class)->discharge($admission, [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Recovered',
    ], requestAs($ctx['admin']));

    app(InpatientClinicalService::class)->openEncounter(
        $admission->fresh(),
        $practitioner->id,
        requestAs($user),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 2. Recording inpatient vitals
// ---------------------------------------------------------------------------

test('vital signs can be recorded against an active inpatient encounter', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];
    $user = userWithRole('nurse');

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($user),
    );

    $vitalsBefore = VitalSign::count();

    $vital = app(VitalSignsService::class)->recordForAdmission(
        $admission,
        $encounter,
        [
            'temperature_c' => 37.2,
            'pulse_rate' => 82,
            'blood_pressure_systolic' => 120,
            'blood_pressure_diastolic' => 80,
            'respiratory_rate' => 16,
            'oxygen_saturation' => 98,
            'weight_kg' => 70,
            'height_cm' => 175,
        ],
        requestAs($user),
    );

    expect(VitalSign::count())->toBe($vitalsBefore + 1);
    expect($vital->admission_id)->toBe($admission->id);
    expect($vital->triage_assessment_id)->toBeNull();
    expect($vital->clinical_encounter_id)->toBe($encounter->id);
});

test('vital signs recording is rejected when the encounter does not belong to the admission', function () {
    $ctx = activeAdmission();
    $ctx2 = activeAdmission();
    $user = userWithRole('nurse');

    // Get an encounter from a different admission
    $wrongEncounter = app(InpatientClinicalService::class)->openEncounter(
        $ctx2['admission'],
        $ctx2['practitioner']->id,
        requestAs($user),
    );

    app(VitalSignsService::class)->recordForAdmission(
        $ctx['admission'],
        $wrongEncounter,
        ['temperature_c' => 37.0],
        requestAs($user),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 3. Updating clinical status
// ---------------------------------------------------------------------------

test('clinical status can be updated on an active admission', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $user = userWithRole('nurse');

    app(InpatientClinicalService::class)->updateClinicalStatus(
        $admission,
        'critical',
        requestAs($user),
    );

    expect($admission->fresh()->clinical_status)->toBe('critical');
});

test('invalid clinical status is rejected', function () {
    $ctx = activeAdmission();
    $user = userWithRole('nurse');

    app(InpatientClinicalService::class)->updateClinicalStatus(
        $ctx['admission'],
        'flying_spaghetti_monster',
        requestAs($user),
    );
})->throws(ValidationException::class);

test('clinical status update on a discharged admission is rejected', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];

    app(AdmissionService::class)->discharge($admission, [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Recovered',
    ], requestAs($ctx['admin']));

    app(InpatientClinicalService::class)->updateClinicalStatus(
        $admission->fresh(),
        'stable',
        requestAs($ctx['admin']),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 4. Opening a concurrent OPD encounter is still allowed while admitted
// ---------------------------------------------------------------------------

test('a patient can have an active OPD encounter while an inpatient encounter is open', function () {
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $user = userWithRole('doctor');

    // Open an inpatient encounter
    app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $ctx['practitioner']->id,
        requestAs($user),
    );

    // The ClinicalEncounterService::findActiveEncounter() must return null
    // for non-inpatient contexts so an OPD encounter can be created.
    $service = app(ClinicalEncounterService::class);
    $activeOpd = $service->findActiveEncounter($admission->patient_id);

    expect($activeOpd)->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. HTTP — ward dashboard returns 200 for authorised user
// ---------------------------------------------------------------------------

test('ward dashboard is accessible to users with admission.view', function () {
    $user = userWithRole('nurse');

    $response = $this->actingAs($user)->get(route('inpatient.dashboard'));

    $response->assertOk();
});

test('ward dashboard is forbidden for unauthenticated users', function () {
    $response = $this->get(route('inpatient.dashboard'));

    $response->assertRedirectToRoute('login');
});

// ---------------------------------------------------------------------------
// 6. HTTP — clinical profile returns 200 for active admission
// ---------------------------------------------------------------------------

test('clinical profile page loads for an active admission', function () {
    $ctx = activeAdmission();
    $user = userWithRole('nurse');

    $response = $this->actingAs($user)->get(route('inpatient.clinical-profile', $ctx['admission']));

    $response->assertOk();
});

test('clinical profile of another hospital admission is forbidden for hospital-admin', function () {
    $ctx = activeAdmission();
    $otherHospital = Hospital::factory()->create();
    $otherStaff = Staff::factory()->create(['hospital_id' => $otherHospital->id]);
    $admin = User::factory()->create();
    $adminStaff = Staff::factory()->create(['hospital_id' => $otherHospital->id, 'user_id' => $admin->id]);
    $admin->assignRole('hospital-admin');

    $response = $this->actingAs($admin)->get(route('inpatient.clinical-profile', $ctx['admission']));

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// 7. Inpatient encounter does NOT create a new Patient row
// ---------------------------------------------------------------------------

test('opening an inpatient encounter never creates a new patient', function () {
    $ctx = activeAdmission();
    $patientsBefore = Patient::count();

    app(InpatientClinicalService::class)->openEncounter(
        $ctx['admission'],
        $ctx['practitioner']->id,
        requestAs(userWithRole('doctor')),
    );

    expect(Patient::count())->toBe($patientsBefore);
});
