<?php

use App\Models\BillingAdjustment;
use App\Models\CarePlanItem;
use App\Models\CashierSession;
use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\DiagnosisCode;
use App\Models\Dispensing;
use App\Models\EncounterDiagnosis;
use App\Models\Hospital;
use App\Models\InsuranceCoverageRule;
use App\Models\InsuranceScheme;
use App\Models\Invoice;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use App\Models\Practitioner;
use App\Models\Prescription;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use App\Models\RadiologyReport;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\TriageAssessment;
use App\Models\User;
use App\Services\BillingAdjustmentService;
use App\Services\CarePlanService;
use App\Services\CashierSessionService;
use App\Services\ChargeService;
use App\Services\ClinicalEncounterService;
use App\Services\ConsultationService;
use App\Services\DiagnosisService;
use App\Services\DispensingService;
use App\Services\InsuranceCoverageRuleService;
use App\Services\InvoiceService;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;
use App\Services\LaboratorySampleService;
use App\Services\PatientAccountService;
use App\Services\PatientInsuranceService;
use App\Services\PaymentRefundService;
use App\Services\PaymentReversalService;
use App\Services\PaymentService;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologyReceptionService;
use App\Services\RadiologyReportService;
use App\Services\RadiologySchedulingService;
use App\Services\ServiceCatalogService;
use App\Services\ServicePricingService;
use App\Services\StockService;
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
 * Receive an abundant, far-from-expiry batch of stock for the given
 * medication at the given hospital (Phase 6, Step 49) — calls
 * StockService::receive() directly (services never gate on permissions
 * themselves, mirroring every other direct-service-call helper here), so the
 * acting user's role is irrelevant. Defaults to 100000 units expiring 2 years
 * out, which is never a binding constraint for a test that doesn't care about
 * stock levels; a test that specifically wants a low/expiring/expired/absent
 * stock scenario should call StockService directly instead of this helper.
 */
