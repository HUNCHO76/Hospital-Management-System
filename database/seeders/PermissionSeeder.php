<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * The permissions available in the system, grouped by module.
     *
     * @var array<string, list<string>>
     */
    protected array $permissions = [
        'dashboard' => ['view'],
        'reports' => ['view', 'export'],
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'permissions' => ['view', 'assign'],
        'hospitals' => ['view', 'create', 'update', 'delete'],
        'departments' => ['view', 'create', 'update', 'delete'],
        'staff' => ['view', 'create', 'update', 'delete'],
        'practitioners' => ['view', 'create', 'update', 'delete', 'nhif-login'],
        'patients' => ['view', 'create', 'update', 'delete', 'correct-demographics'],
        'insurance-providers' => ['view', 'create', 'update', 'delete'],
        'insurance-schemes' => ['view', 'create', 'update', 'delete'],
        'patient-insurance' => ['view', 'create', 'update', 'delete', 'verify'],
        'appointments' => ['view', 'create', 'update', 'confirm', 'reschedule', 'cancel', 'checkin', 'complete', 'no_show'],
        'reception' => ['view', 'dashboard', 'checkin', 'create_walkin', 'update', 'cancel', 'complete'],
        'clinical-encounters' => ['view', 'create', 'start', 'complete', 'cancel'],
        'triage' => ['view', 'create', 'update', 'complete', 'cancel'],
        'vital-signs' => ['view', 'create', 'correct'],
        'consultation' => ['view', 'create', 'update', 'complete', 'cancel', 'correct'],
        'diagnosis' => ['view', 'create', 'update', 'primary', 'status', 'cancel', 'correct'],
        'diagnosis-codes' => ['view'],
        'treatment' => ['view', 'create', 'update', 'activate', 'complete', 'cancel', 'correct'],
        'prescription' => ['view', 'create', 'update', 'cancel'],
        // Phase 6, Step 46: the first-class PrescriptionOrder module
        // (prescription written directly against a ClinicalEncounter, never
        // nested under a CarePlanItem) — deliberately its own group, not
        // reusing 'prescription' above (the pre-existing, unrelated
        // CarePlanItem-nested Prescription), mirroring 'radiology-orders'/
        // 'laboratory-orders' as their own group each time an order type was
        // promoted to a first-class encounter entity.
        'prescription-orders' => ['view', 'create', 'update', 'cancel'],
        // Phase 6, Step 45: grown from the 'view'-only stub Step 44 declared
        // into the full create/update/activate/deactivate set, mirroring
        // 'laboratory-tests'/'radiology-procedures' exactly now that a real
        // catalog CRUD UI exists to use them.
        'medications' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'clinical-notes' => ['view', 'create', 'update', 'sign', 'amend', 'cancel'],
        'clinical-history' => ['view'],
        'laboratory' => ['view'],
        'laboratory-orders' => ['view', 'create', 'update', 'cancel'],
        'laboratory-samples' => ['view', 'collect', 'label', 'receive', 'reject', 'recollect', 'cancel'],
        'laboratory-processing' => ['view', 'accept', 'assign', 'start', 'complete', 'reject', 'transfer'],
        'laboratory-benches' => ['view', 'manage'],
        'laboratory-results' => ['view', 'update', 'submit', 'amend', 'review', 'verify', 'authorize', 'return', 'amend_verified', 'release'],
        'laboratory-reports' => ['view', 'print', 'download'],
        'laboratory-tests' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'laboratory-categories' => ['view', 'manage'],
        'laboratory-test-parameters' => ['manage'],
        'radiology' => ['view'],
        'radiology-orders' => ['view', 'create', 'update', 'cancel'],
        'radiology-procedures' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'radiology-scheduling' => ['view', 'create', 'reschedule', 'confirm', 'cancel', 'no_show'],
        'radiology-reception' => ['view', 'manage'],
        'radiology-examination' => ['view', 'start', 'update', 'complete', 'abort'],
        'radiology-results' => ['view', 'create', 'update', 'submit', 'review', 'verify', 'return', 'release'],
        // Phase 6, Step 44: only the base module and worklist views this
        // step actually builds are declared here — 'pharmacy-prescriptions'
        // mirrors 'radiology-orders'/'laboratory-orders' as its own group
        // rather than a flat 'pharmacy.*' namespace, following this
        // codebase's existing one-group-per-worklist convention. Verify/
        // dispensing/stock/reports permissions are deliberately NOT declared
        // yet — they belong to Steps 47-50, which will add them the same
        // incremental way radiology-scheduling/-reception/-examination were
        // each added only when their own step actually needed them.
        'pharmacy' => ['view'],
        'pharmacy-prescriptions' => ['view'],
        // Phase 6, Step 47: the independent pharmacy review gate between
        // prescribing and dispensing. 'resubmit' deliberately has no
        // permission of its own — it reuses 'prescription-orders.update'
        // (already granted to the prescriber/doctor role), since resubmitting
        // is the prescriber's own action on their own order, not a pharmacy
        // review action. Mirrors 'radiology-results' review/verify/return
        // exactly.
        'pharmacy-verification' => ['view', 'review', 'verify', 'return'],
        // Phase 6, Step 48: the actual fulfillment of an already-VERIFIED
        // prescription. No separate 'update' ability — unlike verification,
        // there is no distinct "edit while pending" step; a single
        // 'complete' call records every item's quantity atomically. Mirrors
        // 'pharmacy-verification' as its own group rather than folding into
        // it, since dispensing is a genuinely separate workflow stage
        // (spec §3).
        'pharmacy-dispensing' => ['view', 'start', 'complete', 'cancel'],
        // Phase 6, Step 49: the ledgered inventory layer behind dispensing.
        // 'adjust' covers both ordinary count corrections and
        // expired/damaged write-offs — spec's own permission list has no
        // separate ability for those, they are just a reason-classified
        // adjustment. No 'transfer' permission: this system has no
        // multi-location pharmacy architecture to transfer stock between
        // (see create_stock_items_table migration's own docblock).
        'pharmacy-stock' => ['view', 'receive', 'adjust', 'quarantine', 'release', 'view_transactions'],
        // Phase 6, Step 50: cross-cutting reporting/analytics over the
        // prescription/verification/dispensing/stock data Steps 44-49
        // already own — a single group for the whole reports module, never
        // a permission per report page, mirroring 'laboratory-reports' (one
        // group covering view/print/download) rather than spec's own
        // suggested per-report-type permission list.
        'pharmacy-reports' => ['view', 'export'],
        // Phase 7, Step 52: the billing/payment foundation —
        // 'billing.view' gates the dashboard; the two sub-modules are their
        // own groups mirroring 'pharmacy-stock'/'pharmacy-verification'
        // exactly, not a flat 'billing.*' namespace. 'billing-charges.create'
        // also covers post/void/cancel (ChargePolicy::manage()) — spec's own
        // permission list has no separate ability per state transition, the
        // same reasoning 'pharmacy-stock.adjust' already covers ordinary
        // corrections and expired/damaged write-offs alike. No payment/
        // receipt/refund/adjustment permissions: those entities don't exist
        // yet (Steps 57/58 own them).
        'billing' => ['view'],
        'billing-accounts' => ['view'],
        'billing-charges' => ['view', 'create'],
        // Phase 7, Step 55: invoices. 'create' also covers building the
        // draft (add/remove charges) — the same reasoning
        // 'billing-charges.create' already folds its own lifecycle
        // management into one ability. 'post' and 'void' are their own
        // separate abilities (not folded into 'create' the way charge
        // post/void/cancel are) because spec §33 explicitly anticipates a
        // "Billing Staff" (create/post) vs "Billing Supervisor" (void/
        // correct) role split for invoices specifically — kept as distinct
        // grantable permissions so that split is possible later without a
        // schema change, even though both are granted to the same two
        // roles for now (this app still has only one financial-facing
        // role; spec §29's own "do not invent a Cashier/Billing
        // Officer/Finance split with no backing feature yet" precedent,
        // carried forward unchanged from Step 52).
        'billing-invoices' => ['view', 'create', 'post', 'void'],
        // Phase 7, Step 53: the central billing service catalog + pricing.
        // 'billing-services' mirrors 'medications'/'laboratory-tests'/
        // 'radiology-procedures' exactly (view/create/update/activate/
        // deactivate, no delete). 'billing-pricing' is create-or-cancel only
        // for historical prices (spec §40's "prefer a new version"); the sole
        // exception is 'update', which corrects the amount of the CURRENT
        // open-ended price in place (audit-logged, reason required) so a
        // price entered by mistake can be fixed without waiting a day.
        // Clinical/pharmacy roles are deliberately not granted either group
        // (spec §43's own "should not modify pricing").
        'billing-services' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'billing-pricing' => ['view', 'create', 'update', 'deactivate'],
        // Phase 7, Step 56: insurance billing & copayment. 'billing-insurance'
        // (view/calculate) gates the invoice-level coverage breakdown and
        // its one state-changing action; 'billing-insurance-rules'
        // (view/create/deactivate) mirrors 'billing-pricing' exactly for the
        // identical reason — a rule is create-or-cancel only, never edited
        // in place. No '.verify' permission: coverage eligibility is a
        // read-only, computed display (no live external check exists — see
        // docs/insurance.md), folded into 'billing-insurance.view' rather
        // than a separate ability with nothing distinct to gate. No
        // '.override' permission: this step implements no manual override
        // mechanism at all (spec §35's own "if not required, do not
        // implement" — no existing business process asks for one).
        'billing-insurance' => ['view', 'calculate'],
        'billing-insurance-rules' => ['view', 'create', 'deactivate'],
        // Phase 7, Step 57: payment recording. 'billing-payments.create'
        // covers the full atomic record-payment action (validate, allocate,
        // generate receipt — spec §57.19's own single transaction), mirroring
        // 'billing-charges.create' folding an entity's entire ordinary
        // lifecycle into one ability. 'void' is kept separate — mirrors
        // 'billing-invoices.void' — since markFailed() is a distinct,
        // rarer, corrective action a create-capable user should not
        // automatically also hold.
        'billing-payments' => ['view', 'create', 'void'],
        // Phase 7, Step 58: financial corrections — reversals (full reversal of
        // a posted payment), refunds (partial/full cash-back), and adjustments
        // (invoice-level credit/debit). Each is its own group: they are distinct
        // accounting events with different signatories and audit requirements,
        // mirroring 'billing-invoices'/'billing-payments' each being their own
        // group rather than folded into a flat 'billing.*' namespace.
        // 'create' covers the single atomic write action; there is no 'update'
        // or 'delete' — these are immutable, auditable correction events.
        'billing-reversals' => ['view', 'create'],
        'billing-refunds' => ['view', 'create'],
        'billing-adjustments' => ['view', 'create'],
        // Phase 7, Step 59: cashier session management — 'open' and 'close'
        // are distinct abilities because the session owner (the cashier who
        // opened it) is the only one permitted to close it; 'view' allows
        // supervisors to see all sessions without being able to open/close them.
        'billing-cashier' => ['view', 'open', 'close'],
        // Phase 7, Step 60: cross-cutting billing reporting/analytics —
        // mirrors 'pharmacy-reports' (one group for the whole module, never
        // a permission per report page). 'export' is a separate ability so
        // a read-only viewer cannot download CSV extracts.
        'billing-reports' => ['view', 'export'],
        'audit-logs' => ['view'],
        // Phase 8, Step 68: NHIF service/price mapping admin
        'nhif-service-mappings' => ['view', 'create', 'update', 'deactivate'],
        'nhif-price-sync' => ['view', 'run'],
        // Phase 8 — NHIF package price catalog (GetPricePackage sync + browser).
        'nhif-package-prices' => ['view', 'sync'],
        // Phase 8, Step 69: NHIF preauthorization. 'request' covers create +
        // submit (one atomic action: you never create a preauth without sending
        // it). 'cancel' is separate — mirrors the pattern set by
        // 'billing-cashier.close' and 'billing-payments.void': a corrective
        // action with distinct authorization from the primary create path.
        'nhif-preauthorization' => ['view', 'request', 'cancel'],
        // Phase 8 Refactor: authorization-number lookup (authorizationNo → member info → patient link)
        'nhif-authorization-context' => ['lookup'],
        // Pre-approval session workflow (request services → sync approval → issue approved services).
        // 'create' covers create + submit + sync + issue (all mutating workflow steps).
        'nhif-preapproval' => ['view', 'create'],
        // Phase 8, Step 70 schema; Phase 9, Step 71 workflow; Phase 9, Step 72
        // review/approval workflow. 'review' covers startReview +
        // returnForCorrection (both are reviewer-only actions). 'approve' and
        // 'reject' are separate so a future policy split is trivial.
        'admission' => ['view', 'create', 'review', 'approve', 'reject', 'confirm', 'discharge', 'cancel', 'transfer', 'nhif-submit'],
        // Phase 9, Step 74: Inpatient clinical care actions.
        // 'encounter.create' — open an inpatient ClinicalEncounter against an active admission.
        // 'clinical-status.update' — update the patient's clinical status (stable/critical/etc.).
        // Phase 9, Step 75: MAR — medication administration, hold, refusal, missed-dose, and correction.
        // 'medications.view' — see the MAR worklist for an admission.
        // 'medications.administer' — record a dose as administered.
        // 'medications.hold' — hold a scheduled dose with a reason.
        // 'medications.record-refusal' — document patient refusal.
        // 'medications.record-missed' — document a missed dose (e.g. patient off ward).
        // 'medications.correct' — amend an existing MAR record (immutable amendment chain).
        'inpatient' => [
            'encounter.create',
            'clinical-status.update',
            'medications.view',
            'medications.administer',
            'medications.hold',
            'medications.record-refusal',
            'medications.record-missed',
            'medications.correct',
        ],

        // Inpatient nursing workspace. 'view' is limited to the user's own
        // department (ward) unless 'view-all-wards' is also held. Vital-sign
        // recording/correction reuse 'vital-signs.create'/'vital-signs.correct',
        // and dose recording reuses the 'inpatient.medications.*' abilities.
        'inpatient-nursing' => [
            'view', 'view-all-wards', 'notes-create', 'wound-care-manage', 'handover-create', 'escalate',
        ],
        // Clinician-side handling of an observation escalation (review/close).
        'inpatient-escalations' => ['view', 'acknowledge', 'resolve'],
        // Documented patient allergies used for display and MAR checks.
        'patient-allergies' => ['view', 'manage'],
        // Maternity nursing, labour monitoring, partograph and delivery records.
        'maternity' => ['view', 'manage', 'partograph-record', 'delivery-record', 'clinical-exception'],
        // Dedicated permission for authorized nurse prescribing privileges
        'prescriptions' => ['nurse-prescribe'],
        // Doctor-ordered procedures and surgical theatre operations.
        'procedures' => ['view', 'order', 'manage'],
        'surgical' => ['theatre-manage', 'operate'],

        // Phase 9, Step 94: discharge document generation.
        // 'view' — read the document and version history.
        // 'generate' — create a new version (initial or amendment).
        // 'print' — trigger the browser print view.
        'discharge-document' => ['view', 'generate', 'print'],

        // Phase 8, Step 71: NHIF clinical service utilization. Records are made by
        // clinical completion events, never by users, so there is no 'create'.
        // 'submit' covers retry + attaching the visit's authorization number;
        // 'reconcile' is separate — recording an out-of-band NHIF outcome is a
        // corrective action with its own authorization (same reasoning as
        // 'nhif-preauthorization.cancel').
        'nhif-utilization' => ['view', 'submit', 'reconcile'],
        // Phase 8, Steps 72-74: NHIF claim preparation, submission and reconciliation.
        // 'finalize' freezes the claim (folio number + snapshot).
        // 'submit', 'view-submission', 'retry-submission' added in Step 73.
        // 'view-reconciliation'/'reconcile'/'refresh-status'/'resolve-discrepancy' added in
        // Step 74; 'reconcile' only ever re-derives from a real NHIF response, it can never
        // set APPROVED/PAID/ACCEPTED by hand (same reasoning as 'nhif-utilization.reconcile').
        'nhif-claims' => [
            'view', 'create', 'update', 'validate', 'finalize', 'cancel',
            'submit', 'view-submission', 'retry-submission',
            'view-reconciliation', 'reconcile', 'refresh-status', 'resolve-discrepancy',
            // OCS: sign folio, retrieve receipt, monthly claim, bill confirmation
            'sign', 'receipt', 'monthly-view', 'monthly-calculate', 'monthly-submit', 'bill-confirmation',
        ],
        // Phase 8, Step 75: NHIF operational dashboard (read-only aggregate view).
        'nhif-dashboard' => ['view'],

        // Procurement and Store Management Module
        'procurement' => ['view', 'dashboard'],
        'procurement-indents' => ['view', 'create', 'update', 'submit', 'approve', 'reject', 'cancel'],
        'procurement-quotations' => ['view', 'create', 'update', 'compare', 'select', 'reject'],
        'procurement-purchase-orders' => ['view', 'create', 'update', 'submit', 'approve', 'send', 'close', 'reject', 'cancel'],
        'procurement-vendors' => ['view', 'create', 'update', 'delete'],
        'procurement-invoices' => ['view', 'create', 'match', 'approve', 'reject'],
        'procurement-items' => ['view', 'create', 'update', 'activate', 'deactivate'],
        'stores' => ['view', 'dashboard'],
        'stores-grn' => ['view', 'create', 'inspect', 'post'],
        'stores-inventory' => ['view', 'adjust'],
        'stores-batches' => ['view', 'quarantine', 'release'],
        'stores-issues' => ['view', 'create', 'issue', 'receive'],
        'stores-requests' => ['view', 'create', 'submit', 'approve', 'reject', 'cancel'],
        'stores-transfers' => ['view', 'create', 'submit', 'approve', 'dispatch', 'receive'],
        'stores-reports' => ['view', 'export'],
    ];

    /**
     * Seed the application's permissions.
     *
     * Uses findOrCreate() so the seeder is idempotent and can be run repeatedly.
     */
    public function run(): void
    {
        foreach ($this->permissions as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }
    }
}
