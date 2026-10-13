<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The initial HMS roles, mapped to the permissions they should be granted.
     *
     * The special value '*' grants every permission in the system.
     *
     * @var array<string, list<string>|'*'>
     */
    protected array $roles = [
        'super-admin' => '*',

        'hospital-admin' => [
            'dashboard.view',
            'reports.view', 'reports.export',
            'users.view', 'users.create',
            'hospitals.view', 'hospitals.update',
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
            'staff.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'insurance-providers.view',
            'insurance-schemes.view', 'insurance-schemes.create', 'insurance-schemes.update', 'insurance-schemes.delete',
            'patient-insurance.view', 'patient-insurance.create', 'patient-insurance.update', 'patient-insurance.delete', 'patient-insurance.verify',
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.confirm', 'appointments.reschedule', 'appointments.cancel', 'appointments.checkin', 'appointments.complete', 'appointments.no_show',
            'reception.view', 'reception.dashboard', 'reception.checkin', 'reception.create_walkin', 'reception.update', 'reception.cancel', 'reception.complete',
            'clinical-encounters.view', 'clinical-encounters.create', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'triage.create', 'triage.update', 'triage.complete', 'triage.cancel',
            'vital-signs.view', 'vital-signs.create', 'vital-signs.correct',
            'consultation.view', 'consultation.create', 'consultation.update', 'consultation.complete', 'consultation.cancel', 'consultation.correct',
            'diagnosis.view', 'diagnosis.create', 'diagnosis.update', 'diagnosis.primary', 'diagnosis.status', 'diagnosis.cancel', 'diagnosis.correct',
            'diagnosis-codes.view',
            'treatment.view', 'treatment.create', 'treatment.update', 'treatment.activate', 'treatment.complete', 'treatment.cancel', 'treatment.correct',
            'prescription.view', 'prescription.create', 'prescription.update', 'prescription.cancel',
            'prescription-orders.view', 'prescription-orders.create', 'prescription-orders.update', 'prescription-orders.cancel',
            'medications.view', 'medications.create', 'medications.update', 'medications.activate', 'medications.deactivate',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-orders.create', 'laboratory-orders.update', 'laboratory-orders.cancel',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view', 'laboratory-benches.manage',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-results.review', 'laboratory-results.verify', 'laboratory-results.authorize', 'laboratory-results.return', 'laboratory-results.amend_verified', 'laboratory-results.release',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'laboratory-tests.view', 'laboratory-tests.create', 'laboratory-tests.update', 'laboratory-tests.activate', 'laboratory-tests.deactivate',
            'laboratory-categories.view', 'laboratory-categories.manage',
            'laboratory-test-parameters.manage',
            'radiology.view', 'radiology-orders.view', 'radiology-orders.create', 'radiology-orders.update', 'radiology-orders.cancel',
            'radiology-procedures.view', 'radiology-procedures.create', 'radiology-procedures.update', 'radiology-procedures.activate', 'radiology-procedures.deactivate',
            'radiology-scheduling.view', 'radiology-scheduling.create', 'radiology-scheduling.reschedule',
            'radiology-scheduling.confirm', 'radiology-scheduling.cancel', 'radiology-scheduling.no_show',
            'radiology-reception.view', 'radiology-reception.manage',
            'radiology-examination.view', 'radiology-examination.start', 'radiology-examination.update', 'radiology-examination.complete', 'radiology-examination.abort',
            'radiology-results.view', 'radiology-results.create', 'radiology-results.update', 'radiology-results.submit',
            'radiology-results.review', 'radiology-results.verify', 'radiology-results.return', 'radiology-results.release',
            'pharmacy.view', 'pharmacy-prescriptions.view',
            'pharmacy-verification.view', 'pharmacy-verification.review', 'pharmacy-verification.verify', 'pharmacy-verification.return',
            'pharmacy-dispensing.view', 'pharmacy-dispensing.start', 'pharmacy-dispensing.complete', 'pharmacy-dispensing.cancel',
            'pharmacy-stock.view', 'pharmacy-stock.receive', 'pharmacy-stock.adjust', 'pharmacy-stock.quarantine', 'pharmacy-stock.release', 'pharmacy-stock.view_transactions',
            'pharmacy-reports.view', 'pharmacy-reports.export',
            'billing.view', 'billing-accounts.view', 'billing-charges.view', 'billing-charges.create',
            'billing-services.view', 'billing-services.create', 'billing-services.update', 'billing-services.activate', 'billing-services.deactivate',
            'billing-pricing.view', 'billing-pricing.create', 'billing-pricing.update', 'billing-pricing.deactivate',
            'billing-invoices.view', 'billing-invoices.create', 'billing-invoices.post', 'billing-invoices.void',
            'billing-insurance.view', 'billing-insurance.calculate',
            'billing-insurance-rules.view', 'billing-insurance-rules.create', 'billing-insurance-rules.deactivate',
            'billing-payments.view', 'billing-payments.create', 'billing-payments.void',
            'billing-reversals.view', 'billing-reversals.create',
            'billing-refunds.view', 'billing-refunds.create',
            'billing-adjustments.view', 'billing-adjustments.create',
            'billing-cashier.view', 'billing-cashier.open', 'billing-cashier.close',
            'billing-reports.view', 'billing-reports.export',
            'audit-logs.view',
            'nhif-service-mappings.view', 'nhif-service-mappings.create', 'nhif-service-mappings.update', 'nhif-service-mappings.deactivate',
            'nhif-price-sync.view', 'nhif-price-sync.run',
            'nhif-package-prices.view', 'nhif-package-prices.sync',
            'nhif-preauthorization.view', 'nhif-preauthorization.request', 'nhif-preauthorization.cancel',
            'nhif-authorization-context.lookup',
            'nhif-preapproval.view', 'nhif-preapproval.create',
            'admission.view', 'admission.create', 'admission.review', 'admission.approve', 'admission.reject', 'admission.confirm', 'admission.discharge', 'admission.cancel', 'admission.transfer', 'admission.nhif-submit',
            'inpatient.encounter.create', 'inpatient.clinical-status.update',
            'inpatient.medications.view', 'inpatient.medications.administer', 'inpatient.medications.hold',
            'inpatient.medications.record-refusal', 'inpatient.medications.record-missed', 'inpatient.medications.correct',
            'discharge-document.view', 'discharge-document.generate', 'discharge-document.print',
            'nhif-utilization.view', 'nhif-utilization.submit', 'nhif-utilization.reconcile',
            'nhif-claims.view', 'nhif-claims.create', 'nhif-claims.update', 'nhif-claims.validate', 'nhif-claims.finalize', 'nhif-claims.cancel',
            'nhif-claims.submit', 'nhif-claims.view-submission', 'nhif-claims.retry-submission',
            'nhif-claims.view-reconciliation', 'nhif-claims.reconcile', 'nhif-claims.refresh-status', 'nhif-claims.resolve-discrepancy',
            // OCS: sign folio, retrieve receipt, monthly claim, bill confirmation
            'nhif-claims.sign', 'nhif-claims.receipt', 'nhif-claims.monthly-view', 'nhif-claims.monthly-calculate', 'nhif-claims.monthly-submit', 'nhif-claims.bill-confirmation',
            'nhif-dashboard.view',
            'dashboard.view',
            'reports.view', 'reports.export',
            // Procurement and Stores Module
            'procurement.view', 'procurement.dashboard',
            'procurement-indents.view', 'procurement-indents.create', 'procurement-indents.update', 'procurement-indents.submit', 'procurement-indents.approve', 'procurement-indents.reject', 'procurement-indents.cancel',
            'procurement-quotations.view', 'procurement-quotations.create', 'procurement-quotations.update', 'procurement-quotations.compare', 'procurement-quotations.select', 'procurement-quotations.reject',
            'procurement-purchase-orders.view', 'procurement-purchase-orders.create', 'procurement-purchase-orders.update', 'procurement-purchase-orders.submit', 'procurement-purchase-orders.approve', 'procurement-purchase-orders.send', 'procurement-purchase-orders.close', 'procurement-purchase-orders.reject', 'procurement-purchase-orders.cancel',
            'procurement-vendors.view', 'procurement-vendors.create', 'procurement-vendors.update', 'procurement-vendors.delete',
            'procurement-invoices.view', 'procurement-invoices.create', 'procurement-invoices.match', 'procurement-invoices.approve', 'procurement-invoices.reject',
            'procurement-items.view', 'procurement-items.create', 'procurement-items.update', 'procurement-items.activate', 'procurement-items.deactivate',
            'stores.view', 'stores.dashboard',
            'stores-grn.view', 'stores-grn.create', 'stores-grn.inspect', 'stores-grn.post',
            'stores-inventory.view', 'stores-inventory.adjust',
            'stores-batches.view', 'stores-batches.quarantine', 'stores-batches.release',
            'stores-issues.view', 'stores-issues.create', 'stores-issues.issue', 'stores-issues.receive',
            'stores-requests.view', 'stores-requests.create', 'stores-requests.submit', 'stores-requests.approve', 'stores-requests.reject', 'stores-requests.cancel',
            'stores-transfers.view', 'stores-transfers.create', 'stores-transfers.submit', 'stores-transfers.approve', 'stores-transfers.dispatch', 'stores-transfers.receive',
            'stores-reports.view', 'stores-reports.export',
        ],

        'Manager' => [
            'dashboard.view',
            'reports.view', 'reports.export',
            // Users / Staff management (create, update, view — NO delete)
            'users.view', 'users.create', 'users.update',
            'staff.view', 'staff.create', 'staff.update',
            'practitioners.view',
            // Roles & Permissions (read-only)
            'roles.view',
            'permissions.view',
            // Facilities & Departments (read-only)
            'hospitals.view',
            'departments.view',
            // Patients & Insurance (create, update, view — NO delete)
            'patients.view', 'patients.create', 'patients.update',
            'insurance-providers.view',
            'insurance-schemes.view',
            'patient-insurance.view', 'patient-insurance.create', 'patient-insurance.update', 'patient-insurance.verify',
            // Appointments & Reception
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.confirm', 'appointments.reschedule', 'appointments.cancel', 'appointments.checkin', 'appointments.complete', 'appointments.no_show',
            'reception.view', 'reception.dashboard', 'reception.checkin', 'reception.create_walkin', 'reception.update', 'reception.cancel', 'reception.complete',
            // Clinical Encounters & Care (read-only monitoring)
            'clinical-encounters.view',
            'triage.view',
            'vital-signs.view',
            'consultation.view',
            'diagnosis.view',
            'diagnosis-codes.view',
            'treatment.view',
            'prescription.view',
            'prescription-orders.view',
            'medications.view',
            'clinical-notes.view',
            'clinical-history.view',
            // Laboratory (view, tests, results & reports)
            'laboratory.view', 'laboratory-orders.view', 'laboratory-tests.view',
            'laboratory-samples.view',
            'laboratory-processing.view',
            'laboratory-benches.view',
            'laboratory-results.view',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'laboratory-categories.view',
            // Radiology (view, orders, results & procedures)
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-scheduling.view',
            'radiology-reception.view',
            'radiology-examination.view',
            'radiology-results.view',
            // Pharmacy (view, orders, stock monitoring & reports)
            'pharmacy.view', 'pharmacy-prescriptions.view',
            'pharmacy-verification.view',
            'pharmacy-dispensing.view',
            'pharmacy-stock.view', 'pharmacy-stock.view_transactions',
            'pharmacy-reports.view', 'pharmacy-reports.export',
            // Billing & Payments (oversight, invoices, payments, sessions & reports)
            'billing.view', 'billing-accounts.view', 'billing-charges.view',
            'billing-services.view',
            'billing-pricing.view',
            'billing-invoices.view',
            'billing-insurance.view',
            'billing-insurance-rules.view',
            'billing-payments.view',
            'billing-reversals.view',
            'billing-refunds.view',
            'billing-adjustments.view',
            'billing-cashier.view',
            'billing-reports.view', 'billing-reports.export',
            // Admissions (read-only & reports)
            'admission.view',
            'inpatient.medications.view',
            'discharge-document.view', 'discharge-document.print',
            // NHIF Claims & Monthly Claims (full operational claims authority)
            'nhif-dashboard.view',
            'nhif-service-mappings.view',
            'nhif-price-sync.view',
            'nhif-package-prices.view',
            'nhif-preauthorization.view',
            'nhif-authorization-context.lookup',
            'nhif-preapproval.view',
            'nhif-utilization.view',
            'nhif-claims.view', 'nhif-claims.create', 'nhif-claims.update', 'nhif-claims.validate', 'nhif-claims.finalize',
            'nhif-claims.sign', 'nhif-claims.submit', 'nhif-claims.receipt', 'nhif-claims.view-submission',
            'nhif-claims.monthly-view', 'nhif-claims.monthly-calculate', 'nhif-claims.monthly-submit', 'nhif-claims.bill-confirmation',
            'nhif-claims.view-reconciliation',
            // Audit Logs
            'audit-logs.view',
        ],

        'doctor' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view', 'patients.update',
            'patient-insurance.view',
            'appointments.view', 'appointments.confirm', 'appointments.checkin', 'appointments.complete', 'appointments.cancel', 'appointments.no_show',
            'clinical-encounters.view', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'vital-signs.view',
            'consultation.view', 'consultation.create', 'consultation.update', 'consultation.complete', 'consultation.cancel', 'consultation.correct',
            'diagnosis.view', 'diagnosis.create', 'diagnosis.update', 'diagnosis.primary', 'diagnosis.status', 'diagnosis.cancel', 'diagnosis.correct',
            'diagnosis-codes.view',
            'treatment.view', 'treatment.create', 'treatment.update', 'treatment.activate', 'treatment.complete', 'treatment.cancel', 'treatment.correct',
            'prescription.view', 'prescription.create', 'prescription.update', 'prescription.cancel',
            'prescription-orders.view', 'prescription-orders.create', 'prescription-orders.update', 'prescription-orders.cancel',
            'medications.view',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory-orders.create', 'laboratory-orders.update', 'laboratory-orders.cancel', 'laboratory-tests.view',
            'laboratory-samples.view',
            'laboratory-processing.view',
            // Phase 9, Step 71: a doctor may request an admission from clinical
            // judgment (matching how they already create lab/radiology/
            // prescription orders) but not confirm/discharge/cancel/submit it —
            // those stay administrative/nursing functions.
            'admission.view', 'admission.create',
            'inpatient.encounter.create',
            'inpatient.medications.view',
            'discharge-document.view', 'discharge-document.generate', 'discharge-document.print',
            'laboratory-results.view',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'radiology-orders.create', 'radiology-orders.update', 'radiology-orders.cancel', 'radiology-procedures.view',
            'nhif-preapproval.view', 'nhif-preapproval.create',
        ],

        'nurse' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view', 'patients.update',
            'patient-insurance.view',
            'appointments.view', 'appointments.checkin', 'appointments.complete',
            'clinical-encounters.view', 'clinical-encounters.create', 'clinical-encounters.start', 'clinical-encounters.complete', 'clinical-encounters.cancel',
            'triage.view', 'triage.create', 'triage.update', 'triage.complete', 'triage.cancel',
            'vital-signs.view', 'vital-signs.create', 'vital-signs.correct',
            'clinical-notes.view', 'clinical-notes.create', 'clinical-notes.update', 'clinical-notes.sign', 'clinical-notes.amend', 'clinical-notes.cancel',
            'clinical-history.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'admission.view', 'admission.create', 'admission.review', 'admission.approve', 'admission.reject', 'admission.confirm', 'admission.discharge', 'admission.cancel', 'admission.transfer',
            'inpatient.encounter.create', 'inpatient.clinical-status.update',
            'prescription-orders.view',
            'inpatient.medications.view', 'inpatient.medications.administer', 'inpatient.medications.hold',
            'inpatient.medications.record-refusal', 'inpatient.medications.record-missed', 'inpatient.medications.correct',
            'discharge-document.view', 'discharge-document.print',
        ],

        // Phase 6, Step 44: the pharmacy-facing role that receives and views
        // prescriptions written by doctors. Only 'view' abilities exist yet
        // — verification (Step 47), dispensing (Step 48), and stock
        // (Step 49) permissions will extend this role the same incremental
        // way 'radiology-technician' grew across Steps 39-41.
        'pharmacist' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'medications.view',
            'pharmacy.view', 'pharmacy-prescriptions.view',
            // Phase 6, Step 46: view access to the first-class
            // PrescriptionOrder detail page reached from the Pharmacy
            // worklist, mirroring 'radiology-technician' holding
            // 'radiology-orders.view'. Dispense/stock abilities (Steps
            // 48-49) will extend this role the same incremental way.
            'prescription-orders.view',
            // Phase 6, Step 47: the independent review gate — mirrors
            // 'radiology-supervisor' holding the review/verify/return trio.
            'pharmacy-verification.view', 'pharmacy-verification.review', 'pharmacy-verification.verify', 'pharmacy-verification.return',
            // Phase 6, Step 48: the actual fulfillment ability — the role
            // that performs dispensing, mirroring how it already performs
            // verification.
            'pharmacy-dispensing.view', 'pharmacy-dispensing.start', 'pharmacy-dispensing.complete', 'pharmacy-dispensing.cancel',
            // Phase 6, Step 49: the inventory layer behind dispensing — the
            // same role that dispenses medication also receives and
            // corrects the stock it dispenses from, since this system has
            // only one pharmacy operational role today (no separate
            // Inventory Officer/Pharmacy Technician split exists elsewhere
            // in this app's role architecture to mirror).
            'pharmacy-stock.view', 'pharmacy-stock.receive', 'pharmacy-stock.adjust', 'pharmacy-stock.quarantine', 'pharmacy-stock.release', 'pharmacy-stock.view_transactions',
            // Phase 6, Step 50: reporting/analytics over the pharmacy data
            // this role already produces day to day.
            'pharmacy-reports.view', 'pharmacy-reports.export',
            // NHIF Claim Finalization workflow for completed pharmacy/dispensing services
            'nhif-claims.view', 'nhif-claims.create', 'nhif-claims.finalize', 'nhif-claims.sign', 'nhif-claims.submit', 'nhif-claims.bill-confirmation',
        ],

        // View access to the radiology worklist/orders (Step 36) plus full
        // scheduling (Step 39), reception/preparation (Step 40), and
        // examination (Step 41) ability — the incremental growth this
        // role's own doc-comment always anticipated. This role performs the
        // operational/technical work; interpreting the resulting images
        // (Step 42) is a distinct clinical role — see 'radiologist' below.
        'radiology-technician' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-scheduling.view', 'radiology-scheduling.create', 'radiology-scheduling.reschedule',
            'radiology-scheduling.confirm', 'radiology-scheduling.cancel', 'radiology-scheduling.no_show',
            'radiology-reception.view', 'radiology-reception.manage',
            'radiology-examination.view', 'radiology-examination.start', 'radiology-examination.update', 'radiology-examination.complete', 'radiology-examination.abort',
        ],

        // The clinical reporting role (Phase 5, Step 42) — interprets
        // completed examinations and authors findings/impression only.
        // Deliberately excludes review/verify/return/release: a plain
        // 'radiologist' can draft and submit a report but is never the one
        // who signs off on it — that responsibility belongs to
        // 'radiology-supervisor' below, mirroring Laboratory's own
        // 'laboratory-technician' (entry) vs 'laboratory-supervisor'
        // (verification/release) split exactly. `RadiologyReportPolicy::
        // independentOfAuthor()` additionally still prevents a supervisor
        // from verifying their own report if they also happen to have
        // authored it themselves.
        'radiologist' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-results.view', 'radiology-results.create', 'radiology-results.update', 'radiology-results.submit',
        ],

        // The independent verification/release role (Phase 5, Steps 43-44)
        // — the only role, besides 'hospital-admin', permitted to review,
        // verify, return, or release a radiology report. Also holds every
        // 'radiologist' ability, so a supervisor can author reports too
        // (mirrors 'laboratory-supervisor' holding every
        // 'laboratory-technician' ability plus its own verification set).
        'radiology-supervisor' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'radiology.view', 'radiology-orders.view', 'radiology-procedures.view',
            'radiology-results.view', 'radiology-results.create', 'radiology-results.update', 'radiology-results.submit',
            'radiology-results.review', 'radiology-results.verify', 'radiology-results.return', 'radiology-results.release',
        ],

        'laboratory-technician' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-tests.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-reports.view',
        ],

        'laboratory-supervisor' => [
            'dashboard.view',
            'practitioners.view', 'practitioners.nhif-login',
            'patients.view',
            'laboratory.view', 'laboratory-orders.view', 'laboratory-tests.view',
            'laboratory-samples.view', 'laboratory-samples.collect', 'laboratory-samples.label', 'laboratory-samples.receive', 'laboratory-samples.reject', 'laboratory-samples.recollect', 'laboratory-samples.cancel',
            'laboratory-processing.view', 'laboratory-processing.accept', 'laboratory-processing.assign', 'laboratory-processing.start', 'laboratory-processing.complete', 'laboratory-processing.reject', 'laboratory-processing.transfer',
            'laboratory-benches.view',
            'laboratory-results.view', 'laboratory-results.update', 'laboratory-results.submit', 'laboratory-results.amend',
            'laboratory-results.review', 'laboratory-results.verify', 'laboratory-results.authorize', 'laboratory-results.return', 'laboratory-results.amend_verified', 'laboratory-results.release',
            'laboratory-reports.view', 'laboratory-reports.print', 'laboratory-reports.download',
            'laboratory-test-parameters.manage',
        ],

        'receptionist' => [
            'dashboard.view',
            'patients.view', 'patients.create', 'patients.update',
            'patient-insurance.view', 'patient-insurance.create', 'patient-insurance.update', 'patient-insurance.verify',
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.confirm', 'appointments.reschedule', 'appointments.cancel', 'appointments.checkin', 'appointments.no_show',
            'reception.view', 'reception.dashboard', 'reception.checkin', 'reception.create_walkin', 'reception.update', 'reception.cancel', 'reception.complete',
            'nhif-authorization-context.lookup',
        ],

        // Phase 7, Step 52: the billing/payment foundation's own operational
        // role — a pre-existing stub (patient-insurance.view only) extended
        // here rather than inventing a new "Billing Officer" role, since
        // this app already has exactly one financial-facing role and the
        // spec's own suggested Cashier/Billing Officer/Finance split has no
        // backing feature yet (payment/refund/reports don't exist as
        // entities in this step). 'patients.view' is needed to search
        // patients when recording a manual charge (reuses the existing
        // patients.search-json endpoint, mirroring MedicationSearch).
        'accountant' => [
            'dashboard.view',
            'reports.view', 'reports.export',
            'patients.view',
            'patient-insurance.view',
            // Phase 9, Step 83: accountant must be able to view admissions to
            // access the inpatient billing page (controller double-gates on both
            // admission.view and billing.view).
            'admission.view',
            'billing.view', 'billing-accounts.view', 'billing-charges.view', 'billing-charges.create',
            // Phase 7, Step 53: the same billing officer manages the
            // service catalog and its pricing — this app's one financial
            // role, extended incrementally exactly as pharmacist grew
            // across Steps 47-50.
            'billing-services.view', 'billing-services.create', 'billing-services.update', 'billing-services.activate', 'billing-services.deactivate',
            'billing-pricing.view', 'billing-pricing.create', 'billing-pricing.update', 'billing-pricing.deactivate',
            // Phase 7, Step 55: the same billing officer builds and posts
            // invoices — this app's one financial role, extended
            // incrementally exactly as it already grew across Steps 52-53.
            'billing-invoices.view', 'billing-invoices.create', 'billing-invoices.post', 'billing-invoices.void',
            // Phase 7, Step 56: the same billing officer calculates
            // insurance coverage on an invoice and configures coverage
            // rules — this app's one financial role, extended incrementally
            // exactly as it already grew across Steps 52-55.
            'billing-insurance.view', 'billing-insurance.calculate',
            'billing-insurance-rules.view', 'billing-insurance-rules.create', 'billing-insurance-rules.deactivate',
            // Phase 7, Step 57: the same billing officer records patient
            // payments — this app's one financial role, extended
            // incrementally exactly as it already grew across Steps 52-56.
            'billing-payments.view', 'billing-payments.create', 'billing-payments.void',
            // Phase 7, Step 58: the same billing officer issues reversals,
            // refunds, and adjustments — the financial correction trio that
            // completes the payment lifecycle, extended incrementally the same
            // way this role grew across Steps 52-57.
            'billing-reversals.view', 'billing-reversals.create',
            'billing-refunds.view', 'billing-refunds.create',
            'billing-adjustments.view', 'billing-adjustments.create',
            // Phase 7, Step 59: the same billing officer manages cashier
            // sessions — opens a session at the start of their shift and
            // closes/reconciles it at the end.
            'billing-cashier.view', 'billing-cashier.open', 'billing-cashier.close',
            // Phase 7, Step 60: the same billing officer views financial
            // reports and exports CSV extracts.
            'billing-reports.view', 'billing-reports.export',
            // Phase 9, Step 94: accountant can view and print discharge
            // documents (financial section gated by billing.view in the UI).
            'discharge-document.view', 'discharge-document.print',
            // Procurement invoice matching & accounts payable
            'procurement.view',
            'procurement-purchase-orders.view',
            'stores-grn.view',
            'procurement-invoices.view', 'procurement-invoices.create', 'procurement-invoices.match', 'procurement-invoices.approve', 'procurement-invoices.reject',
            'procurement-vendors.view',
        ],

        'purchase-officer' => [
            'dashboard.view',
            'procurement.view', 'procurement.dashboard',
            'procurement-indents.view', 'procurement-indents.create', 'procurement-indents.update', 'procurement-indents.submit',
            'procurement-quotations.view', 'procurement-quotations.create', 'procurement-quotations.update', 'procurement-quotations.compare',
            'procurement-purchase-orders.view', 'procurement-purchase-orders.create', 'procurement-purchase-orders.update', 'procurement-purchase-orders.submit', 'procurement-purchase-orders.send',
            'procurement-invoices.view', 'procurement-invoices.create',
            'procurement-vendors.view', 'procurement-vendors.create', 'procurement-vendors.update',
            'procurement-items.view', 'procurement-items.create', 'procurement-items.update',
            'stores-inventory.view', 'stores-reports.view',
        ],

        'purchase-manager' => [
            'dashboard.view',
            'procurement.view', 'procurement.dashboard',
            'procurement-indents.view', 'procurement-indents.approve', 'procurement-indents.reject',
            'procurement-quotations.view', 'procurement-quotations.compare', 'procurement-quotations.select', 'procurement-quotations.reject',
            'procurement-purchase-orders.view', 'procurement-purchase-orders.approve', 'procurement-purchase-orders.close', 'procurement-purchase-orders.reject', 'procurement-purchase-orders.cancel',
            'procurement-invoices.view', 'procurement-invoices.match', 'procurement-invoices.approve', 'procurement-invoices.reject',
            'procurement-vendors.view', 'procurement-vendors.create', 'procurement-vendors.update',
            'procurement-items.view', 'procurement-items.create', 'procurement-items.update',
            'stores-inventory.view', 'stores-reports.view', 'stores-reports.export',
        ],

        'store-officer' => [
            'dashboard.view',
            'stores.view', 'stores.dashboard',
            'stores-grn.view', 'stores-grn.create',
            'stores-inventory.view',
            'stores-batches.view',
            'stores-requests.view',
            'stores-issues.view', 'stores-issues.create', 'stores-issues.issue',
            'stores-transfers.view', 'stores-transfers.create', 'stores-transfers.submit', 'stores-transfers.dispatch',
            'procurement-items.view', 'stores-reports.view',
        ],

        'store-manager' => [
            'dashboard.view',
            'stores.view', 'stores.dashboard',
            'stores-grn.view', 'stores-grn.create', 'stores-grn.inspect', 'stores-grn.post',
            'stores-inventory.view', 'stores-inventory.adjust',
            'stores-batches.view', 'stores-batches.quarantine', 'stores-batches.release',
            'stores-requests.view', 'stores-requests.approve', 'stores-requests.reject',
            'stores-issues.view', 'stores-issues.create', 'stores-issues.issue',
            'stores-transfers.view', 'stores-transfers.approve', 'stores-transfers.dispatch', 'stores-transfers.receive',
            'procurement-items.view', 'procurement-items.create', 'procurement-items.update',
            'stores-reports.view', 'stores-reports.export',
        ],

        'department-user' => [
            'dashboard.view',
            'procurement.view',
            'procurement-indents.view', 'procurement-indents.create', 'procurement-indents.update', 'procurement-indents.submit', 'procurement-indents.cancel',
            'stores.view',
            'stores-requests.view', 'stores-requests.create', 'stores-requests.submit', 'stores-requests.cancel',
            'stores-issues.view', 'stores-issues.receive',
            'procurement-items.view',
        ],
    ];

    /**
     * Seed the application's roles and assign their permissions.
     *
     * Uses findOrCreate() and syncPermissions() so the seeder is idempotent
     * and can be run repeatedly without creating duplicates or drifting from
     * the role/permission matrix defined above.
     */
    public function run(): void
    {
        foreach ($this->roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions($permissions === '*' ? Permission::all() : $permissions);
        }
    }
}