function receiveStock(Medication $medication, Hospital $hospital, int $quantity = 100000, ?string $expiryDate = null): StockBatch
{
    // Seeded here (idempotent, mirrors userWithRole()) so a test that calls
    // this helper before assigning its own actor a role (e.g. building a
    // hospital-admin afterward for an IDOR check) never hits Spatie's
    // RoleDoesNotExist — this helper's own receiving actor never needs a
    // real role itself, since StockService never gates on permissions.
    seedRolesAndPermissions();
    $receiver = User::factory()->create();

    return app(StockService::class)->receive($hospital->id, [
        'medication_id' => $medication->id,
        'batch_number' => 'TEST-'.strtoupper(uniqid()),
        'expiry_date' => $expiryDate ?? now()->addYears(2)->toDateString(),
        'quantity' => $quantity,
    ], requestAs($receiver));
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
 * Create an active radiology procedure catalog entry for use in tests.
 */
function activeRadiologyProcedure(array $attributes = []): RadiologyProcedure
{
    return RadiologyProcedure::factory()->create([
        'status' => RadiologyProcedure::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

/**
 * Create an active laboratory test that is also mapped into the billing
 * Service catalog and priced at the given hospital (Phase 7, Step 54) —
 * mirrors ServiceCatalogSeeder's own mapping shape
 * (serviceable_type/serviceable_id -> LaboratoryTest, reusing its real
 * code/name) so ChargeCaptureService::captureFromLaboratoryOrderItem() can
 * resolve a price for it in a test.
 */
function billableLaboratoryTest(Hospital $hospital, array $testAttributes = [], array $priceOverrides = []): LaboratoryTest
{
    $test = activeLaboratoryTest($testAttributes);

    $service = serviceFor([
        'code' => $test->code,
        'name' => $test->name,
        'category' => 'laboratory',
        'serviceable_type' => LaboratoryTest::class,
        'serviceable_id' => $test->id,
    ]);

    priceFor($service, $hospital, $priceOverrides);

    return $test;
}

/**
 * Create an active radiology procedure that is also mapped into the billing
 * Service catalog and priced at the given hospital (Phase 7, Step 54) —
 * mirrors billableLaboratoryTest() exactly.
 */
function billableRadiologyProcedure(Hospital $hospital, array $procedureAttributes = [], array $priceOverrides = []): RadiologyProcedure
{
    $procedure = activeRadiologyProcedure($procedureAttributes);

    $service = serviceFor([
        'code' => $procedure->code,
        'name' => $procedure->name,
        'category' => 'radiology',
        'serviceable_type' => RadiologyProcedure::class,
        'serviceable_id' => $procedure->id,
    ]);

    priceFor($service, $hospital, $priceOverrides);

    return $procedure;
}

/**
 * Create a consultation service priced at the given hospital — the minimum
 * billing configuration needed to trigger a consultation charge in a test
 * (Step 62, spec §2).
 *
 * @param  array<string, mixed>  $priceOverrides
 */
function billableConsultationService(Hospital $hospital, array $priceOverrides = []): Service
{
    $service = serviceFor([
        'name' => 'Consultation-'.strtoupper(uniqid()),
        'category' => 'consultation',
    ]);

    priceFor($service, $hospital, $priceOverrides);

    return $service;
}

/**
 * Create a billable consultation service + a doctor practitioner mapped to
 * it, then build a triage-completed encounter through ClinicalEncounterService
 * so the consultation charge is captured at encounter creation time (Step 62).
 * Returns the encounter ready for consultation gate tests.
 *
 * @param  array<string, mixed>  $consultationPriceOverrides
 * @return array{ctx: array<string, mixed>, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, service: Service}
 */
function billableConsultationReadyEncounter(array $consultationPriceOverrides = []): array
{
    $ctx = clinicalEncounterContext();

    $consultationSvc = billableConsultationService($ctx['hospital'], $consultationPriceOverrides);

    $doctor = doctorWithPractitioner($ctx['hospital']);
    $doctor['practitioner']->update(['consultation_service_id' => $consultationSvc->id]);

    // Create encounter through the service so the charge is captured.
    $nurse = userWithRole('nurse');
    $encounter = app(ClinicalEncounterService::class)->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $doctor['practitioner']->id,
        'encounter_type' => 'walk_in',
    ], requestAs($nurse));

    // Complete triage — required before ConsultationService::create().
    $triageRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounter, $triageRequest);
    app(TriageService::class)->start($triage, $triageRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $triageRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $triageRequest);
    app(TriageService::class)->complete($triage->fresh(), $triageRequest);

    return [
        'ctx' => $ctx,
        'encounter' => $encounter->fresh(),
        'doctor' => $doctor,
        'service' => $consultationSvc,
    ];
}

/**
 * Create a fresh encounter with a placed, ORDERED radiology order (one
 * item, an active procedure) — the minimum valid context for scheduling in
 * a test. Mirrors laboratoryOrderReadyForSampling()'s shape exactly.
 *
 * @return array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}
 */
function radiologyOrderReadyForScheduling(array $procedureAttributes = []): array
{
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure($procedureAttributes);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    return [
        'hospital' => $ctx['hospital'],
        'department' => $ctx['department'],
        'patient' => $ctx['patient'],
        'encounter' => $ctx['encounter'],
        'doctor' => $ctx['doctor'],
        'order' => $order->fresh('items'),
        'item' => $order->items->sole(),
    ];
}

/**
 * Schedule a radiologyOrderReadyForScheduling() item and return its
 * appointment, using a radiology-technician actor — the minimum valid
 * context for a Step 40 reception test.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array<string, mixed>  $overrides
 */
function scheduledAppointmentFor(array $ctx, array $overrides = []): RadiologyAppointment
{
    $scheduler = userWithRole('radiology-technician');

    return app(RadiologySchedulingService::class)->schedule($ctx['item'], array_merge([
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $overrides), requestAs($scheduler));
}

/**
 * Take a scheduledAppointmentFor() appointment all the way through
 * reception (arrive -> verify -> preparation if required -> ready) to
 * READY — the minimum valid context for a Step 41 examination test. Uses a
 * fresh radiology-technician actor throughout, mirroring
 * scheduledAppointmentFor()'s own actor choice.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array<string, mixed>  $scheduleOverrides
 */
function radiologyAppointmentReadyForExamination(array $ctx, array $scheduleOverrides = []): RadiologyAppointment
{
    $technician = userWithRole('radiology-technician');
    $request = requestAs($technician);

    $appointment = scheduledAppointmentFor($ctx, $scheduleOverrides);

    $reception = app(RadiologyReceptionService::class);
    $reception->markArrived($appointment, $request);
    $reception->verify($appointment, $request);

    $appointment->refresh();

    if ($appointment->status === RadiologyAppointment::STATUS_PREPARATION_PENDING) {
        $reception->startPreparation($appointment, $request);
        $reception->completePreparation($appointment, ['completed' => true], $request);
    }

    $reception->markReady($appointment, $request);

    return $appointment->refresh();
}

/**
 * Take a radiologyAppointmentReadyForExamination() appointment through
 * start() only, leaving it IN_PROGRESS — the minimum valid context for a
 * radiology image upload test (images can only be attached while an
 * examination is actually under way). Returns both the appointment and the
 * technician who started it, since image management is scoped to the same
 * ability as recording technical information.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array<string, mixed>  $scheduleOverrides
 * @return array{appointment: RadiologyAppointment, technician: User}
 */
function radiologyExaminationInProgress(array $ctx, array $scheduleOverrides = []): array
{
    $appointment = radiologyAppointmentReadyForExamination($ctx, $scheduleOverrides);
    $technician = userWithRole('radiology-technician');

    app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician));

    return ['appointment' => $appointment->fresh(), 'technician' => $technician];
}

