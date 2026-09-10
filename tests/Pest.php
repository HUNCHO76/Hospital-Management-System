<?php

use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\DiagnosisCode;
use App\Models\EncounterDiagnosis;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\TriageAssessment;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\DiagnosisService;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;
use App\Services\LaboratorySampleService;
use App\Services\TriageService;
use App\Services\VitalSignsService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Models', 'Unit/Concerns');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed the HMS permission catalog and role/permission matrix.
 */
function seedRolesAndPermissions(): void
{
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
}

/**
 * Create a user assigned the given HMS role, with permissions seeded.
 */
function userWithRole(string $role): User
{
    seedRolesAndPermissions();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Create a self-consistent department + practitioner (with staff) pair under
 * the same hospital, plus a patient — the minimum valid context for booking
 * an appointment in tests.
 *
 * @return array{hospital: Hospital, department: Department, practitioner: Practitioner, patient: Patient}
 */
function appointmentBookingContext(?Hospital $hospital = null): array
{
    $hospital ??= Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();

    return compact('hospital', 'department', 'practitioner', 'patient');
}

/**
 * Create a self-consistent department + practitioner (with staff) pair under
 * the same hospital, plus an active patient — the minimum valid context for
 * opening a clinical encounter in tests.
 *
 * @return array{hospital: Hospital, department: Department, practitioner: Practitioner, patient: Patient}
 */
function clinicalEncounterContext(?Hospital $hospital = null): array
{
    $hospital ??= Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create(['status' => Patient::STATUS_ACTIVE]);

    return compact('hospital', 'department', 'practitioner', 'patient');
}

/**
 * A minimal Request carrying the given user as its resolved actor — for
 * calling a *Service class method directly in a test without going through
 * a real HTTP request/response cycle.
 */
function requestAs(User $user): Request
{
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    return $request;
}

/**
 * Create a fresh open clinical encounter (with a self-consistent hospital
 * context) and start its triage assessment — the minimum valid context for
 * recording vital signs in a test.
 */
function openTriageAssessment(): TriageAssessment
{
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);

    return app(TriageService::class)->create($encounter, requestAs(userWithRole('nurse')));
}

/**
 * Create a user assigned the 'doctor' role who also has a Staff + active
 * Practitioner profile under the given hospital — the minimum valid actor
 * for starting a consultation in tests (ConsultationService requires the
 * acting user to resolve to a clinically eligible practitioner).
 *
 * @return array{user: User, staff: Staff, practitioner: Practitioner}
 */
function doctorWithPractitioner(Hospital $hospital): array
{
    $user = userWithRole('doctor');
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id, 'user_id' => $user->id]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $staff->id,
        'practitioner_type' => 'doctor',
        'status' => Practitioner::STATUS_ACTIVE,
    ]);

    return compact('user', 'staff', 'practitioner');
}

/**
 * Create a fresh clinical encounter with its triage assessment completed —
 * the minimum valid context for starting a consultation in a test.
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter}
 */
function consultationReadyEncounter(): array
{
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);

    $triageRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounter, $triageRequest);
    app(TriageService::class)->start($triage, $triageRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $triageRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $triageRequest);
    app(TriageService::class)->complete($triage->fresh(), $triageRequest);

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $encounter->fresh(),
    ];
}

/**
 * Create a fresh encounter with a completed triage and an in_progress
 * consultation — the minimum valid context for recording a diagnosis in a
 * test (DiagnosisService requires an engaged consultation).
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function diagnosisReadyEncounter(): array
{
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $consultationRequest = requestAs($doctor['user']);
    $consultation = app(ConsultationService::class)->create($ctx['encounter'], $consultationRequest);
    app(ConsultationService::class)->start($consultation, $consultationRequest);

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $ctx['encounter']->fresh(),
        'doctor' => $doctor,
    ];
}

/**
 * Create a fresh, diagnosis-ready encounter (consultation engaged, no
 * diagnosis required — spec §17) with a laboratory order already placed
 * for the given active laboratory tests — the minimum valid context for
 * registering a specimen in a test (LaboratorySampleService requires the
 * order to be ordered/sample_pending, never a diagnosis).
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: LaboratoryOrder}
 */
function laboratoryOrderReadyForSampling(array $tests): array
{
    $ctx = diagnosisReadyEncounter();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => array_map(fn (LaboratoryTest $test) => $test->id, $tests),
    ], requestAs($ctx['doctor']['user']));

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $ctx['encounter'],
        'doctor' => $ctx['doctor'],
        'order' => $order->fresh('items'),
    ];
}

/**
 * Create a fully received specimen — collected, labelled, received, and
 * with its LaboratoryProcessing records already initialized — for the
 * given active laboratory tests (all sharing the same specimen/container
 * requirement). The minimum valid context for testing laboratory
 * processing (Phase 4, Step 32): acceptSample()/rejectSample() require
 * LaboratorySample::STATUS_RECEIVED, and processing records only exist
 * once a specimen has actually been received.
 *
 * @param  list<LaboratoryTest>  $tests
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: LaboratoryOrder, sample: LaboratorySample}
 */
