<?php

use App\Http\Controllers\NhifClaimController;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\DiagnosisCode;
use App\Models\EncounterDiagnosis;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\NHIF\ClaimCalculationService;
use Illuminate\Http\Request;

test('claim readiness requires clinical encounter status to be completed and notifies if in progress', function () {
    $hospital = Hospital::factory()->create(['code' => 'FAC-CLAIM-01']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $patient = Patient::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'date_of_birth' => '1990-01-01',
        'gender' => 'female',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => '201627864221',
        'card_number' => '1234567890',
        'nhif_facility_code' => 'FAC-CLAIM-01',
    ]);

    $doctorUser = User::factory()->create();
    $doctorStaff = Staff::factory()->create([
        'user_id' => $doctorUser->id,
        'hospital_id' => $hospital->id,
    ]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $doctorStaff->id,
        'nhif_practitioner_no' => 'MCT-1234',
        'status' => Practitioner::STATUS_ACTIVE,
    ]);

    $diagCode = DiagnosisCode::firstOrCreate(
        ['code' => 'A09'],
        ['description' => 'Infectious gastroenteritis', 'system' => 'icd10']
    );

    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
        'encounter_type' => 'opd',
        'started_at' => now()->subHour(),
    ]);

    EncounterDiagnosis::create([
        'clinical_encounter_id' => $encounter->id,
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'status' => EncounterDiagnosis::STATUS_CONFIRMED,
        'diagnosed_by' => $doctorUser->id,
        'diagnosed_at' => now(),
        'nhif_disease_code' => 'A09',
        'nhif_disease_name' => 'Infectious gastroenteritis',
    ]);

    $calc = app(ClaimCalculationService::class);
    $readiness = $calc->validateClaimReadiness($encounter);

    // In Progress encounter must NOT be ready for claim
    expect($readiness['is_ready'])->toBeFalse();
    expect($readiness['errors'])->toContain('Doctor should complete Clinical Encounters for the patient before sending claim.');

    $servicesChecklist = collect($readiness['checklist'])->firstWhere('key', 'services_completed');
    expect($servicesChecklist['passed'])->toBeFalse()
        ->and($servicesChecklist['detail'])->toContain('Doctor should complete Clinical Encounters for the patient before sending claim');

    // Once completed, services_completed passes
    $encounter->update(['status' => ClinicalEncounter::STATUS_COMPLETED, 'ended_at' => now()]);
    $readinessCompleted = $calc->validateClaimReadiness($encounter->fresh());
    $servicesChecklistCompleted = collect($readinessCompleted['checklist'])->firstWhere('key', 'services_completed');
    expect($servicesChecklistCompleted['passed'])->toBeTrue()
        ->and($readinessCompleted['errors'])->not->toContain('Doctor should complete Clinical Encounters for the patient before sending claim.');
});

test('eligibleVisits endpoint returns is_completed false and completion_warning for in-progress encounters', function () {
    $hospital = Hospital::factory()->create(['code' => 'FAC-CLAIM-02']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => '201627864222',
        'card_number' => '1234567891',
        'nhif_facility_code' => 'FAC-CLAIM-02',
    ]);

    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
        'started_at' => now()->subHour(),
    ]);

    $user = User::factory()->create();
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $request = Request::create(route('nhif.claims.eligible-visits'), 'GET');
    $request->setUserResolver(fn () => $user);

    $controller = app(NhifClaimController::class);
    $response = $controller->eligibleVisits($request);
    $data = $response->getData(true)['data'];

    $entry = collect($data)->firstWhere('id', $encounter->id);
    expect($entry)->not->toBeNull();
    expect($entry['is_completed'])->toBeFalse();
    expect($entry['completion_warning'])->toBe('Doctor should complete Clinical Encounters for the patient before sending claim');
});