/**
 * Take a radiologyAppointmentReadyForExamination() appointment through
 * start() -> complete() — the minimum valid context for a Step 42 reporting
 * test. Uses a fresh radiology-technician actor to perform the exam,
 * distinct from whichever actor a test subsequently uses to report on it.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array<string, mixed>  $scheduleOverrides
 */
function radiologyExaminationCompleted(array $ctx, array $scheduleOverrides = []): RadiologyAppointment
{
    $appointment = radiologyAppointmentReadyForExamination($ctx, $scheduleOverrides);
    $technician = userWithRole('radiology-technician');
    $request = requestAs($technician);

    $service = app(RadiologyExaminationService::class);
    $service->start($appointment, [], $request);
    $appointment->refresh();
    $service->complete($appointment, [], $request);

    return $appointment->refresh();
}

/**
 * Create a user assigned the 'radiologist' role who also has a Staff +
 * active Practitioner profile under the given hospital — the minimum valid
 * actor for authoring a radiology report in tests.
 *
 * @return array{user: User, staff: Staff, practitioner: Practitioner}
 */
function radiologistWithPractitioner(Hospital $hospital, string $role = 'radiologist'): array
{
    $user = userWithRole($role);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id, 'user_id' => $user->id]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $staff->id,
        'practitioner_type' => 'radiologist',
        'status' => Practitioner::STATUS_ACTIVE,
    ]);

    return compact('user', 'staff', 'practitioner');
}

/**
 * Create a radiologistWithPractitioner() actor holding the
 * 'radiology-supervisor' role — the only role (besides hospital-admin)
 * permitted to review/verify/return/release a report. A thin, explicitly-
 * named wrapper so verification/release tests read clearly about *why*
 * this particular actor differs from a plain radiologist.
 *
 * @return array{user: User, staff: Staff, practitioner: Practitioner}
 */
function radiologySupervisorWithPractitioner(Hospital $hospital): array
{
    return radiologistWithPractitioner($hospital, 'radiology-supervisor');
}

/**
 * Take a radiologyExaminationCompleted() appointment through a fresh
 * report's create -> submit — the minimum valid context for a Step 43
 * verification test. Uses a fresh radiologist actor (with its own
 * practitioner profile) as the reporting practitioner unless one is given,
 * so a caller can pass a *different* radiologistWithPractitioner() when a
 * test specifically needs to control who the verifier is relative to the
 * author (e.g. self-verification tests).
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array{user: User, staff: Staff, practitioner: Practitioner}|null  $reporter
 * @param  array<string, mixed>  $scheduleOverrides
 */
function submittedRadiologyReportFor(array $ctx, ?array $reporter = null, array $scheduleOverrides = []): RadiologyReport
{
    $appointment = radiologyExaminationCompleted($ctx, $scheduleOverrides);
    $reporter ??= radiologistWithPractitioner($ctx['hospital']);
    $request = requestAs($reporter['user']);

    $service = app(RadiologyReportService::class);
    $report = $service->createDraft($appointment, [
        'technique' => 'Standard technique.',
        'findings' => 'No acute findings.',
        'impression' => 'Unremarkable study.',
    ], $request);

    return $service->submit($report, $request)->fresh();
}

/**
 * Take a submittedRadiologyReportFor() report through verify() — the
 * minimum valid context for a Step 44 release test. Uses a fresh
 * independent radiologist actor as the verifier unless one is given.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, order: RadiologyOrder, item: RadiologyOrderItem}  $ctx
 * @param  array{user: User, staff: Staff, practitioner: Practitioner}|null  $reporter
 * @param  array{user: User, staff: Staff, practitioner: Practitioner}|null  $verifier
 * @param  array<string, mixed>  $scheduleOverrides
 */