function receivedSampleReadyForProcessing(array $tests): array
{
    $ctx = laboratoryOrderReadyForSampling($tests);
    $request = requestAs($ctx['doctor']['user']);
    $firstTest = $tests[0];

    $itemIds = $ctx['order']->items->pluck('id')->all();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => $itemIds,
        'specimen_type' => $firstTest->specimen_type,
        'container_type' => $firstTest->specimen_container,
    ], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    $sample = app(LaboratorySampleService::class)->receive($sample, $request);
    app(LaboratoryProcessingService::class)->initializeForSample($sample, $request);

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $ctx['encounter'],
        'doctor' => $ctx['doctor'],
        'order' => $ctx['order']->fresh('items'),
        'sample' => $sample->fresh('items'),
    ];
}

/**
 * Build a fully-received specimen (one test) under a specific hospital —
 * needed for cross-hospital laboratory-processing tests.
 */
function receivedSampleForHospital(Hospital $hospital, LaboratoryTest $test): LaboratorySample
{
    [$order, $doctor] = laboratorySamplingOrderFor($hospital, $test);
    $request = requestAs($doctor['user']);
    $item = $order->items->sole();

    $sample = app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => $test->specimen_type,
        'container_type' => $test->specimen_container,
    ], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    $sample = app(LaboratorySampleService::class)->receive($sample, $request);
    app(LaboratoryProcessingService::class)->initializeForSample($sample, $request);

    return $sample->fresh();
}

/**
 * Build a specimen whose single test has completed processing and reached
 * RESULT_PENDING — the minimum valid context for testing Phase 4, Step 33's
 * result-entry lifecycle. Mirrors LaboratoryProcessingController::complete()'s
 * own orchestration (LaboratoryProcessingService::complete() then
 * LaboratoryResultService::initializeForItem()), since that pairing is a
 * controller-level decision, not something either service does alone.
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: LaboratoryOrder, sample: LaboratorySample, processing: LaboratoryProcessing, result: LaboratoryResult}
 */
function resultPendingForItem(LaboratoryTest $test): array
{
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample']->fresh(), $request);
    $processing = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $processing = app(LaboratoryProcessingService::class)->start($processing, [], $request);
    $processing = app(LaboratoryProcessingService::class)->complete($processing, [], $request);
    app(LaboratoryResultService::class)->initializeForItem($processing->item, $processing->sample, $request);

    $result = LaboratoryResult::where('laboratory_order_item_id', $processing->laboratory_order_item_id)->sole();

    return [...$ctx, 'processing' => $processing->fresh(), 'result' => $result->fresh()];
}

/**
 * Build a result-pending test under a specific hospital — needed for
 * cross-hospital result-entry tests.
 */
function resultPendingForHospital(Hospital $hospital, LaboratoryTest $test): LaboratoryResult
{
    [$order, $doctor] = laboratorySamplingOrderFor($hospital, $test);
    $request = requestAs($doctor['user']);
    $item = $order->items->sole();

    $sample = app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => $test->specimen_type,
        'container_type' => $test->specimen_container,
    ], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    $sample = app(LaboratorySampleService::class)->receive($sample, $request);
    app(LaboratoryProcessingService::class)->initializeForSample($sample, $request);

    app(LaboratoryProcessingService::class)->acceptSample($sample->fresh(), $request);
    $processing = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->sole();
    $processing = app(LaboratoryProcessingService::class)->start($processing, [], $request);
    $processing = app(LaboratoryProcessingService::class)->complete($processing, [], $request);
    app(LaboratoryResultService::class)->initializeForItem($processing->item, $processing->sample, $request);

    return LaboratoryResult::where('laboratory_order_item_id', $processing->laboratory_order_item_id)->sole();
}

/**
 * Build a specimen whose single test has been entered and submitted for
 * verification (PENDING_VERIFICATION) — the minimum valid context for
 * testing Phase 4, Step 34's review/verify/authorize/return-for-correction
 * lifecycle. `$value` defaults to a numeric value; pass e.g.
 * ['value_code' => 'positive'] for a qualitative/coded test.
 *
 * @param  array<string, mixed>  $value
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: LaboratoryOrder, sample: LaboratorySample, processing: LaboratoryProcessing, result: LaboratoryResult}
 */
function pendingVerificationForItem(LaboratoryTest $test, array $value = ['value_numeric' => '13.5']): array
{
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], $value, $request);
    $result = $service->submit($result, $request);

    return [...$ctx, 'result' => $result->fresh()];
}

/**
 * Build a PENDING_VERIFICATION result under a specific hospital — needed
 * for cross-hospital verification tests.
 */
function pendingVerificationForHospital(Hospital $hospital, LaboratoryTest $test, array $value = ['value_numeric' => '13.5']): LaboratoryResult
{
    $result = resultPendingForHospital($hospital, $test);
    $enteredBy = $result->item->laboratoryOrder->ordered_by;
    $request = requestAs(User::find($enteredBy));
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($result, $value, $request);

    return $service->submit($result, $request);
}

