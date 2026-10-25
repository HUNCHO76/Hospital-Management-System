<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClinicalEncounter\CancelClinicalEncounterRequest;
use App\Http\Requests\ClinicalEncounter\ChangePractitionerRequest;
use App\Http\Requests\ClinicalEncounter\CompleteClinicalEncounterRequest;
use App\Http\Requests\ClinicalEncounter\StoreClinicalEncounterRequest;
use App\Http\Resources\CarePlanResource;
use App\Http\Resources\ClinicalEncounterResource;
use App\Http\Resources\ClinicalNoteResource;
use App\Http\Resources\ConsultationResource;
use App\Http\Resources\EncounterDiagnosisResource;
use App\Http\Resources\LaboratoryOrderResource;
use App\Http\Resources\LaboratoryReportResource;
use App\Http\Resources\PrescriptionOrderResource;
use App\Http\Resources\RadiologyOrderResource;
use App\Http\Resources\RadiologyResultResource;
use App\Http\Resources\TriageAssessmentResource;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\LaboratoryReport;
use App\Models\NhifPreApprovalSession;
use App\Models\Practitioner;
use App\Models\ProcedureOrder;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Models\ReceptionVisit;
use App\Services\AuditLogService;
use App\Services\ClinicalEncounterSearchService;
use App\Services\ClinicalEncounterService;
use App\Services\FinancialAuthorizationService;
use App\Services\MedicationSafetyService;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalEncounterController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'department:id,name,code',
            'practitioner:id,practitioner_number,practitioner_type,nhif_practitioner_no,staff_id',
            'practitioner.staff:id,first_name,last_name',
            'receptionVisit:id,visit_number,status',
            'appointment:id,appointment_number,appointment_type,scheduled_start',
        ];
    }

    /**
     * Display a filterable, paginated list of clinical encounters.
     */
    public function index(Request $request, ClinicalEncounterSearchService $search): Response
    {
        $this->authorize('viewAny', ClinicalEncounter::class);

        $filters = $request->only(['search', 'patient_id', 'department_id', 'practitioner_id', 'status', 'encounter_type', 'date_from', 'date_to', 'workflow_stage']);

        $query = $search->query($filters)->with([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'department:id,name,code',
            'practitioner:id,practitioner_number,staff_id',
            'practitioner.staff:id,first_name,last_name',
            'consultation:id,clinical_encounter_id,status',
        ]);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $encounters = $query->latest('created_at')->paginate(15)->withQueryString();

        return Inertia::render('clinical-encounters/index', [
            'encounters' => $encounters,
            'filters' => $filters,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'encounterTypes' => ClinicalEncounter::TYPES,
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
            'myPractitionerId' => $request->user()->staff?->practitioner?->id,
        ]);
    }

    /**
     * Display the specified clinical encounter. Triage and vital signs
     * (Step 22) are rendered inline on this same screen (spec §38) rather
     * than a standalone page. Extension points for future clinical modules
     * (consultation, diagnosis, treatment) remain placeholders only — no
     * such content is fetched or exposed here.
     */
    public function show(Request $request, ClinicalEncounter $clinicalEncounter, AuditLogService $auditLog): Response
    {
        $this->authorize('view', $clinicalEncounter);

        $auditLog->record('clinical_encounter.viewed', $clinicalEncounter, null, [
            'patient_id' => $clinicalEncounter->patient_id,
            'encounter_id' => $clinicalEncounter->id,
            'encounter_type' => $clinicalEncounter->encounter_type,
        ], $request);

        $clinicalEncounter->load($this->eagerLoads());

        $triage = $clinicalEncounter->triageAssessment()->with([
            'assessedBy:id,UserName',
            'completedBy:id,UserName',
            'vitalSigns.recordedBy:id,UserName',
            'vitalSigns.correction',
        ])->first();

        $consultation = $clinicalEncounter->consultation()->with([
            'practitioner:id,practitioner_number,staff_id',
            'practitioner.staff:id,first_name,last_name',
            'completedBy:id,UserName',
            'correctedBy:id,UserName',
        ])->first();

        $diagnoses = $clinicalEncounter->diagnoses()->with([
            'diagnosisCode',
            'diagnosedBy:id,UserName',
            'correctedBy:id,UserName',
        ])->get();

        $carePlan = $clinicalEncounter->carePlan()->with([
            'items.encounterDiagnosis.diagnosisCode',
            'items.referredToDepartment:id,name',
            'items.referredToPractitioner:id,practitioner_number,staff_id',
            'items.referredToPractitioner.staff:id,first_name,last_name',
            'items.prescription.medication:id,generic_name,brand_name',
            'items.createdBy:id,UserName',
            'items.correctedBy:id,UserName',
            'completedBy:id,UserName',
            'correctedBy:id,UserName',
        ])->first();

        $clinicalNotes = $clinicalEncounter->clinicalNotes()->with([
            'author:id,UserName',
            'signedBy:id,UserName',
            'correctedBy:id,UserName',
            'versions.author:id,UserName',
        ])->get();

        $laboratoryOrders = $clinicalEncounter->laboratoryOrders()->with([
            // item.result is what actually carries the doctor-visible
            // draft/verified/authorized/released progress — the item's own
            // `status` never advances past "result_pending" once
            // processing completes (Steps 33-35 track that entirely on the
            // linked LaboratoryResult instead).
            'items.result',
            'orderedBy:id,UserName',
            'cancelledBy:id,UserName',
        ])->get();

        $radiologyOrders = $clinicalEncounter->radiologyOrders()->with([
            'items',
            'orderedBy:id,UserName',
            'cancelledBy:id,UserName',
        ])->get();

        $prescriptionOrders = $clinicalEncounter->prescriptionOrders()->with([
            'items',
            'prescriber:id,UserName',
            'cancelledBy:id,UserName',
            // Phase 6, Step 47: so the prescriber can see their own
            // prescription's pharmacy verification status/return reason
            // inline, without a separate page.
            'verification.returnedBy:id,UserName',
            'verification.verifiedBy:id,UserName',
        ])->get();

        // Only the currently-active (non-superseded) released laboratory report
        // per order is shown here — superseded versions never appear on the
        // encounter page (a doctor must not see a retracted result as the
        // current one). Loaded separately from $laboratoryOrders above, which
        // continues showing plain order/item status tracking for every status.
        $laboratoryResults = LaboratoryReport::query()
            ->where('status', LaboratoryReport::STATUS_ACTIVE)
            ->whereHas('laboratoryOrder', fn ($q) => $q->where('clinical_encounter_id', $clinicalEncounter->id))
            ->with([
                'laboratoryOrder',
                'laboratoryOrder.clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
                'laboratoryOrder.clinicalEncounter.department:id,name,hospital_id',
                'laboratoryOrder.clinicalEncounter.department.hospital:id,name,phone,email,address',
                'laboratoryOrder.orderedBy:id,UserName',
                'releasedBy:id,UserName',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        // Only RELEASED radiology reports are ever presented as official
        // clinical results on the encounter page (Phase 5, Step 44, Part
        // 15/18) — a draft, submitted, or verified-but-not-yet-released
        // report must never be mistaken for one here. Loaded separately
        // from $radiologyOrders above (which keeps showing plain order/item
        // status tracking, unchanged) since this needs the full report
        // content chain.
        $radiologyResults = $clinicalEncounter->radiologyOrders()->with([
            'items.radiologyAppointments.report.reportingPractitioner.staff.user',
            'items.radiologyAppointments.report.verifyingPractitioner.staff.user',
            'items.radiologyAppointments.report.releasedBy:id,UserName',
            'items.radiologyAppointments.images',
            'items.radiologyAppointments.images.uploadedBy:id,UserName',
        ])->get()
            ->flatMap(fn ($order) => $order->items)
            // The appointment's own inverse relation back to its item is not
            // populated by the item.radiologyAppointments eager load above
            // (Eloquent does not auto-set inverse relations), and
            // RadiologyResultResource needs it for procedure_code/name — set
            // it explicitly rather than triggering a second query per row.
            ->flatMap(fn ($item) => $item->radiologyAppointments->each(fn (RadiologyAppointment $appointment) => $appointment->setRelation('radiologyOrderItem', $item)))
            ->filter(fn (RadiologyAppointment $appointment) => $appointment->report?->status === RadiologyReport::STATUS_RELEASED)
            ->map(fn (RadiologyAppointment $appointment) => (new RadiologyResultResource($appointment))->resolve($request))
            ->values();

        $nhifPreApprovalService = app(NhifOrderPreApprovalService::class);
        $procedureOrders = $clinicalEncounter->procedureOrders()->with([
            'service',
            'prescribedBy:id,UserName',
            'performer:id,UserName',
            'charge',
            'invoice',
        ])->get()->map(function (ProcedureOrder $order) use ($nhifPreApprovalService) {
            $nhifStatus = $nhifPreApprovalService->statusForProcedureOrder($order);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'procedure_name' => $order->procedure_name,
                'procedure_type' => $order->procedure_type,
                'urgency' => $order->urgency,
                'clinical_indication' => $order->clinical_indication,
                'status' => $order->status,
                'payment_mode' => $order->payment_mode,
                'billing_status' => $order->billing_status,
                'quantity' => $order->quantity,
                'unit_price' => (float) $order->unit_price,
                'total_price' => (float) $order->total_price,
                'charge_id' => $order->charge_id,
                'invoice_id' => $order->invoice_id,
                'is_paid' => $order->isPaid(),
                'is_authorized' => $order->isAuthorized(),
                'can_be_executed' => $order->canBeExecuted(),
                'is_emergency_exemption' => $order->is_emergency_exemption,
                'exemption_reason' => $order->exemption_reason,
                'nhif_item_code' => $order->nhif_item_code,
                'nhif_approval_status' => $order->nhif_approval_status,
                'nhif_authorization_no' => $order->nhif_authorization_no,
                'nhif_rejection_reason' => $order->nhif_rejection_reason,
                'nhif_pre_approval' => $nhifStatus,
                'prescribed_by' => $order->prescribedBy ? ['id' => $order->prescribedBy->id, 'UserName' => $order->prescribedBy->UserName] : null,
                'performer' => $order->performer ? ['id' => $order->performer->id, 'UserName' => $order->performer->UserName] : null,
                'performed_at' => $order->performed_at?->toISOString(),
                'scheduled_at' => $order->scheduled_at?->toISOString(),
                'findings' => $order->findings,
                'results' => $order->results,
                'procedure_notes' => $order->procedure_notes,
                'complications' => $order->complications,
                'notes' => $order->notes,
                'created_at' => $order->created_at->toISOString(),
            ];
        })->values()->all();

        return Inertia::render('clinical-encounters/show', [
            'encounter' => (new ClinicalEncounterResource($clinicalEncounter))->resolve($request),
            'triage' => $triage ? (new TriageAssessmentResource($triage))->resolve($request) : null,
            'consultation' => $consultation ? (new ConsultationResource($consultation))->resolve($request) : null,
            'diagnoses' => EncounterDiagnosisResource::collection($diagnoses)->resolve($request),
            'carePlan' => $carePlan ? (new CarePlanResource($carePlan))->resolve($request) : null,
            'laboratoryOrders' => LaboratoryOrderResource::collection($laboratoryOrders)->resolve($request),
            'laboratoryResults' => LaboratoryReportResource::collection($laboratoryResults)->resolve($request),
            'radiologyOrders' => RadiologyOrderResource::collection($radiologyOrders)->resolve($request),
            'radiologyResults' => $radiologyResults,
            'prescriptionOrders' => PrescriptionOrderResource::collection($prescriptionOrders)->resolve($request),
            'procedureOrders' => $procedureOrders,
            'clinicalNotes' => ClinicalNoteResource::collection($clinicalNotes)->resolve($request),
            // Only needed for the care plan's referral item form.
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'referralPractitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
            // Step 62: computed on-the-fly so the UI can show financial status
            // without polling a separate endpoint — cashiers/reception see this
            // immediately after payment is posted.
            'consultationFinancialStatus' => app(FinancialAuthorizationService::class)
                ->statusForSource(ClinicalEncounter::class, $clinicalEncounter->id),
            // Medication safety rules pre-filtered to the patient's active
            // diagnoses, so the prescription form can warn the prescriber
            // before submission (spec §12). Keyed by medication_id.
            'medicationSafetyRules' => app(MedicationSafetyService::class)
                ->warningsForDiagnoses(
                    app(MedicationSafetyService::class)->activeDiagnosisCodeIds($clinicalEncounter->id),
                ),
            // Whether the patient has current NHIF insurance — used
            // for pre-approvals, diagnosis disease fields, and pre-approval item tracking.
            'nhifEligible' => $clinicalEncounter->patient?->currentNhifInsurance() !== null,
            'nhifInsurance' => ($currentInsurance = $clinicalEncounter->patient?->currentNhifInsurance()) ? [
                ...$currentInsurance->only([
                    'id', 'card_number', 'nhif_authorization_no', 'nhif_scheme_id',
                    'nhif_scheme_name', 'nhif_facility_code', 'nhif_pf_number',
                ]),
                'is_authorization_expired' => $currentInsurance->isAuthorizationExpired(),
                'has_valid_authorization' => $currentInsurance->hasValidAuthorization(),
                'authorization_hours_remaining' => $currentInsurance->authorizationHoursRemaining(),
                'authorization_expires_at' => $currentInsurance->authorizationExpiresAt()?->toISOString(),
            ] : null,
            'nhifPreApprovalSessions' => NhifPreApprovalSession::with('items')
                ->where('clinical_encounter_id', $clinicalEncounter->id)
                ->latest()
                ->get(),
            'nhifPendingItems' => Inertia::defer(fn () => $clinicalEncounter->patient?->currentNhifInsurance()
                ? app(NhifEncounterPreApprovalService::class)
                    ->prepareItems($clinicalEncounter, $clinicalEncounter->patient->currentNhifInsurance())
                : null),
        ]);
    }

    /**
     * Show the guided clinical encounter creation form.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', ClinicalEncounter::class);

        $patientId = $request->integer('patient_id') ?: null;
        $receptionVisitId = $request->integer('reception_visit_id') ?: null;

        return Inertia::render('clinical-encounters/create', [
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number', 'practitioner_type']),
            'encounterTypes' => ClinicalEncounter::TYPES,
            'patientId' => $patientId,
            'receptionVisit' => $receptionVisitId
                ? ReceptionVisit::query()->with('patient:id,patient_number,first_name,middle_name,last_name,phone')->find($receptionVisitId)
                : null,
        ]);
    }

    /**
     * Create a clinical encounter.
     */
    public function store(StoreClinicalEncounterRequest $request, ClinicalEncounterService $service): RedirectResponse
    {
        $this->authorize('create', ClinicalEncounter::class);

        $encounter = $service->create($request->validated(), $request);

        return to_route('clinical-encounters.show', $encounter)->with('success', "Clinical encounter {$encounter->encounter_number} created.");
    }

    /**
     * Start a clinical encounter.
     */
    public function start(Request $request, ClinicalEncounter $clinicalEncounter, ClinicalEncounterService $service): RedirectResponse
    {
        $this->authorize('start', $clinicalEncounter);

        $service->start($clinicalEncounter, $request);

        return back()->with('success', 'Clinical encounter started.');
    }

    /**
     * Complete a clinical encounter, recording its clinical outcome.
     */
    public function complete(CompleteClinicalEncounterRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalEncounterService $service): RedirectResponse
    {
        $this->authorize('complete', $clinicalEncounter);

        $service->complete($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Clinical encounter completed.');
    }

    /**
     * Cancel a clinical encounter.
     */
    public function cancel(CancelClinicalEncounterRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalEncounterService $service): RedirectResponse
    {
        $this->authorize('cancel', $clinicalEncounter);

        $service->cancel($clinicalEncounter, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Clinical encounter cancelled.');
    }

    /**
     * Reassign the practitioner on an encounter before the consultation has
     * started, recalculating the consultation charge if the new practitioner's
     * billing configuration differs (Step 62, spec §7).
     */
    public function changePractitioner(ChangePractitionerRequest $request, ClinicalEncounter $clinicalEncounter, ClinicalEncounterService $service): RedirectResponse
    {
        $this->authorize('update', $clinicalEncounter);

        $validated = $request->validated();

        $service->changePractitioner($clinicalEncounter, (int) $validated['practitioner_id'], $validated['reason'], $request);

        return back()->with('success', 'Practitioner updated.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