function verifiedRadiologyReportFor(array $ctx, ?array $reporter = null, ?array $verifier = null, array $scheduleOverrides = []): RadiologyReport
{
    $report = submittedRadiologyReportFor($ctx, $reporter, $scheduleOverrides);
    $verifier ??= radiologySupervisorWithPractitioner($ctx['hospital']);

    return app(RadiologyReportService::class)->verify($report, [], requestAs($verifier['user']))->fresh();
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
 * Create a carePlanReadyEncounter() context, then write one medication
 * prescription against it through the real CarePlanService::addItem() path
 * — the minimum valid context for a Phase 6, Step 44 pharmacy test. Returns
 * the created Prescription; the full ctx (with 'item' added) remains
 * reachable via $prescription->carePlanItem if a test needs it.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, diagnosis: EncounterDiagnosis}|null  $ctx
 * @param  array<string, mixed>  $overrides
 */
function prescriptionFor(?array $ctx = null, array $overrides = []): Prescription
{
    $ctx ??= carePlanReadyEncounter();
    $doctorRequest = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], [
        'goals' => 'Symptomatic relief',
    ], $doctorRequest);

    $medication = activeMedication();

    $item = app(CarePlanService::class)->addItem($carePlan, array_merge([
        'type' => CarePlanItem::TYPE_MEDICATION,
        'description' => 'Prescribed medication',
        'priority' => 'medium',
        'prescription' => [
            'medication_id' => $medication->id,
            'strength' => '500mg',
            'dose' => '1 tablet',
            'route' => 'oral',
            'frequency' => '3 times daily',
            'duration' => '5 days',
            'quantity' => '15',
            'instructions' => 'Take after meals',
        ],
    ], $overrides), $doctorRequest);

    return $item->prescription()->firstOrFail();
}

/**
 * Create a diagnosisReadyEncounter() context, then write one first-class
 * PrescriptionOrder against it through the real
 * PrescriptionOrderService::create() path (Phase 6, Step 46) — the minimum
 * valid context for a Step 47 pharmacy verification test. Creating the
 * order also creates its PrescriptionVerification row as
 * PENDING_VERIFICATION (PrescriptionOrderService::create() calls
 * PrescriptionVerificationService::initiate()), so the returned order is
 * already sitting in the verification queue.
 *
 * @param  array{hospital: Hospital, department: Department, patient: Patient, encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}}|null  $ctx
 * @param  array<string, mixed>  $overrides
 */
function activePrescriptionOrderFor(?array $ctx = null, array $overrides = []): PrescriptionOrder
{
    $ctx ??= diagnosisReadyEncounter();
    $doctorRequest = requestAs($ctx['doctor']['user']);

    $medication = activeMedication();

    $data = array_merge([
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'three_times_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
            'instructions' => 'Take after meals',
        ]],
    ], $overrides);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], $data, $doctorRequest);

    // Phase 6, Step 49: dispensing now deducts real stock inside
    // DispensingService::complete(), so every prescription built through this
    // helper — the shared foundation every Step 46-48 dispensing/verification
    // test already builds on — needs abundant stock to exist for whichever
    // medication(s) actually ended up on the order (the default above, or
    // whatever a caller's own 'items' override used), or those pre-existing
    // tests would start failing on "insufficient stock" with no relation to
    // what they actually test. A test that specifically wants a
    // low/expiring/expired/absent stock scenario should build its own order
    // via PrescriptionOrderService::create() directly instead of this helper.
    foreach ($order->items as $item) {
        receiveStock($item->medication, $ctx['hospital']);
    }

    return $order;
}

/**
 * Take an activePrescriptionOrderFor() order through
 * PrescriptionVerificationService::verify() — the minimum valid context for
 * a Step 47 dispensing-boundary/regression test. Uses a fresh pharmacist
 * actor as the verifier unless one is given.
 */
function verifiedPrescriptionOrderFor(?array $ctx = null, ?User $verifier = null): PrescriptionOrder
{
    $order = activePrescriptionOrderFor($ctx);
    $verifier ??= userWithRole('pharmacist');

    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($verifier));

    return $order->fresh(['items', 'verification']);
}