/**
 * Build a fully RELEASED result (and its order's first report version) —
 * the minimum valid context for testing Phase 4, Step 35's release/report
 * lifecycle. A different supervisor from the one who entered the result
 * reviews/verifies/authorizes/releases it, matching the entry-vs-review
 * separation this app enforces at the policy layer.
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: LaboratoryOrder, sample: LaboratorySample, processing: LaboratoryProcessing, result: LaboratoryResult, supervisor: User, report: LaboratoryReport}
 */
function releasedForItem(LaboratoryTest $test, array $value = ['value_numeric' => '13.5']): array
{
    $ctx = pendingVerificationForItem($test, $value);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $resultService = app(LaboratoryResultService::class);
    $reportService = app(LaboratoryReportService::class);

    $result = $resultService->startReview($ctx['result'], $request);
    $result = $resultService->verifyResult($result, [], $request);
    $result = $resultService->authorizeResult($result, $request);
    $result = $resultService->release($result, [], $request);
    $report = $reportService->generateForOrder($result->item->laboratoryOrder, $request);

    return [...$ctx, 'result' => $result->fresh(), 'supervisor' => $supervisor, 'report' => $report->fresh()];
}

/**
 * Build a fully RELEASED result under a specific hospital, with its
 * order's first report version — needed for cross-hospital release/report
 * tests.
 *
 * @return array{result: LaboratoryResult, report: LaboratoryReport}
 */
function releasedForHospital(Hospital $hospital, LaboratoryTest $test, array $value = ['value_numeric' => '13.5']): array
{
    $result = pendingVerificationForHospital($hospital, $test, $value);
    $supervisor = User::factory()->create();
    $supervisor->assignRole('laboratory-supervisor');
    Staff::factory()->create(['user_id' => $supervisor->id, 'hospital_id' => $hospital->id]);
    $request = requestAs($supervisor);
    $resultService = app(LaboratoryResultService::class);
    $reportService = app(LaboratoryReportService::class);

    $result = $resultService->startReview($result, $request);
    $result = $resultService->verifyResult($result, [], $request);
    $result = $resultService->authorizeResult($result, $request);
    $result = $resultService->release($result, [], $request);
    $report = $reportService->generateForOrder($result->item->laboratoryOrder, $request);

    return ['result' => $result->fresh(), 'report' => $report->fresh()];
}

/**
 * Build a diagnosis-ready, in_progress-consultation encounter with a
 * laboratory order for the given test, under a specific hospital — needed
 * for cross-hospital laboratory-sample tests.
 *
 * @return array{0: LaboratoryOrder, 1: array{user: User, staff: Staff, practitioner: Practitioner}}
 */
function laboratorySamplingOrderFor(Hospital $hospital, LaboratoryTest $test): array
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

    $order = app(LaboratoryOrderService::class)->create($encounter->fresh(), ['test_ids' => [$test->id]], $consultationRequest);

    return [$order->fresh('items'), $doctor];
}

/**
 * Create an active ICD-10 diagnosis code for use in tests.
 */
function activeDiagnosisCode(array $attributes = []): DiagnosisCode
{
    return DiagnosisCode::factory()->create([
        'status' => DiagnosisCode::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

/**
 * Create an active medication catalog entry for use in tests.
 */
function activeMedication(array $attributes = []): Medication
{
    return Medication::factory()->create([
        'status' => Medication::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

/**
 * Create an active laboratory test catalog entry for use in tests.
 */
function activeLaboratoryTest(array $attributes = []): LaboratoryTest
{
    return LaboratoryTest::factory()->create([
        'status' => LaboratoryTest::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

/**
 * Create a fresh encounter with a completed triage, an in_progress
 * consultation, and one confirmed diagnosis — the minimum valid context for
 * starting a treatment plan in a test (CarePlanService requires at least one
 * non-cancelled diagnosis).
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, diagnosis: EncounterDiagnosis}
 */
function carePlanReadyEncounter(): array
{
    $ctx = diagnosisReadyEncounter();
    $diagnosisRequest = requestAs($ctx['doctor']['user']);

    $diagnosis = app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], $diagnosisRequest);

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $ctx['encounter']->fresh(),
        'doctor' => $ctx['doctor'],
        'diagnosis' => $diagnosis,
    ];
}

/**
 * Create a fresh, non-cancelled clinical encounter with a doctor and a nurse
 * in the same hospital — the minimum valid context for authoring a clinical
 * note in a test. Unlike diagnosis/treatment, notes have no consultation or
 * diagnosis prerequisite (a nursing note may be recorded before a doctor is
 * ever involved).
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, nurse: User}
 */
function clinicalNoteReadyEncounter(): array
{
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $nurse = userWithRole('nurse');

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $encounter->fresh(),
        'doctor' => $doctor,
        'nurse' => $nurse,
    ];
}
