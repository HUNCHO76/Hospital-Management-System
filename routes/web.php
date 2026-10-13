<?php

use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BillingAdjustmentController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingReportController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\CarePlanItemController;
use App\Http\Controllers\CashierSessionController;
use App\Http\Controllers\ChargeController;
use App\Http\Controllers\ClinicalEncounterController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DiagnosisCodeController;
use App\Http\Controllers\DischargeDocumentController;
use App\Http\Controllers\DispensingController;
use App\Http\Controllers\EncounterDiagnosisController;
use App\Http\Controllers\EncounterLaboratoryOrderController;
use App\Http\Controllers\EncounterNhifPreApprovalController;
use App\Http\Controllers\EncounterPrescriptionOrderController;
use App\Http\Controllers\EncounterRadiologyOrderController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\InpatientBillingController;
use App\Http\Controllers\InpatientController;
use App\Http\Controllers\InsuranceCoverageRuleController;
use App\Http\Controllers\InsuranceProviderController;
use App\Http\Controllers\InsuranceSchemeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceInsuranceController;
use App\Http\Controllers\LaboratoryBenchController;
use App\Http\Controllers\LaboratoryOrderController;
use App\Http\Controllers\LaboratoryProcessingController;
use App\Http\Controllers\LaboratoryReferenceRangeController;
use App\Http\Controllers\LaboratoryReportController;
use App\Http\Controllers\LaboratoryResultController;
use App\Http\Controllers\LaboratoryResultDocumentController;
use App\Http\Controllers\LaboratorySampleController;
use App\Http\Controllers\LaboratoryTestCategoryController;
use App\Http\Controllers\LaboratoryTestController;
use App\Http\Controllers\LaboratoryTestParameterController;
use App\Http\Controllers\MedicationAdministrationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\NhifAdmissionController;
use App\Http\Controllers\NhifAuthorizationContextController;
use App\Http\Controllers\NhifClaimController;
use App\Http\Controllers\NhifClaimReconciliationController;
use App\Http\Controllers\NhifDashboardController;
use App\Http\Controllers\NhifDiseaseController;
use App\Http\Controllers\NhifDiseaseSyncController;
use App\Http\Controllers\NhifEligibilityController;
use App\Http\Controllers\NhifItemMappingController;
use App\Http\Controllers\NhifMemberVerificationController;
use App\Http\Controllers\NhifPackagePriceController;
use App\Http\Controllers\NhifPractitionerAttendanceController;
use App\Http\Controllers\NhifPreApprovalSessionController;
use App\Http\Controllers\NhifPreauthorizationController;
use App\Http\Controllers\NhifPriceSyncController;
use App\Http\Controllers\NhifServiceCatalogController;
use App\Http\Controllers\NhifServiceMappingController;
use App\Http\Controllers\NhifUtilizationController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientInsuranceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentRefundController;
use App\Http\Controllers\PaymentReversalController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\PharmacyReportController;
use App\Http\Controllers\PractitionerController;
use App\Http\Controllers\PrescriptionVerificationController;
use App\Http\Controllers\Procurement\IndentController;
use App\Http\Controllers\Procurement\ProcurementDashboardController;
use App\Http\Controllers\Procurement\ProcurementItemController;
use App\Http\Controllers\Procurement\PurchaseOrderController;
use App\Http\Controllers\Procurement\VendorController;
use App\Http\Controllers\Procurement\VendorInvoiceController;
use App\Http\Controllers\Procurement\VendorQuotationController;
use App\Http\Controllers\RadiologyExaminationController;
use App\Http\Controllers\RadiologyImageController;
use App\Http\Controllers\RadiologyOrderController;
use App\Http\Controllers\RadiologyProcedureController;
use App\Http\Controllers\RadiologyReceptionController;
use App\Http\Controllers\RadiologyReportController;
use App\Http\Controllers\RadiologyResultReleaseController;
use App\Http\Controllers\RadiologySchedulingController;
use App\Http\Controllers\RadiologyVerificationController;
use App\Http\Controllers\RadiologyWorklistController;
use App\Http\Controllers\ReceptionVisitController;
use App\Http\Controllers\ServiceCatalogController;
use App\Http\Controllers\ServicePricingController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\Stores\DepartmentStockRequestController;
use App\Http\Controllers\Stores\GoodsReceiptNoteController;
use App\Http\Controllers\Stores\ProcurementReportController;
use App\Http\Controllers\Stores\StoreDashboardController;
use App\Http\Controllers\Stores\StoreInventoryController;
use App\Http\Controllers\Stores\StoreIssueController;
use App\Http\Controllers\Stores\StoreTransferController;
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
    Route::post('practitioners/{practitioner}/nhif-login', [NhifPractitionerAttendanceController::class, 'login'])->name('practitioners.nhif-login');
    Route::post('practitioners/{practitioner}/nhif-logout', [NhifPractitionerAttendanceController::class, 'logout'])->name('practitioners.nhif-logout');

    // Phase 8, Step 70 — Admissions lifecycle; Phase 9, Step 72 — review/approval workflow
    Route::prefix('admissions')->name('admissions.')->group(function () {
        Route::get('', [AdmissionController::class, 'index'])->name('index');
        Route::get('create', [AdmissionController::class, 'create'])->name('create');
        Route::post('', [AdmissionController::class, 'store'])->name('store');
        Route::get('{admission}', [AdmissionController::class, 'show'])->name('show');
        Route::post('{admission}/review', [AdmissionController::class, 'startReview'])->name('review');
        Route::post('{admission}/approve', [AdmissionController::class, 'approve'])->name('approve');
        Route::post('{admission}/reject', [AdmissionController::class, 'reject'])->name('reject');
        Route::post('{admission}/return', [AdmissionController::class, 'returnForCorrection'])->name('return');
        Route::post('{admission}/confirm', [AdmissionController::class, 'confirm'])->name('confirm');
        Route::post('{admission}/discharge', [AdmissionController::class, 'discharge'])->name('discharge');
        Route::post('{admission}/cancel', [AdmissionController::class, 'cancel'])->name('cancel');
        Route::post('{admission}/transfers', [AdmissionController::class, 'storeTransfer'])->name('transfers.store');
        Route::post('{admission}/transfers/{transfer}/complete', [AdmissionController::class, 'completeTransfer'])->name('transfers.complete');
        Route::post('{admission}/nhif-submit', [AdmissionController::class, 'submitNhif'])->name('nhif-submit');
        Route::post('{admission}/submit-nhif-discharge', [AdmissionController::class, 'submitDischargeNhif'])->name('submit-nhif-discharge');
        Route::post('{admission}/transfers/{transfer}/nhif-submit', [AdmissionController::class, 'submitTransferNhif'])->name('transfers.nhif-submit');
    });

    // Phase 9, Step 74 — inpatient ward dashboard and patient clinical profile
    // Phase 9, Step 75 — medication administration record (MAR)
    Route::prefix('inpatient')->name('inpatient.')->group(function () {
        Route::get('', [InpatientController::class, 'dashboard'])->name('dashboard');
        Route::get('{admission}/clinical-profile', [InpatientController::class, 'clinicalProfile'])->name('clinical-profile');
        Route::post('{admission}/encounters', [InpatientController::class, 'openEncounter'])->name('open-encounter');
        Route::post('{admission}/clinical-status', [InpatientController::class, 'updateClinicalStatus'])->name('update-clinical-status');
        Route::post('{admission}/vitals', [InpatientController::class, 'storeVitals'])->name('store-vitals');
        // MAR routes
        Route::get('{admission}/medications', [MedicationAdministrationController::class, 'worklist'])->name('medications.worklist');
        Route::post('{admission}/medications', [MedicationAdministrationController::class, 'store'])->name('medications.store');
        Route::post('{admission}/medications/{medicationAdministration}/correct', [MedicationAdministrationController::class, 'correct'])->name('medications.correct');
        // Phase 9, Step 83 — inpatient billing view
        Route::get('{admission}/billing', [InpatientBillingController::class, 'show'])->name('billing');
        // Phase 9, Step 94 — discharge document generation
        Route::get('{admission}/discharge-document', [DischargeDocumentController::class, 'show'])->name('discharge-document');
        Route::post('{admission}/discharge-document', [DischargeDocumentController::class, 'generate'])->name('discharge-document.generate');
    });

    // Phase 8, Step 68 — NHIF service/price mapping administration
    // Phase 8, Step 69 — NHIF preauthorization lifecycle
    // Phase 8 Refactor — NHIF authorization-number lookup
    Route::prefix('nhif')->name('nhif.')->group(function () {
        // Phase 8, Step 75 — NHIF operational dashboard (read-only).
        Route::get('admissions', [NhifAdmissionController::class, 'index'])->name('admissions.index');
        Route::get('admissions/lookup', [NhifAdmissionController::class, 'lookup'])->name('admissions.lookup');
        Route::post('admissions/sync', [NhifAdmissionController::class, 'sync'])->name('admissions.sync');

        Route::get('dashboard', [NhifDashboardController::class, 'index'])->name('dashboard');
        Route::get('diseases/search-json', [NhifDiseaseController::class, 'searchJson'])->name('diseases.search-json');

        Route::get('authorization-context', [NhifAuthorizationContextController::class, 'index'])->name('authorization-context.index');
        Route::match(['get', 'post'], 'authorization-context/lookup', [NhifAuthorizationContextController::class, 'lookup'])->name('authorization-context.lookup');
        Route::get('service-mappings', [NhifServiceMappingController::class, 'index'])->name('service-mappings.index');
        Route::get('service-mappings/create', [NhifServiceMappingController::class, 'create'])->name('service-mappings.create');
        Route::post('service-mappings', [NhifServiceMappingController::class, 'store'])->name('service-mappings.store');
        Route::get('service-mappings/{nhifServiceMapping}', [NhifServiceMappingController::class, 'show'])->name('service-mappings.show');
        Route::get('service-mappings/{nhifServiceMapping}/edit', [NhifServiceMappingController::class, 'edit'])->name('service-mappings.edit');
        Route::put('service-mappings/{nhifServiceMapping}', [NhifServiceMappingController::class, 'update'])->name('service-mappings.update');
        Route::post('service-mappings/{nhifServiceMapping}/deactivate', [NhifServiceMappingController::class, 'deactivate'])->name('service-mappings.deactivate');
        Route::get('price-sync', [NhifPriceSyncController::class, 'index'])->name('price-sync.index');
        Route::post('price-sync', [NhifPriceSyncController::class, 'store'])->name('price-sync.store');
        Route::get('disease-sync', [NhifDiseaseSyncController::class, 'index'])->name('disease-sync.index');
        Route::post('disease-sync', [NhifDiseaseSyncController::class, 'store'])->name('disease-sync.store');
        Route::get('package-prices', [NhifPackagePriceController::class, 'index'])->name('package-prices.index');
        Route::post('package-prices/sync', [NhifPackagePriceController::class, 'sync'])->name('package-prices.sync');
        Route::get('catalog/services', [NhifServiceCatalogController::class, 'services'])->name('catalog.services');
        Route::get('catalog/packages', [NhifServiceCatalogController::class, 'packages'])->name('catalog.packages');
        Route::get('catalog-items/search-json', [NhifServiceCatalogController::class, 'searchItemsJson'])->name('catalog-items.search-json');
        Route::get('item-mapping', [NhifItemMappingController::class, 'index'])->name('item-mapping.index');
        Route::patch('item-mapping/laboratory-tests/{laboratoryTest}', [NhifItemMappingController::class, 'updateLaboratoryTest'])->name('item-mapping.laboratory-tests.update');
        Route::patch('item-mapping/medications/{medication}', [NhifItemMappingController::class, 'updateMedication'])->name('item-mapping.medications.update');

        // Individual preauth record actions (no patient context needed for these)
        Route::get('preauthorizations/{nhifPreauthorization}', [NhifPreauthorizationController::class, 'show'])->name('preauthorization.show');
        Route::post('preauthorizations/{nhifPreauthorization}/submit', [NhifPreauthorizationController::class, 'submit'])->name('preauthorization.submit');
        Route::post('preauthorizations/{nhifPreauthorization}/sync', [NhifPreauthorizationController::class, 'sync'])->name('preauthorization.sync');
        Route::post('preauthorizations/{nhifPreauthorization}/cancel', [NhifPreauthorizationController::class, 'cancel'])->name('preauthorization.cancel');

        // Phase 8, Step 71 — NHIF clinical service utilization monitoring & recovery.
        // Records are created only by clinical completion events, never through HTTP.
        Route::get('utilizations', [NhifUtilizationController::class, 'index'])->name('utilization.index');
        Route::get('utilizations/{nhifUtilization}', [NhifUtilizationController::class, 'show'])->name('utilization.show');
        Route::post('utilizations/{nhifUtilization}/submit', [NhifUtilizationController::class, 'submit'])->name('utilization.submit');
        Route::post('utilizations/{nhifUtilization}/authorization', [NhifUtilizationController::class, 'attachAuthorization'])->name('utilization.authorization');
        Route::post('utilizations/{nhifUtilization}/reconcile', [NhifUtilizationController::class, 'reconcile'])->name('utilization.reconcile');

        // OCS & Claim Workflow Endpoints (specific routes before wildcard {nhifClaim})
        Route::get('claims', [NhifClaimController::class, 'index'])->name('claims.index');
        Route::post('claims', [NhifClaimController::class, 'store'])->name('claims.store');
        Route::get('claims/eligible-visits', [NhifClaimController::class, 'eligibleVisits'])->name('claims.eligible-visits');
        Route::post('claims/preview', [NhifClaimController::class, 'preview'])->name('claims.preview');
        Route::post('claims/finalize-from-context', [NhifClaimController::class, 'finalizeFromContext'])->name('claims.finalize-from-context');
        Route::get('claims/monthly', [NhifClaimController::class, 'monthly'])->name('claims.monthly');
        Route::get('claims/monthly/calculate', [NhifClaimController::class, 'calculateMonthly'])->name('claims.monthly.calculate');
        Route::post('claims/monthly/submit', [NhifClaimController::class, 'submitMonthly'])->name('claims.monthly.submit');
        Route::post('claims/monthly/{nhifMonthlySubmission}/retry', [NhifClaimController::class, 'retryMonthly'])->name('claims.monthly.retry');
        Route::get('claims/bill-confirmation', [NhifClaimController::class, 'billConfirmation'])->name('claims.bill-confirmation.index');
        Route::post('claims/bill-confirmation', [NhifClaimController::class, 'requestBillConfirmation'])->name('claims.bill-confirmation.store');

        // Parameterized {nhifClaim} routes
        Route::get('claims/{nhifClaim}', [NhifClaimController::class, 'show'])->name('claims.show');
        Route::post('claims/{nhifClaim}/regenerate', [NhifClaimController::class, 'regenerate'])->name('claims.regenerate');
        Route::post('claims/{nhifClaim}/validate', [NhifClaimController::class, 'validateClaim'])->name('claims.validate');
        Route::post('claims/{nhifClaim}/finalize', [NhifClaimController::class, 'finalize'])->name('claims.finalize');
        Route::post('claims/{nhifClaim}/submit', [NhifClaimController::class, 'submit'])->name('claims.submit');
        Route::post('claims/{nhifClaim}/cancel', [NhifClaimController::class, 'cancel'])->name('claims.cancel');
        Route::post('claims/{nhifClaim}/reopen', [NhifClaimController::class, 'reopen'])->name('claims.reopen');
        Route::post('claims/{nhifClaim}/items', [NhifClaimController::class, 'addItem'])->name('claims.items.store');
        Route::delete('claims/{nhifClaim}/items/{nhifClaimItem}', [NhifClaimController::class, 'removeItem'])->name('claims.items.destroy');
        Route::post('claims/{nhifClaim}/documents', [NhifClaimController::class, 'uploadDocument'])->name('claims.documents.store');
        Route::get('claims/{nhifClaim}/documents/{nhifClaimDocument}', [NhifClaimController::class, 'downloadDocument'])->name('claims.documents.download');
        Route::delete('claims/{nhifClaim}/documents/{nhifClaimDocument}', [NhifClaimController::class, 'destroyDocument'])->name('claims.documents.destroy');
        Route::post('claims/{nhifClaim}/sign', [NhifClaimController::class, 'sign'])->name('claims.sign');
        Route::get('claims/{nhifClaim}/receipt', [NhifClaimController::class, 'receipt'])->name('claims.receipt');
        Route::post('claims/{nhifClaim}/retry-bill-confirmation', [NhifClaimController::class, 'retryBillConfirmation'])->name('claims.retry-bill-confirmation');

        // Phase 8, Step 74 — NHIF claim reconciliation & response handling.
        // Reuses Step 73's submission data; there is no way to hand-set a claim's
        // NHIF outcome — only to (re-)derive it from an actual NHIF response.
        Route::get('reconciliation', [NhifClaimReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('claims/{nhifClaim}/reconciliation', [NhifClaimReconciliationController::class, 'show'])->name('claims.reconciliation.show');
        Route::post('claims/{nhifClaim}/reconciliation/reconcile', [NhifClaimReconciliationController::class, 'reconcile'])->name('claims.reconciliation.reconcile');
        Route::post('claims/{nhifClaim}/reconciliation/refresh', [NhifClaimReconciliationController::class, 'refreshStatus'])->name('claims.reconciliation.refresh');
        Route::post('claims/{nhifClaim}/reconciliation/discrepancies/{nhifClaimDiscrepancy}/resolve', [NhifClaimReconciliationController::class, 'resolveDiscrepancy'])->name('claims.reconciliation.discrepancies.resolve');
    });
    Route::get('patients/search-json', [PatientController::class, 'searchJson'])->name('patients.search-json');
    // NHIF-first registration lookup (Phase 8): pre-registration internal HMS endpoints.
    Route::post('patients/nhif-lookup', [NhifMemberVerificationController::class, 'lookup'])->name('patients.nhif-lookup');
    Route::post('patients/nhif-verifiers', [NhifMemberVerificationController::class, 'getVerifiers'])->name('patients.nhif-verifiers');
    Route::get('patients/nhif-visit-types', [NhifMemberVerificationController::class, 'getVisitTypes'])->name('patients.nhif-visit-types');
    Route::get('patients/nhif-scheme-services', [NhifMemberVerificationController::class, 'getSchemeServices'])->name('patients.nhif-scheme-services');
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
        Route::post('{patientInsurance}/nhif-verify', [NhifMemberVerificationController::class, 'verify'])->name('nhif-verify');
        Route::post('{patientInsurance}/nhif-update-visit-type', [NhifMemberVerificationController::class, 'updateVisitType'])->name('nhif-update-visit-type');
        Route::post('{patientInsurance}/nhif-eligibility', [NhifEligibilityController::class, 'verify'])->name('nhif-eligibility');
        // Preauthorization creation/listing (patient context required for IDOR protection)
        Route::get('{patientInsurance}/nhif-preauthorizations', [NhifPreauthorizationController::class, 'index'])->name('nhif-preauthorization.index');
        Route::post('{patientInsurance}/nhif-preauthorizations', [NhifPreauthorizationController::class, 'store'])->name('nhif-preauthorization.store');

        // NHIF Pre-Approval Sessions (batch: create → submit → sync → issue)
        Route::prefix('{patientInsurance}/nhif-pre-approval-sessions')->name('nhif.pre-approval-sessions.')->group(function () {
            Route::get('/', [NhifPreApprovalSessionController::class, 'index'])->name('index');
            Route::get('create', [NhifPreApprovalSessionController::class, 'create'])->name('create');
            Route::post('/', [NhifPreApprovalSessionController::class, 'store'])->name('store');
            Route::get('{nhifPreApprovalSession}', [NhifPreApprovalSessionController::class, 'show'])->name('show');
            Route::post('{nhifPreApprovalSession}/submit', [NhifPreApprovalSessionController::class, 'submit'])->name('submit');
            Route::post('{nhifPreApprovalSession}/sync', [NhifPreApprovalSessionController::class, 'sync'])->name('sync');
            Route::post('{nhifPreApprovalSession}/issue', [NhifPreApprovalSessionController::class, 'issue'])->name('issue');
            Route::post('{nhifPreApprovalSession}/cancel', [NhifPreApprovalSessionController::class, 'cancel'])->name('cancel');
        });
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
        Route::get('consultation-price', [ReceptionVisitController::class, 'consultationPrice'])->name('consultation-price');
        Route::get('nhif-consultation-services', [ReceptionVisitController::class, 'nhifConsultationServices'])->name('nhif-consultation-services');
        Route::post('appointments/{appointment}/check-in', [ReceptionVisitController::class, 'checkInAppointment'])->name('appointments.check-in');
        Route::post('visits/{visit}/waiting', [ReceptionVisitController::class, 'markWaiting'])->name('visits.waiting');
        Route::post('visits/{visit}/ready', [ReceptionVisitController::class, 'markReady'])->name('visits.ready');
        Route::post('visits/{visit}/send-to-service', [ReceptionVisitController::class, 'sendToService'])->name('visits.send-to-service');
        Route::get('visits/{visit}/financial-status', [ReceptionVisitController::class, 'financialStatus'])->name('visits.financial-status');
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
        Route::post('{clinicalEncounter}/change-practitioner', [ClinicalEncounterController::class, 'changePractitioner'])->name('change-practitioner');

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

        // Radiology orders are presented as part of this same encounter
        // screen, never a standalone page. Radiology is its own sibling
        // branch off the encounter (Phase 5, Step 36) — never nested under
        // care-plan.items, mirroring laboratory-orders above exactly.
        Route::post('{clinicalEncounter}/radiology-orders', [EncounterRadiologyOrderController::class, 'store'])->name('radiology-orders.store');
        Route::put('{clinicalEncounter}/radiology-orders/{radiologyOrder}', [EncounterRadiologyOrderController::class, 'update'])->name('radiology-orders.update');
        Route::post('{clinicalEncounter}/radiology-orders/{radiologyOrder}/cancel', [EncounterRadiologyOrderController::class, 'cancel'])->name('radiology-orders.cancel');
        Route::post('{clinicalEncounter}/radiology-orders/{radiologyOrder}/items/{item}/cancel', [EncounterRadiologyOrderController::class, 'cancelItem'])->name('radiology-orders.items.cancel');

        // Prescription orders are presented as part of this same encounter
        // screen, never a standalone page. Prescription is its own sibling
        // branch off the encounter (Phase 6, Step 46) — never nested under
        // care-plan.items, mirroring radiology-orders above exactly. The
        // pre-existing care-plan.items.* prescription payload (Phase 3) is
        // left untouched for the care plan's own simple medication notes.
        Route::post('{clinicalEncounter}/prescription-orders', [EncounterPrescriptionOrderController::class, 'store'])->name('prescription-orders.store');
        Route::put('{clinicalEncounter}/prescription-orders/{prescriptionOrder}', [EncounterPrescriptionOrderController::class, 'update'])->name('prescription-orders.update');
        Route::post('{clinicalEncounter}/prescription-orders/{prescriptionOrder}/cancel', [EncounterPrescriptionOrderController::class, 'cancel'])->name('prescription-orders.cancel');
        Route::post('{clinicalEncounter}/prescription-orders/{prescriptionOrder}/items/{item}/cancel', [EncounterPrescriptionOrderController::class, 'cancelItem'])->name('prescription-orders.items.cancel');

        // NHIF pre-approval sessions scoped to this encounter (Phase 8).
        // Submitted inline on the encounter page — prices are fetched
        // server-side from the NHIF API, never trusted from the client.
        Route::post('{clinicalEncounter}/nhif-pre-approval', [EncounterNhifPreApprovalController::class, 'store'])->name('nhif-pre-approval.store');
        Route::post('{clinicalEncounter}/nhif-pre-approval/{nhifPreApprovalSession}/submit', [EncounterNhifPreApprovalController::class, 'submit'])->name('nhif-pre-approval.submit');
        Route::post('{clinicalEncounter}/nhif-pre-approval/{nhifPreApprovalSession}/sync', [EncounterNhifPreApprovalController::class, 'sync'])->name('nhif-pre-approval.sync');
        Route::post('{clinicalEncounter}/nhif-pre-approval/{nhifPreApprovalSession}/issue', [EncounterNhifPreApprovalController::class, 'issue'])->name('nhif-pre-approval.issue');
        Route::post('{clinicalEncounter}/nhif-pre-approval/{nhifPreApprovalSession}/cancel', [EncounterNhifPreApprovalController::class, 'cancel'])->name('nhif-pre-approval.cancel');
    });

    Route::get('diagnosis-codes/search-json', [DiagnosisCodeController::class, 'searchJson'])->name('diagnosis-codes.search-json');
    Route::get('medications/search-json', [MedicationController::class, 'searchJson'])->name('medications.search-json');
    Route::get('medications/resolve-nhif-item', [MedicationController::class, 'resolveNhifItem'])->name('medications.resolve-nhif-item');
    Route::get('laboratory-tests/search-json', [LaboratoryTestController::class, 'searchJson'])->name('laboratory-tests.search-json');
    Route::get('laboratory-tests/resolve-nhif-item', [LaboratoryTestController::class, 'resolveNhifItem'])->name('laboratory-tests.resolve-nhif-item');
    Route::get('radiology-procedures/search-json', [RadiologyProcedureController::class, 'searchJson'])->name('radiology-procedures.search-json');

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

    // The Radiology dashboard/worklist/detail (Phase 5, Step 36) — a
    // standalone, cross-encounter surface for radiology staff, mirroring
    // the laboratory group immediately above. No store/update/cancel routes
    // here: those flow through the clinical-encounters.radiology-orders.*
    // routes above (Radiology is its own branch off the ClinicalEncounter,
    // not a CarePlanItem). Ends at ORDERED — item-level scheduling is Step
    // 39; examination/reporting/release are Steps 40-44.
    Route::prefix('radiology')->name('radiology.')->group(function () {
        Route::get('/', [RadiologyOrderController::class, 'index'])->name('index');
        Route::get('orders', [RadiologyOrderController::class, 'orders'])->name('orders.index');
        Route::get('orders/{radiologyOrder}', [RadiologyOrderController::class, 'show'])->name('orders.show');
    });

    // The radiology procedure catalog (Phase 5, Step 37). No destroy
    // route: procedures are deactivated, never deleted (Part 27) — old
    // orders keep referencing an inactive procedure's row without ever
    // breaking. Mirrors laboratory/tests exactly.
    Route::resource('radiology/catalog', RadiologyProcedureController::class)
        ->parameters(['catalog' => 'radiologyProcedure'])
        ->names('radiology-catalog')
        ->except(['destroy']);
    Route::post('radiology/catalog/{radiologyProcedure}/activate', [RadiologyProcedureController::class, 'activate'])->name('radiology-catalog.activate');
    Route::post('radiology/catalog/{radiologyProcedure}/deactivate', [RadiologyProcedureController::class, 'deactivate'])->name('radiology-catalog.deactivate');

    // Radiology scheduling (Phase 5, Step 39) — the "awaiting scheduling"
    // queue plus schedule/reschedule/confirm/cancel/no-show mutations. No
    // destroy route: an appointment is cancelled, never deleted (Part 19).
    Route::prefix('radiology/scheduling')->name('radiology-scheduling.')->group(function () {
        Route::get('/', [RadiologySchedulingController::class, 'index'])->name('index');
        Route::post('/', [RadiologySchedulingController::class, 'store'])->name('store');
        Route::post('{radiologyAppointment}/reschedule', [RadiologySchedulingController::class, 'reschedule'])->name('reschedule');
        Route::post('{radiologyAppointment}/confirm', [RadiologySchedulingController::class, 'confirm'])->name('confirm');
        Route::post('{radiologyAppointment}/cancel', [RadiologySchedulingController::class, 'cancel'])->name('cancel');
        Route::post('{radiologyAppointment}/no-show', [RadiologySchedulingController::class, 'noShow'])->name('no-show');
    });

    // The radiology worklist (Phase 5, Step 39, Part 14) — read-only
    // operational view over the same scheduling data, reusing
    // radiology-scheduling.view rather than a separate permission.
    Route::get('radiology/worklist', [RadiologyWorklistController::class, 'index'])->name('radiology-worklist.index');

    // Radiology reception & preparation (Phase 5, Step 40) — the arrival ->
    // verification -> preparation/screening/consent -> readiness pipeline.
    // No destroy route: nothing here is ever deleted, only transitioned.
    Route::prefix('radiology/reception')->name('radiology-reception.')->group(function () {
        Route::get('/', [RadiologyReceptionController::class, 'index'])->name('index');
        Route::post('{radiologyAppointment}/arrive', [RadiologyReceptionController::class, 'arrive'])->name('arrive');
        Route::post('{radiologyAppointment}/verify', [RadiologyReceptionController::class, 'verify'])->name('verify');
        Route::post('{radiologyAppointment}/start-preparation', [RadiologyReceptionController::class, 'startPreparation'])->name('start-preparation');
        Route::post('{radiologyAppointment}/complete-preparation', [RadiologyReceptionController::class, 'completePreparation'])->name('complete-preparation');
        Route::post('{radiologyAppointment}/waive-preparation', [RadiologyReceptionController::class, 'waivePreparation'])->name('waive-preparation');
        Route::post('{radiologyAppointment}/screening', [RadiologyReceptionController::class, 'recordScreening'])->name('screening');
        Route::post('{radiologyAppointment}/review-contrast', [RadiologyReceptionController::class, 'reviewContrast'])->name('review-contrast');
        Route::post('{radiologyAppointment}/consent', [RadiologyReceptionController::class, 'recordConsent'])->name('consent');
        Route::post('{radiologyAppointment}/ready', [RadiologyReceptionController::class, 'markReady'])->name('ready');
        Route::post('{radiologyAppointment}/not-ready', [RadiologyReceptionController::class, 'markNotReady'])->name('not-ready');
        Route::post('{radiologyAppointment}/resume', [RadiologyReceptionController::class, 'resume'])->name('resume');
    });

    // Radiology examination (Phase 5, Step 41) — the actual imaging
    // procedure for an already-READY appointment: start -> record technical
    // information -> complete, or abort with a reason. No destroy route:
    // an aborted examination is never deleted, only transitioned (Part 14).
    Route::prefix('radiology/examinations')->name('radiology-examinations.')->group(function () {
        Route::get('/', [RadiologyExaminationController::class, 'index'])->name('index');
        Route::get('{radiologyAppointment}', [RadiologyExaminationController::class, 'show'])->name('show');
        Route::post('{radiologyAppointment}/start', [RadiologyExaminationController::class, 'start'])->name('start');
        Route::put('{radiologyAppointment}', [RadiologyExaminationController::class, 'update'])->name('update');
        Route::post('{radiologyAppointment}/complete', [RadiologyExaminationController::class, 'complete'])->name('complete');
        Route::post('{radiologyAppointment}/abort', [RadiologyExaminationController::class, 'abort'])->name('abort');
    });

    // Radiology examination imaging (Radiology UI completion pass) —
    // attach/remove/view X-Ray images against an IN_PROGRESS examination.
    // 'show' streams the raw image bytes for an <img> tag (re-authorized on
    // every request, never a public URL or raw filesystem path) — the one
    // deliberate exception to "every route renders an Inertia page" in this
    // module, the same way any authenticated-asset route works.
    Route::prefix('radiology/examinations/{radiologyAppointment}/images')->name('radiology-images.')->group(function () {
        Route::post('/', [RadiologyImageController::class, 'store'])->name('store');
        Route::get('{radiologyImage}', [RadiologyImageController::class, 'show'])->name('show');
        Route::delete('{radiologyImage}', [RadiologyImageController::class, 'destroy'])->name('destroy');
    });

    // Radiology findings & results (Phase 5, Step 42) — reporting on an
    // already-COMPLETED examination: DRAFT -> SUBMITTED. No destroy route:
    // a report is never deleted, only transitioned. 'create' is a GET
    // (read-only examination preview before drafting begins) deliberately
    // distinct from 'store' (the actual draft creation), mirroring the
    // rest of this app's resourceful-route conventions.
    Route::prefix('radiology/results')->name('radiology-results.')->group(function () {
        Route::get('/', [RadiologyReportController::class, 'index'])->name('index');
        Route::get('create/{radiologyAppointment}', [RadiologyReportController::class, 'create'])->name('create');
        Route::post('/', [RadiologyReportController::class, 'store'])->name('store');
        Route::get('{radiologyReport}', [RadiologyReportController::class, 'show'])->name('show');
        Route::put('{radiologyReport}', [RadiologyReportController::class, 'update'])->name('update');
        Route::post('{radiologyReport}/submit', [RadiologyReportController::class, 'submit'])->name('submit');
        Route::get('{radiologyReport}/history', [RadiologyReportController::class, 'history'])->name('history');
    });

    // Radiology report verification & authorization (Phase 5, Step 43):
    // independent review of an already-SUBMITTED report -> VERIFIED, or
    // -> RETURNED for correction. No destroy route: a report is never
    // deleted, only transitioned. History reuses radiology-results.history
    // above rather than a duplicate route.
    Route::prefix('radiology/verification')->name('radiology-verification.')->group(function () {
        Route::get('/', [RadiologyVerificationController::class, 'index'])->name('index');
        Route::get('{radiologyReport}', [RadiologyVerificationController::class, 'review'])->name('review');
        Route::post('{radiologyReport}/verify', [RadiologyVerificationController::class, 'verify'])->name('verify');
        Route::post('{radiologyReport}/return', [RadiologyVerificationController::class, 'returnForCorrection'])->name('return');
    });

    // Radiology result release (Phase 5, Step 44): official release of an
    // already-VERIFIED report -> RELEASED. No destroy route: a released
    // result is never deleted. History reuses radiology-results.history
    // above rather than a duplicate route.
    Route::prefix('radiology/release')->name('radiology-release.')->group(function () {
        Route::get('/', [RadiologyResultReleaseController::class, 'index'])->name('index');
        Route::get('{radiologyReport}', [RadiologyResultReleaseController::class, 'show'])->name('show');
        Route::post('{radiologyReport}', [RadiologyResultReleaseController::class, 'release'])->name('release');
    });

    // Prescription verification (Phase 6, Step 47) — independent pharmacy
    // review of an already-submitted PrescriptionOrder -> VERIFIED, or ->
    // RETURNED for correction. Declared BEFORE the pharmacy.prescriptions.*
    // group below: the literal 'verification' segment here must win over
    // that group's dynamic 'pharmacy/prescriptions/{prescriptionOrder}'
    // route, which Laravel would otherwise match first and try to resolve
    // "verification" as a prescriptionOrder id. No destroy route: a
    // prescription is never deleted, only transitioned. resubmit() is not
    // nested under this name() group — see its own registration below,
    // mirroring how it is the prescriber's action, not a pharmacy review one.
    Route::prefix('pharmacy/prescriptions/verification')->name('pharmacy-verification.')->group(function () {
        Route::get('/', [PrescriptionVerificationController::class, 'index'])->name('index');
    });
    Route::get('pharmacy/prescriptions/{prescriptionOrder}/verification', [PrescriptionVerificationController::class, 'review'])->name('pharmacy-verification.review');
    Route::post('pharmacy/prescriptions/{prescriptionOrder}/verify', [PrescriptionVerificationController::class, 'verify'])->name('pharmacy-verification.verify');
    Route::post('pharmacy/prescriptions/{prescriptionOrder}/return', [PrescriptionVerificationController::class, 'returnForCorrection'])->name('pharmacy-verification.return');
    Route::post('pharmacy/prescriptions/{prescriptionOrder}/resubmit', [PrescriptionVerificationController::class, 'resubmit'])->name('pharmacy-verification.resubmit');

    // Pharmacy Management Foundation (Phase 6, Step 44): a dashboard plus
    // the cross-encounter prescriptions worklist. Since Step 46, both read
    // the first-class PrescriptionOrder table (never the pre-existing
    // CarePlanItem-nested Prescription). No store/update/cancel routes
    // here: those flow through the clinical-encounters.prescription-orders.*
    // routes above (Prescription is its own branch off the
    // ClinicalEncounter, not a CarePlanItem), mirroring how the radiology/
    // laboratory groups stay read-only standalone surfaces.
    Route::prefix('pharmacy')->name('pharmacy.')->group(function () {
        Route::get('/', [PharmacyController::class, 'index'])->name('index');
        Route::get('prescriptions', [PharmacyController::class, 'prescriptions'])->name('prescriptions.index');
        Route::get('prescriptions/{prescriptionOrder}', [PharmacyController::class, 'showPrescription'])->name('prescriptions.show');
    });

    // Pharmacy dispensing (Phase 6, Step 48): the actual fulfillment of an
    // already-VERIFIED PrescriptionOrder. A distinct prefix from
    // pharmacy/prescriptions/* above, so no route-ordering conflict like
    // pharmacy-verification's own 'verification' segment needed. complete()/
    // cancel() take {prescriptionOrder}, not a separate {dispensing} id —
    // the controller resolves the order's own single in-progress Dispensing
    // internally, since only one may exist at a time. No destroy route: a
    // dispensing event is cancelled, never deleted.
    Route::prefix('pharmacy/dispensing')->name('pharmacy-dispensing.')->group(function () {
        Route::get('/', [DispensingController::class, 'index'])->name('index');
        Route::get('{prescriptionOrder}', [DispensingController::class, 'show'])->name('show');
        Route::post('{prescriptionOrder}/start', [DispensingController::class, 'start'])->name('start');
        Route::post('{prescriptionOrder}/complete', [DispensingController::class, 'complete'])->name('complete');
        Route::post('{prescriptionOrder}/cancel', [DispensingController::class, 'cancel'])->name('cancel');
    });

    // Pharmacy stock/inventory (Phase 6, Step 49): the ledgered inventory
    // layer behind dispensing — dashboard, searchable stock list, per-item
    // detail (batches + transaction ledger), receiving, and batch
    // corrections. 'receive' is declared before the 'items/{stockItem}'
    // dynamic segment so the literal 'receive' path never gets swallowed by
    // it, mirroring pharmacy-verification's own ordering precedent against
    // pharmacy/prescriptions/*. Batch actions take {stockBatch} directly
    // (not nested under a stock item id) since a batch id is already
    // globally unique and the controller loads its own parent stockItem for
    // authorization.
    Route::prefix('pharmacy/stock')->name('pharmacy-stock.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('receive', [StockController::class, 'receiveForm'])->name('receive.create');
        Route::post('receive', [StockController::class, 'receive'])->name('receive');
        Route::get('items', [StockController::class, 'items'])->name('items.index');
        Route::get('items/{stockItem}', [StockController::class, 'show'])->name('items.show');
        Route::post('batches/{stockBatch}/adjust', [StockController::class, 'adjust'])->name('batches.adjust');
        Route::post('batches/{stockBatch}/quarantine', [StockController::class, 'quarantine'])->name('batches.quarantine');
        Route::post('batches/{stockBatch}/release', [StockController::class, 'release'])->name('batches.release');
    });

    // Pharmacy reporting & operational analytics (Phase 6, Step 50) —
    // read-only aggregation over the Prescription/Verification/Dispensing/
    // Stock tables Steps 44-49 already own. No destroy/store/update routes:
    // every action here is a GET report (optionally exported as CSV via
    // ?export=1 on the same URL, so a report's filters/scope are identical
    // whether viewed or exported).
    Route::prefix('pharmacy/reports')->name('pharmacy-reports.')->group(function () {
        Route::get('/', [PharmacyReportController::class, 'index'])->name('index');
        Route::get('stock-batches', [PharmacyReportController::class, 'stockBatches'])->name('stock-batches');
        Route::get('low-stock', [PharmacyReportController::class, 'lowStock'])->name('low-stock');
        Route::get('out-of-stock', [PharmacyReportController::class, 'outOfStock'])->name('out-of-stock');
        Route::get('expiry', [PharmacyReportController::class, 'expiry'])->name('expiry');
        Route::get('stock-movements', [PharmacyReportController::class, 'movements'])->name('stock-movements');
        Route::get('medication-utilization', [PharmacyReportController::class, 'utilization'])->name('medication-utilization');
        Route::get('prescriptions', [PharmacyReportController::class, 'prescriptions'])->name('prescriptions');
        Route::get('dispensing', [PharmacyReportController::class, 'dispensing'])->name('dispensing');
        Route::get('reconciliation', [PharmacyReportController::class, 'reconciliation'])->name('reconciliation');
    });

    // The medication catalog (Phase 6, Step 45). No destroy route: a
    // medication is deactivated, never deleted (spec §11/§26) — old
    // prescriptions keep referencing an inactive medication's row without
    // ever breaking. Declared as a standalone, flatly-named resource
    // (route/policy/permission names all "medications") rather than nested
    // under name('pharmacy.'), mirroring how laboratory/tests and
    // radiology/catalog are declared outside their own module's own name()
    // group.
    Route::resource('pharmacy/medications', MedicationController::class)
        ->parameters(['medications' => 'medication'])
        ->names('medications')
        ->except(['destroy']);
    Route::post('pharmacy/medications/{medication}/activate', [MedicationController::class, 'activate'])->name('medications.activate');
    Route::post('pharmacy/medications/{medication}/deactivate', [MedicationController::class, 'deactivate'])->name('medications.deactivate');

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

        // Phase 4 Machine Document Upload — PDF/JPG/PNG documents from
        // analyzers, attached to an editable result and streamed back through
        // an authenticated route (path never sent to the browser).
        Route::post('results/{result}/documents', [LaboratoryResultDocumentController::class, 'store'])->name('results.documents.store');
        Route::get('results/{result}/documents/{document}', [LaboratoryResultDocumentController::class, 'show'])->name('results.documents.show');
        Route::delete('results/{result}/documents/{document}', [LaboratoryResultDocumentController::class, 'destroy'])->name('results.documents.destroy');
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

    // Per-test parameter definitions (analyte catalog engine, Batch A).
    // Parameters are created/updated/deleted via the parent test's show page —
    // no dedicated show/index routes; all actions redirect back to tests.show.
    Route::post('laboratory/tests/{laboratoryTest}/parameters', [LaboratoryTestParameterController::class, 'store'])->name('laboratory-test-parameters.store');
    Route::put('laboratory/tests/{laboratoryTest}/parameters/{parameter}', [LaboratoryTestParameterController::class, 'update'])->name('laboratory-test-parameters.update');
    Route::delete('laboratory/tests/{laboratoryTest}/parameters/{parameter}', [LaboratoryTestParameterController::class, 'destroy'])->name('laboratory-test-parameters.destroy');

    // Reference ranges nested under a parameter — also redirect to tests.show.
    Route::post('laboratory/parameters/{parameter}/ranges', [LaboratoryReferenceRangeController::class, 'store'])->name('laboratory-reference-ranges.store');
    Route::put('laboratory/parameters/{parameter}/ranges/{range}', [LaboratoryReferenceRangeController::class, 'update'])->name('laboratory-reference-ranges.update');
    Route::delete('laboratory/parameters/{parameter}/ranges/{range}', [LaboratoryReferenceRangeController::class, 'destroy'])->name('laboratory-reference-ranges.destroy');

    // Laboratory test categories — no show route (managed entirely from
    // the index page) and no destroy route (deactivated, never deleted).
    Route::resource('laboratory/categories', LaboratoryTestCategoryController::class)
        ->parameters(['categories' => 'laboratoryTestCategory'])
        ->names('laboratory-categories')
        ->except(['destroy', 'show']);
    Route::post('laboratory/categories/{laboratoryTestCategory}/activate', [LaboratoryTestCategoryController::class, 'activate'])->name('laboratory-categories.activate');
    Route::post('laboratory/categories/{laboratoryTestCategory}/deactivate', [LaboratoryTestCategoryController::class, 'deactivate'])->name('laboratory-categories.deactivate');

    // Billing & Payment Foundation (Phase 7, Step 52): dashboard, searchable
    // patient-account list, a single account's detail (balance + financial
    // timeline), and manual charge entry/lifecycle management. Invoices
    // (Phase 7, Step 55) live under the same 'billing.' name group, their
    // own 'invoices' prefix. No payment/receipt/refund routes — those
    // belong to Steps 57/58 and have no backing tables yet. 'create' is
    // declared before the 'accounts/{patientAccount}' dynamic segment isn't
    // actually ambiguous here (charges/invoices live under their own
    // prefixes), but kept grouped by resource for readability.
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::get('accounts', [BillingController::class, 'accounts'])->name('accounts.index');
        Route::get('accounts/{patientAccount}', [BillingController::class, 'showAccount'])->name('accounts.show');
        Route::get('charges/create', [ChargeController::class, 'create'])->name('charges.create');
        Route::post('charges', [ChargeController::class, 'store'])->name('charges.store');
        Route::post('charges/{charge}/post', [ChargeController::class, 'post'])->name('charges.post');
        Route::post('charges/{charge}/void', [ChargeController::class, 'void'])->name('charges.void');
        Route::post('charges/{charge}/cancel', [ChargeController::class, 'cancel'])->name('charges.cancel');

        // Invoices (Phase 7, Step 55): a draft is built via
        // create/store/addCharge/removeCharge, then finalized via post() —
        // the critical, transactional transition (spec §12/§40). void()
        // (DRAFT -> VOIDED) and cancel() (POSTED -> CANCELLED) are separate
        // routes, mirroring charges.void/charges.cancel exactly, even
        // though both currently share one InvoicePolicy ability.
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/charges', [InvoiceController::class, 'addCharge'])->name('invoices.charges.add');
        Route::post('invoices/{invoice}/charges/{charge}/remove', [InvoiceController::class, 'removeCharge'])->name('invoices.charges.remove');
        Route::post('invoices/{invoice}/post', [InvoiceController::class, 'post'])->name('invoices.post');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

        // Insurance Billing & Copayment (Phase 7, Step 56): who is
        // financially responsible for this invoice once insurance is
        // considered. Read (show) and the one state-changing action
        // (calculate/recalculate, DRAFT-only — see
        // InsuranceBillingService::applyToInvoice()'s own docblock) are
        // gated by their own distinct 'billing-insurance.view'/'.calculate'
        // permissions (spec §34), not folded into 'billing-invoices.*' —
        // not every invoice viewer needs to see insurance calculation
        // detail.
        Route::get('invoices/{invoice}/insurance', [InvoiceInsuranceController::class, 'show'])->name('invoices.insurance.show');
        Route::post('invoices/{invoice}/insurance/calculate', [InvoiceInsuranceController::class, 'calculate'])->name('invoices.insurance.calculate');

        // Payment Management (Phase 7, Step 57): recording a patient's own
        // payment against an invoice's outstanding patient responsibility
        // (Step 56's own insurance allocation is respected, never
        // overwritten — see PaymentService::calculateOutstanding()'s own
        // docblock). 'void' here means PaymentService::markFailed() (spec
        // §57.4's one controlled correction, mirrors billing.invoices.void
        // naming for the same kind of action); genuine refund/reversal is
        // Step 58's job and has no route here.
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
        // Phase 7, Step 58: financial corrections — reversal and refund are
        // sub-actions on an existing payment (not standalone create pages),
        // so they live under the 'billing.' group alongside their parent
        // payment routes. Adjustments are on invoices. Each correction has
        // a detail show page linked from the correction history table.
        Route::post('payments/{payment}/reversal', [PaymentReversalController::class, 'store'])->name('payments.reversal.store');
        Route::get('reversals/{reversal}', [PaymentReversalController::class, 'show'])->name('reversals.show');
        Route::post('payments/{payment}/refunds', [PaymentRefundController::class, 'store'])->name('payments.refunds.store');
        Route::get('refunds/{refund}', [PaymentRefundController::class, 'show'])->name('refunds.show');
        Route::get('adjustments', [BillingAdjustmentController::class, 'index'])->name('adjustments.index');
        Route::post('invoices/{invoice}/adjustments', [BillingAdjustmentController::class, 'store'])->name('invoices.adjustments.store');
        Route::get('adjustments/{adjustment}', [BillingAdjustmentController::class, 'show'])->name('adjustments.show');
        // Cashier sessions (Phase 7, Step 59): list, open, detail, and close.
        // 'close' is a POST sub-action on an existing session (mirrors
        // payments.void), not a separate destroy route — the session row is
        // never deleted, only transitioned to STATUS_CLOSED.
        Route::get('cashier', [CashierSessionController::class, 'index'])->name('cashier.index');
        Route::get('cashier/open', [CashierSessionController::class, 'create'])->name('cashier.create');
        Route::post('cashier', [CashierSessionController::class, 'store'])->name('cashier.store');
        Route::get('cashier/{cashierSession}', [CashierSessionController::class, 'show'])->name('cashier.show');
        Route::post('cashier/{cashierSession}/close', [CashierSessionController::class, 'close'])->name('cashier.close');
        // Billing reports hub and individual reports (Phase 7, Step 60) —
        // mirrors 'pharmacy-reports.*' naming exactly. 'export' is gated per
        // action via abort_unless() inside BillingReportController, not a
        // separate route — the same filtered query is used for both the HTML
        // page and the CSV download (?export=1).
        Route::get('reports', [BillingReportController::class, 'index'])->name('reports.index');
        Route::get('reports/invoices', [BillingReportController::class, 'invoices'])->name('reports.invoices');
        Route::get('reports/payments', [BillingReportController::class, 'payments'])->name('reports.payments');
        Route::get('reports/cashier-sessions', [BillingReportController::class, 'cashierSessions'])->name('reports.cashier-sessions');
        Route::get('reports/corrections', [BillingReportController::class, 'corrections'])->name('reports.corrections');
    });

    // The central billing service catalog (Phase 7, Step 53) — flatly
    // named ('billing-services'/'billing-pricing', not nested under
    // name('billing.')), mirroring how 'medications' sits outside
    // name('pharmacy.') despite living under the same 'pharmacy/' URL
    // prefix. No destroy route: a service is deactivated, never deleted.
    // Pricing is create + cancel only, deliberately no update route — a
    // price is never edited in place (spec §40), only superseded by a new
    // one (ServicePricingService::create()'s own versioning logic) or
    // cancelled before it ever takes effect.
    Route::resource('billing/services', ServiceCatalogController::class)
        ->parameters(['services' => 'service'])
        ->names('billing-services')
        ->except(['destroy']);
    Route::post('billing/services/{service}/activate', [ServiceCatalogController::class, 'activate'])->name('billing-services.activate');
    Route::post('billing/services/{service}/deactivate', [ServiceCatalogController::class, 'deactivate'])->name('billing-services.deactivate');

    Route::prefix('billing/pricing')->name('billing-pricing.')->group(function () {
        Route::get('/', [ServicePricingController::class, 'index'])->name('index');
        Route::get('create', [ServicePricingController::class, 'create'])->name('create');
        Route::post('/', [ServicePricingController::class, 'store'])->name('store');
        Route::patch('{servicePrice}', [ServicePricingController::class, 'update'])->name('update');
        Route::post('{servicePrice}/cancel', [ServicePricingController::class, 'cancel'])->name('cancel');
    });

    // Insurance coverage rule administration (Phase 7, Step 56) — flatly
    // named ('billing-insurance-rules', not nested under name('billing.')),
    // mirroring 'billing-services'/'billing-pricing' sitting outside that
    // group despite sharing the 'billing/' URL prefix. Create + cancel
    // only, no update route — a rule is never edited in place (mirrors
    // 'billing-pricing' having no update route for the identical reason).
    Route::prefix('billing/insurance-rules')->name('billing-insurance-rules.')->group(function () {
        Route::get('/', [InsuranceCoverageRuleController::class, 'index'])->name('index');
        Route::get('create', [InsuranceCoverageRuleController::class, 'create'])->name('create');
        Route::post('/', [InsuranceCoverageRuleController::class, 'store'])->name('store');
        Route::post('{insuranceCoverageRule}/cancel', [InsuranceCoverageRuleController::class, 'cancel'])->name('cancel');
    });

    // ==========================================
    // PROCUREMENT MODULE
    // ==========================================
    Route::prefix('procurement')->name('procurement.')->group(function () {
        Route::get('/', [ProcurementDashboardController::class, 'index'])->name('dashboard');

        // Indents / Requisitions
        Route::get('indents', [IndentController::class, 'index'])->name('indents.index');
        Route::get('indents/create', [IndentController::class, 'create'])->name('indents.create');
        Route::post('indents', [IndentController::class, 'store'])->name('indents.store');
        Route::get('indents/{indent}', [IndentController::class, 'show'])->name('indents.show');
        Route::get('indents/{indent}/edit', [IndentController::class, 'edit'])->name('indents.edit');
        Route::put('indents/{indent}', [IndentController::class, 'update'])->name('indents.update');
        Route::post('indents/{indent}/submit', [IndentController::class, 'submit'])->name('indents.submit');
        Route::post('indents/{indent}/approve', [IndentController::class, 'approve'])->name('indents.approve');
        Route::post('indents/{indent}/reject', [IndentController::class, 'reject'])->name('indents.reject');
        Route::post('indents/{indent}/cancel', [IndentController::class, 'cancel'])->name('indents.cancel');

        // Vendor Quotations & Matrix
        Route::get('quotations', [VendorQuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/create', [VendorQuotationController::class, 'create'])->name('quotations.create');
        Route::post('quotations', [VendorQuotationController::class, 'store'])->name('quotations.store');
        Route::get('quotations/compare', [VendorQuotationController::class, 'compare'])->name('quotations.compare');
        Route::get('quotations/{quotation}', [VendorQuotationController::class, 'show'])->name('quotations.show');
        Route::post('quotations/{quotation}/select', [VendorQuotationController::class, 'select'])->name('quotations.select');

        // Purchase Orders
        Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->name('orders.edit');
        Route::put('orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
        Route::post('orders/{order}/submit', [PurchaseOrderController::class, 'submit'])->name('orders.submit');
        Route::post('orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
        Route::post('orders/{order}/send', [PurchaseOrderController::class, 'send'])->name('orders.send');
        Route::post('orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');

        // Vendor Invoices & 3-Way Match
        Route::get('invoices', [VendorInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/create', [VendorInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [VendorInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [VendorInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/approve', [VendorInvoiceController::class, 'approve'])->name('invoices.approve');

        // Vendors Directory
        Route::resource('vendors', VendorController::class);

        // Procurement Items Catalog
        Route::resource('items', ProcurementItemController::class);
    });

    // ==========================================
    // STORES & INVENTORY MODULE
    // ==========================================
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [StoreDashboardController::class, 'index'])->name('dashboard');

        // Goods Receipt Notes (GRN)
        Route::get('grn', [GoodsReceiptNoteController::class, 'index'])->name('grn.index');
        Route::get('grn/create', [GoodsReceiptNoteController::class, 'create'])->name('grn.create');
        Route::post('grn', [GoodsReceiptNoteController::class, 'store'])->name('grn.store');
        Route::get('grn/{grn}', [GoodsReceiptNoteController::class, 'show'])->name('grn.show');
        Route::post('grn/{grn}/inspect', [GoodsReceiptNoteController::class, 'inspect'])->name('grn.inspect');
        Route::post('grn/{grn}/post', [GoodsReceiptNoteController::class, 'post'])->name('grn.post');

        // Department Stock Requests
        Route::get('requests', [DepartmentStockRequestController::class, 'index'])->name('requests.index');
        Route::get('requests/create', [DepartmentStockRequestController::class, 'create'])->name('requests.create');
        Route::post('requests', [DepartmentStockRequestController::class, 'store'])->name('requests.store');
        Route::get('requests/{stockRequest}', [DepartmentStockRequestController::class, 'show'])->name('requests.show');
        Route::post('requests/{stockRequest}/approve', [DepartmentStockRequestController::class, 'approve'])->name('requests.approve');

        // Store Issues (Outward Distribution)
        Route::get('issues', [StoreIssueController::class, 'index'])->name('issues.index');
        Route::get('issues/create', [StoreIssueController::class, 'create'])->name('issues.create');
        Route::post('issues', [StoreIssueController::class, 'store'])->name('issues.store');
        Route::get('issues/{issue}', [StoreIssueController::class, 'show'])->name('issues.show');
        Route::post('issues/{issue}/receive', [StoreIssueController::class, 'receive'])->name('issues.receive');

        // Store Transfers (Inter-Store Movement)
        Route::get('transfers', [StoreTransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/create', [StoreTransferController::class, 'create'])->name('transfers.create');
        Route::post('transfers', [StoreTransferController::class, 'store'])->name('transfers.store');
        Route::get('transfers/{transfer}', [StoreTransferController::class, 'show'])->name('transfers.show');
        Route::post('transfers/{transfer}/submit', [StoreTransferController::class, 'submit'])->name('transfers.submit');
        Route::post('transfers/{transfer}/dispatch', [StoreTransferController::class, 'dispatch'])->name('transfers.dispatch');
        Route::post('transfers/{transfer}/receive', [StoreTransferController::class, 'receive'])->name('transfers.receive');

        // Store Inventory & Batches
        Route::get('inventory', [StoreInventoryController::class, 'index'])->name('inventory.index');
        Route::get('batches', [StoreInventoryController::class, 'batches'])->name('inventory.batches');
        Route::post('inventory/adjust', [StoreInventoryController::class, 'adjust'])->name('inventory.adjust');

        // Reports
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [ProcurementReportController::class, 'index'])->name('index');
            Route::get('stock-valuation', [ProcurementReportController::class, 'stockValuation'])->name('stock-valuation');
            Route::get('expiry', [ProcurementReportController::class, 'expiry'])->name('expiry');
            Route::get('reorder-alerts', [ProcurementReportController::class, 'reorderAlerts'])->name('reorder-alerts');
            Route::get('discrepancies', [ProcurementReportController::class, 'discrepancies'])->name('discrepancies');
            Route::get('department-consumption', [ProcurementReportController::class, 'departmentConsumption'])->name('department-consumption');
        });
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