/**
 * Take a verifiedPrescriptionOrderFor() order through
 * DispensingService::start() — the minimum valid context for a Step 48
 * dispensing test. Uses a fresh pharmacist actor as the dispenser unless
 * one is given.
 */
function inProgressDispensingFor(?array $ctx = null, ?User $dispenser = null): Dispensing
{
    $order = verifiedPrescriptionOrderFor($ctx);
    $dispenser ??= userWithRole('pharmacist');

    return app(DispensingService::class)->start($order, requestAs($dispenser));
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

/**
 * Create a PENDING charge against a patient's account at the given hospital
 * (Phase 7, Step 52) — calls ChargeService::create() directly (services
 * never gate on permissions themselves, mirroring receiveStock()'s own
 * precedent), auto-provisioning the account on first use. Defaults to a
 * plain manual charge (no source_type/source_id) unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 */
function chargeFor(Hospital $hospital, Patient $patient, array $overrides = []): Charge
{
    $actor = User::factory()->create();

    return app(ChargeService::class)->create(array_merge([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'description' => 'Doctor Consultation',
        'quantity' => 1,
        'unit_price' => 20000,
    ], $overrides), requestAs($actor));
}

/**
 * A chargeFor() charge, immediately posted (Phase 7, Step 55) — the minimum
 * valid eligible-for-invoicing charge (InvoiceService::addCharge() requires
 * STATUS_POSTED) for a test.
 *
 * @param  array<string, mixed>  $overrides
 */
function postedChargeFor(Hospital $hospital, Patient $patient, array $overrides = []): Charge
{
    $charge = chargeFor($hospital, $patient, $overrides);

    return app(ChargeService::class)->post($charge, requestAs(User::factory()->create()));
}

/**
 * Create (or reuse) a patient's account at the given hospital directly,
 * without any charge — for tests that only care about the account itself.
 */
function accountFor(Hospital $hospital, Patient $patient): PatientAccount
{
    $actor = User::factory()->create();

    return app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($actor));
}

/**
 * Create an active, billable Service catalog entry directly (Phase 7, Step
 * 53) — calls ServiceCatalogService::create() (services never gate on
 * permissions themselves, mirroring receiveStock()/chargeFor()'s own
 * precedent).
 *
 * @param  array<string, mixed>  $overrides
 */
function serviceFor(array $overrides = []): Service
{
    $actor = User::factory()->create();

    return app(ServiceCatalogService::class)->create(array_merge([
        'code' => 'SVC-'.strtoupper(uniqid()),
        'name' => 'Test Service',
        'category' => 'consultation',
        'is_billable' => true,
    ], $overrides), requestAs($actor));
}

/**
 * Create an active price for a service at a hospital, effective from
 * today by default (Phase 7, Step 53) — calls ServicePricingService::
 * create() directly.
 *
 * @param  array<string, mixed>  $overrides
 */
function priceFor(Service $service, Hospital $hospital, array $overrides = []): ServicePrice
{
    $actor = User::factory()->create();

    return app(ServicePricingService::class)->create(array_merge([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'amount' => 20000,
        'effective_from' => now()->toDateString(),
    ], $overrides), requestAs($actor));
}

/**
 * Create the patient's primary insurance coverage record directly (Phase 7,
 * Step 56) — calls PatientInsuranceService::create() (services never gate
 * on permissions themselves, mirroring chargeFor()'s own precedent).
 * Defaults to an active, currently-effective record under the given scheme.
 *
 * @param  array<string, mixed>  $overrides
 */
function primaryCoverageFor(Patient $patient, InsuranceScheme $scheme, array $overrides = []): PatientInsurance
{
    $actor = User::factory()->create();

    return app(PatientInsuranceService::class)->create($patient, array_merge([
        'insurance_provider_id' => $scheme->insurance_provider_id,
        'insurance_scheme_id' => $scheme->id,
        'membership_number' => 'MEM-'.strtoupper(uniqid()),
        'effective_from' => now()->subMonth()->toDateString(),
        'effective_to' => null,
        'is_primary' => true,
        'status' => 'active',
        'verification_status' => 'verified',
    ], $overrides), requestAs($actor));
}

/**
 * Create an active insurance scheme under a fresh provider directly (Phase
 * 7, Step 56).
 */
function insuranceSchemeFor(array $overrides = []): InsuranceScheme
{
    return InsuranceScheme::factory()->create(array_merge([
        'status' => 'active',
    ], $overrides));
}

