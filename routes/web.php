<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\CarePlanItemController;
use App\Http\Controllers\ClinicalEncounterController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DiagnosisCodeController;
use App\Http\Controllers\EncounterDiagnosisController;
use App\Http\Controllers\EncounterLaboratoryOrderController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\InsuranceProviderController;
use App\Http\Controllers\InsuranceSchemeController;
use App\Http\Controllers\LaboratoryBenchController;
use App\Http\Controllers\LaboratoryOrderController;
use App\Http\Controllers\LaboratoryProcessingController;
use App\Http\Controllers\LaboratoryReportController;
use App\Http\Controllers\LaboratoryResultController;
use App\Http\Controllers\LaboratorySampleController;
use App\Http\Controllers\LaboratoryTestCategoryController;
use App\Http\Controllers\LaboratoryTestController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientInsuranceController;
use App\Http\Controllers\PractitionerController;
use App\Http\Controllers\ReceptionVisitController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TriageController;
use App\Http\Controllers\VitalSignController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('hospitals', HospitalController::class);
    Route::resource('departments', DepartmentController::class);
    Route::resource('staff', StaffController::class);
    Route::resource('practitioners', PractitionerController::class);
    Route::get('patients/search-json', [PatientController::class, 'searchJson'])->name('patients.search-json');
    Route::resource('patients', PatientController::class);
    Route::get('patients/{patient}/history', [PatientController::class, 'history'])->name('patients.history');
    Route::get('patients/{patient}/clinical-history', [PatientController::class, 'clinicalHistory'])->name('patients.clinical-history');

    Route::resource('audit-logs', AuditLogController::class)
        ->only(['index', 'show'])
        ->parameters(['audit-logs' => 'auditLog']);

    Route::prefix('insurance')->name('insurance.')->group(function () {
        Route::resource('providers', InsuranceProviderController::class);
        Route::resource('schemes', InsuranceSchemeController::class);
        Route::get('patient-insurance', [PatientInsuranceController::class, 'index'])->name('patient-insurance.index');
    });

    Route::prefix('patients/{patient}/insurance')->name('patients.insurance.')->group(function () {
        Route::get('create', [PatientInsuranceController::class, 'create'])->name('create');
        Route::post('/', [PatientInsuranceController::class, 'store'])->name('store');
        Route::get('{patientInsurance}', [PatientInsuranceController::class, 'show'])->name('show');
        Route::get('{patientInsurance}/edit', [PatientInsuranceController::class, 'edit'])->name('edit');
        Route::put('{patientInsurance}', [PatientInsuranceController::class, 'update'])->name('update');
        Route::delete('{patientInsurance}', [PatientInsuranceController::class, 'destroy'])->name('destroy');
        Route::post('{patientInsurance}/primary', [PatientInsuranceController::class, 'makePrimary'])->name('make-primary');
        Route::patch('{patientInsurance}/verify', [PatientInsuranceController::class, 'verify'])->name('verify');
    });

    // No destroy route: appointments are never hard-deleted, only cancelled
    // (see AppointmentController::cancel), so this is not a full
    // Route::resource() like the other modules above.
    Route::prefix('appointments')->name('appointments.')->group(function () {
        Route::get('/', [AppointmentController::class, 'index'])->name('index');
        Route::get('calendar', [AppointmentController::class, 'calendar'])->name('calendar');
        Route::get('create', [AppointmentController::class, 'create'])->name('create');
        Route::post('/', [AppointmentController::class, 'store'])->name('store');
        Route::get('{appointment}', [AppointmentController::class, 'show'])->name('show');
        Route::get('{appointment}/edit', [AppointmentController::class, 'edit'])->name('edit');
        Route::put('{appointment}', [AppointmentController::class, 'update'])->name('update');
        Route::post('{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('reschedule');
        Route::post('{appointment}/confirm', [AppointmentController::class, 'confirm'])->name('confirm');
        Route::post('{appointment}/check-in', [AppointmentController::class, 'checkIn'])->name('check-in');
        Route::post('{appointment}/start', [AppointmentController::class, 'start'])->name('start');
        Route::post('{appointment}/complete', [AppointmentController::class, 'complete'])->name('complete');
        Route::post('{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('cancel');
        Route::post('{appointment}/no-show', [AppointmentController::class, 'noShow'])->name('no-show');
    });

    // No destroy route: visits are never hard-deleted, only cancelled.
    Route::prefix('reception')->name('reception.')->group(function () {
        Route::get('/', [ReceptionVisitController::class, 'dashboard'])->name('dashboard');
        Route::get('visits', [ReceptionVisitController::class, 'index'])->name('visits.index');
        Route::get('visits/{visit}', [ReceptionVisitController::class, 'show'])->name('visits.show');
        Route::get('walk-in/create', [ReceptionVisitController::class, 'createWalkIn'])->name('walk-in.create');
        Route::post('walk-in', [ReceptionVisitController::class, 'storeWalkIn'])->name('walk-in.store');
        Route::post('appointments/{appointment}/check-in', [ReceptionVisitController::class, 'checkInAppointment'])->name('appointments.check-in');
        Route::post('visits/{visit}/waiting', [ReceptionVisitController::class, 'markWaiting'])->name('visits.waiting');
        Route::post('visits/{visit}/ready', [ReceptionVisitController::class, 'markReady'])->name('visits.ready');
        Route::post('visits/{visit}/send-to-service', [ReceptionVisitController::class, 'sendToService'])->name('visits.send-to-service');
        Route::post('visits/{visit}/complete', [ReceptionVisitController::class, 'complete'])->name('visits.complete');
        Route::post('visits/{visit}/cancel', [ReceptionVisitController::class, 'cancel'])->name('visits.cancel');
    });

    // No destroy/update route: clinical encounters are never hard-deleted or
    // arbitrarily edited, only progressed through explicit lifecycle actions.
    Route::prefix('clinical-encounters')->name('clinical-encounters.')->group(function () {
        Route::get('/', [ClinicalEncounterController::class, 'index'])->name('index');
        Route::get('create', [ClinicalEncounterController::class, 'create'])->name('create');
        Route::post('/', [ClinicalEncounterController::class, 'store'])->name('store');
        Route::get('{clinicalEncounter}', [ClinicalEncounterController::class, 'show'])->name('show');
        Route::post('{clinicalEncounter}/start', [ClinicalEncounterController::class, 'start'])->name('start');
        Route::post('{clinicalEncounter}/complete', [ClinicalEncounterController::class, 'complete'])->name('complete');
        Route::post('{clinicalEncounter}/cancel', [ClinicalEncounterController::class, 'cancel'])->name('cancel');

        // Triage and vital signs are presented as part of this same
        // encounter screen (spec §38) — every action redirects back here
        // rather than to a standalone page.
        Route::post('{clinicalEncounter}/triage', [TriageController::class, 'store'])->name('triage.store');
        Route::put('{clinicalEncounter}/triage', [TriageController::class, 'update'])->name('triage.update');
        Route::post('{clinicalEncounter}/triage/start', [TriageController::class, 'start'])->name('triage.start');
        Route::post('{clinicalEncounter}/triage/complete', [TriageController::class, 'complete'])->name('triage.complete');
        Route::post('{clinicalEncounter}/triage/cancel', [TriageController::class, 'cancel'])->name('triage.cancel');

        Route::post('{clinicalEncounter}/vital-signs', [VitalSignController::class, 'store'])->name('vital-signs.store');
        Route::post('{clinicalEncounter}/vital-signs/{vitalSign}/correct', [VitalSignController::class, 'correct'])->name('vital-signs.correct');

        // Consultation follows triage — presented as part of this same
        // encounter screen, never a standalone page. No {consultation} route
        // parameter: at most one consultation exists per encounter.
        Route::post('{clinicalEncounter}/consultation', [ConsultationController::class, 'store'])->name('consultation.store');
        Route::put('{clinicalEncounter}/consultation', [ConsultationController::class, 'update'])->name('consultation.update');
        Route::post('{clinicalEncounter}/consultation/start', [ConsultationController::class, 'start'])->name('consultation.start');
        Route::post('{clinicalEncounter}/consultation/complete', [ConsultationController::class, 'complete'])->name('consultation.complete');
        Route::post('{clinicalEncounter}/consultation/cancel', [ConsultationController::class, 'cancel'])->name('consultation.cancel');
        Route::post('{clinicalEncounter}/consultation/correct', [ConsultationController::class, 'correct'])->name('consultation.correct');

        // Diagnosis follows consultation — presented as part of this same
        // encounter screen, never a standalone page. Unlike triage/
        // consultation, multiple diagnoses exist per encounter, so a real
        // {diagnosis} route parameter is required.
        Route::post('{clinicalEncounter}/diagnoses', [EncounterDiagnosisController::class, 'store'])->name('diagnoses.store');
        Route::put('{clinicalEncounter}/diagnoses/{diagnosis}', [EncounterDiagnosisController::class, 'update'])->name('diagnoses.update');
        Route::post('{clinicalEncounter}/diagnoses/{diagnosis}/primary', [EncounterDiagnosisController::class, 'primary'])->name('diagnoses.primary');
        Route::post('{clinicalEncounter}/diagnoses/{diagnosis}/status', [EncounterDiagnosisController::class, 'status'])->name('diagnoses.status');
        Route::post('{clinicalEncounter}/diagnoses/{diagnosis}/cancel', [EncounterDiagnosisController::class, 'cancel'])->name('diagnoses.cancel');
        Route::post('{clinicalEncounter}/diagnoses/{diagnosis}/correct', [EncounterDiagnosisController::class, 'correct'])->name('diagnoses.correct');

        // Treatment/care plan follows diagnosis — presented as part of this
        // same encounter screen, never a standalone page. No {carePlan}
        // route parameter: at most one care plan exists per encounter, but
        // items need a real {item} parameter since multiple exist per plan.
        Route::post('{clinicalEncounter}/care-plan', [CarePlanController::class, 'store'])->name('care-plan.store');
        Route::put('{clinicalEncounter}/care-plan', [CarePlanController::class, 'update'])->name('care-plan.update');
        Route::post('{clinicalEncounter}/care-plan/activate', [CarePlanController::class, 'activate'])->name('care-plan.activate');
        Route::post('{clinicalEncounter}/care-plan/complete', [CarePlanController::class, 'complete'])->name('care-plan.complete');
        Route::post('{clinicalEncounter}/care-plan/cancel', [CarePlanController::class, 'cancel'])->name('care-plan.cancel');
        Route::post('{clinicalEncounter}/care-plan/correct', [CarePlanController::class, 'correct'])->name('care-plan.correct');

        Route::post('{clinicalEncounter}/care-plan/items', [CarePlanItemController::class, 'store'])->name('care-plan.items.store');
        Route::put('{clinicalEncounter}/care-plan/items/{item}', [CarePlanItemController::class, 'update'])->name('care-plan.items.update');
        Route::post('{clinicalEncounter}/care-plan/items/{item}/status', [CarePlanItemController::class, 'status'])->name('care-plan.items.status');
        Route::post('{clinicalEncounter}/care-plan/items/{item}/cancel', [CarePlanItemController::class, 'cancel'])->name('care-plan.items.cancel');
        Route::post('{clinicalEncounter}/care-plan/items/{item}/correct', [CarePlanItemController::class, 'correct'])->name('care-plan.items.correct');

        // Clinical notes are presented as part of this same encounter
        // screen, never a standalone page. Unlike triage/consultation/care
        // plan, many notes exist per encounter from potentially different
        // authors, so a real {clinicalNote} route parameter is required.
        Route::post('{clinicalEncounter}/clinical-notes', [ClinicalNoteController::class, 'store'])->name('clinical-notes.store');
        Route::put('{clinicalEncounter}/clinical-notes/{clinicalNote}', [ClinicalNoteController::class, 'update'])->name('clinical-notes.update');
        Route::post('{clinicalEncounter}/clinical-notes/{clinicalNote}/sign', [ClinicalNoteController::class, 'sign'])->name('clinical-notes.sign');
        Route::post('{clinicalEncounter}/clinical-notes/{clinicalNote}/amend', [ClinicalNoteController::class, 'amend'])->name('clinical-notes.amend');
        Route::post('{clinicalEncounter}/clinical-notes/{clinicalNote}/cancel', [ClinicalNoteController::class, 'cancel'])->name('clinical-notes.cancel');

        // Laboratory orders are presented as part of this same encounter
        // screen, never a standalone page. Laboratory is its own sibling
        // branch off the encounter (Phase 4, Step 30) — never nested under
        // care-plan.items the way Step 28 originally modeled it.
        Route::post('{clinicalEncounter}/laboratory-orders', [EncounterLaboratoryOrderController::class, 'store'])->name('laboratory-orders.store');
        Route::put('{clinicalEncounter}/laboratory-orders/{laboratoryOrder}', [EncounterLaboratoryOrderController::class, 'update'])->name('laboratory-orders.update');
        Route::post('{clinicalEncounter}/laboratory-orders/{laboratoryOrder}/cancel', [EncounterLaboratoryOrderController::class, 'cancel'])->name('laboratory-orders.cancel');
        Route::post('{clinicalEncounter}/laboratory-orders/{laboratoryOrder}/items/{item}/cancel', [EncounterLaboratoryOrderController::class, 'cancelItem'])->name('laboratory-orders.items.cancel');
    });

    Route::get('diagnosis-codes/search-json', [DiagnosisCodeController::class, 'searchJson'])->name('diagnosis-codes.search-json');
    Route::get('medications/search-json', [MedicationController::class, 'searchJson'])->name('medications.search-json');
    Route::get('laboratory-tests/search-json', [LaboratoryTestController::class, 'searchJson'])->name('laboratory-tests.search-json');

    // The Laboratory dashboard/worklist/detail — a standalone,
    // cross-encounter surface for laboratory staff. No store/update/cancel
    // routes here: those flow through the clinical-encounters.laboratory-
    // orders.* routes above (Laboratory is its own branch off the
    // ClinicalEncounter, not a CarePlanItem).
    Route::prefix('laboratory')->name('laboratory.')->group(function () {
        Route::get('/', [LaboratoryOrderController::class, 'index'])->name('index');
        Route::get('orders', [LaboratoryOrderController::class, 'orders'])->name('orders.index');
        Route::get('orders/{laboratoryOrder}', [LaboratoryOrderController::class, 'show'])->name('orders.show');
    });

    // Specimen collection (Phase 4, Step 31) — its own standalone surface,
    // since collection is a laboratory-department activity, not something
    // performed from the clinical encounter screen the way orders
    // themselves are placed.
    Route::prefix('laboratory')->name('laboratory.')->group(function () {
        Route::get('collection', [LaboratorySampleController::class, 'collectionIndex'])->name('collection.index');
        Route::get('collection/{laboratoryOrder}', [LaboratorySampleController::class, 'collectionShow'])->name('collection.show');
        Route::post('collection/{laboratoryOrder}/samples', [LaboratorySampleController::class, 'store'])->name('samples.store');

        Route::get('samples/{sample}', [LaboratorySampleController::class, 'show'])->name('samples.show');
        Route::get('samples/{sample}/label', [LaboratorySampleController::class, 'labelView'])->name('samples.label-view');
        Route::post('samples/{sample}/collect', [LaboratorySampleController::class, 'collect'])->name('samples.collect');
        Route::post('samples/{sample}/label', [LaboratorySampleController::class, 'label'])->name('samples.label');
        Route::post('samples/{sample}/receive', [LaboratorySampleController::class, 'receive'])->name('samples.receive');
        Route::post('samples/{sample}/reject', [LaboratorySampleController::class, 'reject'])->name('samples.reject');
        Route::post('samples/{sample}/recollect', [LaboratorySampleController::class, 'recollect'])->name('samples.recollect');
        Route::post('samples/{sample}/cancel', [LaboratorySampleController::class, 'cancel'])->name('samples.cancel');
    });

    // Laboratory processing (Phase 4, Step 32) — the bench-side workflow
    // once a specimen is received, its own standalone surface parallel to
    // laboratory/collection. Accept/reject act on a whole specimen
    // ({sample}); assign/start/complete/transfer act on one of its tests'
    // own processing record ({processing}).
    Route::prefix('laboratory')->name('laboratory.')->group(function () {
        Route::get('processing', [LaboratoryProcessingController::class, 'index'])->name('processing.index');
        Route::get('processing/{sample}', [LaboratoryProcessingController::class, 'show'])->name('processing.show');
        Route::post('processing/{sample}/accept', [LaboratoryProcessingController::class, 'accept'])->name('processing.accept');
        Route::post('processing/{sample}/reject', [LaboratoryProcessingController::class, 'reject'])->name('processing.reject');
        Route::post('processing/records/{processing}/assign', [LaboratoryProcessingController::class, 'assign'])->name('processing.assign');
        Route::post('processing/records/{processing}/start', [LaboratoryProcessingController::class, 'start'])->name('processing.start');
        Route::post('processing/records/{processing}/complete', [LaboratoryProcessingController::class, 'complete'])->name('processing.complete');
        Route::post('processing/records/{processing}/transfer', [LaboratoryProcessingController::class, 'transfer'])->name('processing.transfer');
    });

    // Laboratory result entry, verification, authorization, and release
    // (Phase 4, Steps 33-35) — its own standalone surface parallel to
    // laboratory/processing. One row per test's own result ({result}),
    // since one specimen may carry several independently resulted tests.
    // Ends at RELEASED.
    Route::prefix('laboratory')->name('laboratory.')->group(function () {
        Route::get('results', [LaboratoryResultController::class, 'index'])->name('results.index');
        Route::get('results/{result}', [LaboratoryResultController::class, 'show'])->name('results.show');
        Route::put('results/{result}', [LaboratoryResultController::class, 'update'])->name('results.update');
        Route::post('results/{result}/submit', [LaboratoryResultController::class, 'submit'])->name('results.submit');
        Route::post('results/{result}/amend', [LaboratoryResultController::class, 'amend'])->name('results.amend');
        Route::post('results/{result}/review', [LaboratoryResultController::class, 'startReview'])->name('results.review');
        Route::post('results/{result}/verify', [LaboratoryResultController::class, 'verify'])->name('results.verify');
        Route::post('results/{result}/authorize', [LaboratoryResultController::class, 'authorizeResult'])->name('results.authorize');
        Route::post('results/{result}/return', [LaboratoryResultController::class, 'returnForCorrection'])->name('results.return');
        Route::post('results/{result}/release', [LaboratoryResultController::class, 'release'])->name('results.release');
    });

    // The laboratory report viewer (Phase 4, Step 35) — one printable/
    // downloadable document per released report version, reached from a
    // released result rather than having its own worklist (the existing
    // laboratory/results worklist already covers the full lifecycle through
    // release, per this step's own anti-duplication requirement).
    Route::prefix('laboratory')->name('laboratory.')->group(function () {
        Route::get('reports/{report}', [LaboratoryReportController::class, 'show'])->name('reports.show');
        Route::post('reports/{report}/print', [LaboratoryReportController::class, 'print'])->name('reports.print');
        Route::post('reports/{report}/download', [LaboratoryReportController::class, 'download'])->name('reports.download');
    });

    // Laboratory work areas/benches (Phase 4, Step 32) — a small,
    // hospital-configurable catalog, mirroring laboratory/categories
    // exactly. No destroy route: deactivated, never deleted.
    Route::resource('laboratory/benches', LaboratoryBenchController::class)
        ->parameters(['benches' => 'laboratoryBench'])
        ->names('laboratory-benches')
        ->except(['destroy', 'show']);
    Route::post('laboratory/benches/{laboratoryBench}/activate', [LaboratoryBenchController::class, 'activate'])->name('laboratory-benches.activate');
    Route::post('laboratory/benches/{laboratoryBench}/deactivate', [LaboratoryBenchController::class, 'deactivate'])->name('laboratory-benches.deactivate');

    // The laboratory test catalog (Phase 4, Step 29). No destroy route:
    // tests are deactivated, never deleted (spec §23) — old orders keep
    // referencing an inactive test's row without ever breaking.
    Route::resource('laboratory/tests', LaboratoryTestController::class)
        ->parameters(['tests' => 'laboratoryTest'])
        ->names('laboratory-tests')
        ->except(['destroy']);
    Route::post('laboratory/tests/{laboratoryTest}/activate', [LaboratoryTestController::class, 'activate'])->name('laboratory-tests.activate');
    Route::post('laboratory/tests/{laboratoryTest}/deactivate', [LaboratoryTestController::class, 'deactivate'])->name('laboratory-tests.deactivate');

    // Laboratory test categories — no show route (managed entirely from
    // the index page) and no destroy route (deactivated, never deleted).
    Route::resource('laboratory/categories', LaboratoryTestCategoryController::class)
        ->parameters(['categories' => 'laboratoryTestCategory'])
        ->names('laboratory-categories')
        ->except(['destroy', 'show']);
    Route::post('laboratory/categories/{laboratoryTestCategory}/activate', [LaboratoryTestCategoryController::class, 'activate'])->name('laboratory-categories.activate');
    Route::post('laboratory/categories/{laboratoryTestCategory}/deactivate', [LaboratoryTestCategoryController::class, 'deactivate'])->name('laboratory-categories.deactivate');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