/**
 * Create an active coverage rule for a scheme directly (Phase 7, Step 56) —
 * calls InsuranceCoverageRuleService::create() directly, mirroring
 * priceFor()'s own precedent. Defaults to an 80% scheme-wide rule
 * (service_id/category both null).
 *
 * @param  array<string, mixed>  $overrides
 */
function coverageRuleFor(InsuranceScheme $scheme, array $overrides = []): InsuranceCoverageRule
{
    $actor = User::factory()->create();

    return app(InsuranceCoverageRuleService::class)->create(array_merge([
        'insurance_scheme_id' => $scheme->id,
        'coverage_type' => 'percentage',
        'coverage_percentage' => 80,
        'effective_from' => now()->subMonth()->toDateString(),
    ], $overrides), requestAs($actor));
}

/**
 * Create a single-charge POSTED invoice for a patient directly (Phase 7,
 * Step 57) — the minimum valid eligible-for-payment invoice. Calls
 * postedChargeFor()/InvoiceService::createDraft()/post() directly, mirroring
 * this file's own repeated "build the minimum valid context via the real
 * services" precedent.
 *
 * @param  array<string, mixed>  $chargeOverrides
 */
function postedInvoiceFor(Hospital $hospital, Patient $patient, array $chargeOverrides = []): Invoice
{
    $actor = User::factory()->create();
    $charge = postedChargeFor($hospital, $patient, $chargeOverrides);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($actor));

    return app(InvoiceService::class)->post($invoice, requestAs($actor));
}

/**
 * Record a payment directly against a posted invoice (Phase 7, Step 57) —
 * calls PaymentService::createPayment() directly, mirroring chargeFor()'s
 * own precedent.
 *
 * @param  array<string, mixed>  $overrides
 */
function paymentFor(Invoice $invoice, array $overrides = []): Payment
{
    $actor = User::factory()->create();

    return app(PaymentService::class)->createPayment(array_merge([
        'invoice_id' => $invoice->id,
        'amount' => 10000,
        'payment_date' => now()->toDateString(),
        'payment_method' => Payment::METHOD_CASH,
    ], $overrides), requestAs($actor));
}

/**
 * Reverse a posted payment directly (Phase 7, Step 58) — calls
 * PaymentReversalService::create() directly, mirroring paymentFor()'s
 * own precedent.
 *
 * @param  array<string, mixed>  $overrides
 */
function reversalFor(Payment $payment, array $overrides = []): PaymentReversal
{
    $actor = User::factory()->create();

    return app(PaymentReversalService::class)->create($payment, array_merge([
        'reason' => 'Test reversal',
        'notes' => null,
    ], $overrides), requestAs($actor));
}

/**
 * Issue a refund against a posted payment directly (Phase 7, Step 58) —
 * calls PaymentRefundService::create() directly, mirroring paymentFor()'s
 * own precedent.
 *
 * @param  array<string, mixed>  $overrides
 */
function refundFor(Payment $payment, array $overrides = []): PaymentRefund
{
    $actor = User::factory()->create();

    return app(PaymentRefundService::class)->create($payment, array_merge([
        'amount' => (float) $payment->amount / 2,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Test refund',
        'notes' => null,
    ], $overrides), requestAs($actor));
}

/**
 * Post a billing adjustment against a posted invoice directly (Phase 7,
 * Step 58) — calls BillingAdjustmentService::create() directly, mirroring
 * paymentFor()'s own precedent.
 *
 * @param  array<string, mixed>  $overrides
 */
function adjustmentFor(Invoice $invoice, array $overrides = []): BillingAdjustment
{
    $actor = User::factory()->create();

    return app(BillingAdjustmentService::class)->create($invoice, array_merge([
        'type' => BillingAdjustment::TYPE_CREDIT_ADJUSTMENT,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => 5000,
        'reason' => 'Test adjustment',
        'notes' => null,
    ], $overrides), requestAs($actor));
}

/**
 * Open a cashier session at the given hospital directly (Phase 7, Step 59)
 * — calls CashierSessionService::open() directly, mirroring paymentFor()'s
 * own precedent.
 *
 * @param  array<string, mixed>  $overrides
 */
function cashierSessionFor(Hospital $hospital, ?User $cashier = null, array $overrides = []): CashierSession
{
    $cashier ??= User::factory()->create();

    return app(CashierSessionService::class)->open(array_merge([
        'hospital_id' => $hospital->id,
        'opening_balance' => 50000,
        'notes' => null,
    ], $overrides), requestAs($cashier));
}
