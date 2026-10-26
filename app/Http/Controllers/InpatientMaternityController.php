<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inpatient\StoreDeliveryRecordRequest;
use App\Http\Requests\Inpatient\StoreMaternityProfileRequest;
use App\Http\Requests\Inpatient\StorePartographEntryRequest;
use App\Http\Requests\LaboratoryOrder\StoreLaboratoryOrderRequest;
use App\Http\Requests\PrescriptionOrder\StorePrescriptionOrderRequest;
use App\Http\Requests\RadiologyOrder\StoreRadiologyOrderRequest;
use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\DeliveryRecord;
use App\Models\LaboratoryOrder;
use App\Models\MaternityProfile;
use App\Models\ObservationEscalation;
use App\Models\PartographEntry;
use App\Models\Practitioner;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use App\Models\VitalSign;
use App\Services\InpatientClinicalService;
use App\Services\LaboratoryOrderService;
use App\Services\MaternityEligibilityService;
use App\Services\MaternityService;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\NHIF\NhifLabAuthorizationService;
use App\Services\NursingAccessService;
use App\Services\PrescriptionOrderService;
use App\Services\RadiologyOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for Inpatient Maternity Nursing, Labour Monitoring & WHO-compliant Partograph.
 */
class InpatientMaternityController extends Controller
{
    public function __construct(
        protected NursingAccessService $accessService,
        protected MaternityService $maternityService,
        protected MaternityEligibilityService $eligibilityService,
    ) {}

    /**
     * Display the maternity nursing and labour workspace for an admitted patient.
     */
    public function show(Request $request, Admission $admission): Response
    {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);

        $admission->load([
            'patient',
            'department:id,name',
            'practitioner.staff:id,first_name,last_name',
            'nhifAdmission',
            'patientInsurance.insuranceProvider',
        ]);

        $profile = MaternityProfile::query()
            ->where('admission_id', $admission->id)
            ->with([
                'recordedBy:id,UserName',
                'deliveryRecord.conductedBy:id,UserName',
                'deliveryRecord.assistedBy:id,UserName',
                'deliveryRecord.babyPatient:id,patient_number,first_name,last_name',
                'assignedMidwife:id,UserName',
                'primaryClinician:id,UserName',
            ])
            ->latest()
            ->first();

        $partographEntries = $profile ? $profile->partographEntries()
            ->with('recordedBy:id,UserName')
            ->orderBy('recorded_at')
            ->get() : [];

        $practitioners = Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->with(['staff' => fn ($q) => $q->select('id', 'first_name', 'last_name', 'user_id')])
            ->get(['id', 'staff_id'])
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'user_id' => $p->staff?->user_id,
                    'name' => $p->staff ? trim("{$p->staff->first_name} {$p->staff->last_name}") : "Practitioner #{$p->id}",
                    'staff' => $p->staff,
                ];
            });

        $latestVital = VitalSign::query()
            ->where('admission_id', $admission->id)
            ->latest('recorded_at')
            ->first();

        $clinicalAlerts = ObservationEscalation::query()
            ->where('admission_id', $admission->id)
            ->where('status', ObservationEscalation::STATUS_OPEN)
            ->latest('created_at')
            ->get();

        $encounterIds = array_filter([
            $admission->clinical_encounter_id,
            ...$admission->inpatientEncounters()->pluck('id')->all(),
        ]);

        $medications = ! empty($encounterIds)
            ? PrescriptionOrder::query()
                ->whereIn('clinical_encounter_id', $encounterIds)
                ->where('status', PrescriptionOrder::STATUS_ACTIVE)
                ->with([
                    'items' => fn ($q) => $q->where('status', PrescriptionOrderItem::STATUS_ACTIVE)->with('medication:id,generic_name,brand_name,strength,form'),
                    'items.dispensingItems',
                ])
                ->get()
                ->flatMap->items
            : collect();

        $laboratoryOrders = ! empty($encounterIds)
            ? LaboratoryOrder::query()
                ->whereIn('clinical_encounter_id', $encounterIds)
                ->with([
                    'items.laboratoryTest',
                    'items.result',
                    'orderedBy:id,UserName',
                ])
                ->latest('ordered_at')
                ->get()
            : collect();

        $prescriptionOrders = ! empty($encounterIds)
            ? PrescriptionOrder::query()
                ->whereIn('clinical_encounter_id', $encounterIds)
                ->with([
                    'items.medication',
                    'items.dispensingItems',
                    'prescriber:id,UserName',
                    'verification',
                ])
                ->latest('created_at')
                ->get()
            : collect();

        $radiologyOrders = ! empty($encounterIds)
            ? RadiologyOrder::query()
                ->whereIn('clinical_encounter_id', $encounterIds)
                ->with([
                    'items.radiologyProcedure',
                    'items.activeRadiologyAppointment.report.reportingPractitioner.staff.user',
                    'items.activeRadiologyAppointment.report.verifyingPractitioner.staff.user',
                    'items.activeRadiologyAppointment.report.releasedBy:id,UserName',
                    'items.activeRadiologyAppointment.images.uploadedBy:id,UserName',
                    'orderedBy:id,UserName',
                ])
                ->latest('ordered_at')
                ->get()
            : collect();

        $radiologyProcedures = RadiologyProcedure::query()
            ->where('status', RadiologyProcedure::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'modality', 'price']);

        $activeEncounter = ! empty($encounterIds)
            ? ClinicalEncounter::whereIn('id', $encounterIds)
                ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
                ->latest()
                ->first()
            : null;

        $nhifClearance = $this->eligibilityService->checkNhifClearance(
            $admission,
            $admission->patient,
            $admission->clinicalEncounter
        );

        $eligibility = $this->eligibilityService->checkEligibility($admission->patient);

        return Inertia::render('inpatient/maternity-workspace', [
            'admission' => $admission,
            'profile' => $profile,
            'is_registered' => $profile !== null,
            'episode_number' => $profile?->episode_number,
            'care_pathway' => $profile?->care_pathway ?? MaternityProfile::CARE_PATHWAY_INPATIENT,
            'partographEntries' => $partographEntries,
            'deliveryRecord' => $profile?->deliveryRecord,
            'practitioners' => $practitioners,
            'eligibility' => $eligibility,
            'nhif_clearance' => $nhifClearance,
            'latestVital' => $latestVital,
            'clinicalAlerts' => $clinicalAlerts,
            'medications' => $medications,
            'laboratoryOrders' => $laboratoryOrders,
            'prescriptionOrders' => $prescriptionOrders,
            'radiologyOrders' => $radiologyOrders,
            'radiologyProcedures' => $radiologyProcedures,
            'canOrderLabs' => $request->user()->can('laboratory-orders.create') || $request->user()->can('maternity.manage') || $request->user()->can('inpatient-nursing.view'),
            'canPrescribe' => $request->user()->can('prescription-orders.create') || $request->user()->can('prescriptions.nurse-prescribe') || $request->user()->can('maternity.manage'),
            'canOrderRadiology' => $request->user()->can('radiology-orders.create') || $request->user()->can('maternity.manage') || $request->user()->can('inpatient-nursing.view'),
            'activeEncounterId' => $activeEncounter?->id,
            'constants' => [
                'amniotic_fluid_states' => PartographEntry::AMNIOTIC_FLUID_STATES,
                'moulding_grades' => PartographEntry::MOULDING_GRADES,
                'contraction_durations' => PartographEntry::CONTRACTION_DURATIONS,
                'presentations' => MaternityProfile::PRESENTATIONS,
                'membrane_statuses' => MaternityProfile::MEMBRANE_STATUSES,
                'hiv_statuses' => MaternityProfile::HIV_STATUSES,
                'care_stages' => MaternityProfile::CARE_STAGES,
                'care_pathways' => MaternityProfile::CARE_PATHWAYS,
                'labour_stages' => MaternityProfile::LABOUR_STAGES,
                'delivery_modes' => DeliveryRecord::MODES,
                'perineal_statuses' => DeliveryRecord::PERINEAL_STATUSES,
                'baby_outcomes' => DeliveryRecord::BABY_OUTCOMES,
            ],
        ]);
    }

    /**
     * Store or update maternity profile (obstetric history & labour episode).
     */
    public function storeProfile(StoreMaternityProfileRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless(
            $request->user()->hasRole('super-admin')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('maternity.view')
            || $request->user()->can('inpatient-nursing.view')
            || $request->user()->hasAnyRole(['midwife', 'nurse', 'doctor']),
            403,
            'User does not possess maternity clinical privileges.'
        );

        $this->maternityService->saveProfile($admission, $request->validated(), $request);

        return back()->with('success', 'Maternity profile updated successfully.');
    }

    /**
     * Record a WHO-compliant partograph observation entry.
     */
    public function storePartograph(
        StorePartographEntryRequest $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);

        $this->maternityService->recordPartograph($profile, $request->validated(), $request);

        return back()->with('success', 'Partograph reading recorded successfully.');
    }

    /**
     * Transition the care stage of a maternity episode.
     */
    public function updateStage(
        Request $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);

        $validated = $request->validate([
            'care_stage' => ['required', 'string', Rule::in(array_keys(MaternityProfile::CARE_STAGES))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->maternityService->transitionStage(
            $profile,
            $validated['care_stage'],
            $request,
            $validated['notes'] ?? null,
            (bool) ($validated['is_emergency'] ?? false),
            $validated['emergency_reason'] ?? null
        );

        return back()->with('success', 'Maternity care stage updated successfully.');
    }

    /**
     * Document delivery outcome.
     */
    public function storeDelivery(
        StoreDeliveryRecordRequest $request,
        Admission $admission,
        MaternityProfile $profile
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless((int) $profile->admission_id === (int) $admission->id, 404);

        $this->maternityService->recordDelivery($profile, $request->validated(), $request);

        return back()->with('success', 'Delivery and newborn outcome documented successfully.');
    }

    /**
     * Order laboratory tests for a maternity patient.
     */
    public function storeLaboratoryOrder(
        StoreLaboratoryOrderRequest $request,
        Admission $admission,
        LaboratoryOrderService $service,
        InpatientClinicalService $clinicalService,
        NhifLabAuthorizationService $nhifLab,
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless(
            $request->user()->can('laboratory-orders.create')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view'),
            403
        );

        $encounter = $this->resolveOrCreateInpatientEncounter($admission, $request, $clinicalService);

        $order = $service->create($encounter, $request->validated(), $request);

        // Best-effort NHIF authorization for insured maternity patients
        try {
            $nhifLab->authorizeOrder($order, $encounter, $request);
        } catch (\Throwable $e) {
            Log::warning('NHIF lab authorization in maternity failed (non-blocking)', [
                'laboratory_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $redirect = back()->with('success', "Laboratory order {$order->order_number} placed successfully.");

        if ($order->billing_warning ?? null) {
            $redirect->with('warning', $order->billing_warning);
        }

        return $redirect;
    }

    /**
     * Prescribe medications to pharmacy for a maternity patient.
     */
    public function storePrescriptionOrder(
        StorePrescriptionOrderRequest $request,
        Admission $admission,
        PrescriptionOrderService $service,
        InpatientClinicalService $clinicalService,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless(
            $request->user()->can('prescription-orders.create')
            || $request->user()->can('prescriptions.nurse-prescribe')
            || $request->user()->can('maternity.manage'),
            403
        );

        $encounter = $this->resolveOrCreateInpatientEncounter($admission, $request, $clinicalService);

        $order = $service->create($encounter, $request->validated(), $request);

        $insurance = $admission->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $encounter,
                    [],
                    $itemIds,
                    $request,
                    $order->clinical_notes,
                    $encounter->practitioner?->nhif_practitioner_no,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}).";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed for maternity prescription', [
                    'prescription_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $preApprovalMessage
            ? "Prescription order {$order->order_number} sent to pharmacy. {$preApprovalMessage}"
            : "Prescription order {$order->order_number} sent to pharmacy.";

        return back()->with('success', $message);
    }

    /**
     * Order radiology procedures / ultrasound for a maternity patient.
     */
    public function storeRadiologyOrder(
        StoreRadiologyOrderRequest $request,
        Admission $admission,
        RadiologyOrderService $service,
        InpatientClinicalService $clinicalService,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        $maternityAccess = $this->eligibilityService->checkAdmissionAccess($request->user(), $admission);
        abort_unless($maternityAccess['allowed'], 403, $maternityAccess['reason']);
        abort_unless(
            $request->user()->can('radiology-orders.create')
            || $request->user()->can('maternity.manage')
            || $request->user()->can('inpatient-nursing.view'),
            403
        );

        $encounter = $this->resolveOrCreateInpatientEncounter($admission, $request, $clinicalService);

        $order = $service->create($encounter, $request->validated(), $request);

        $insurance = $admission->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $encounter,
                    [],
                    [],
                    $request,
                    $order->clinical_indication ?? $order->notes,
                    $encounter->practitioner?->nhif_practitioner_no,
                    $itemIds,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}).";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed for maternity radiology', [
                    'radiology_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $preApprovalMessage
            ? "Radiology order {$order->order_number} placed successfully. {$preApprovalMessage}"
            : "Radiology order {$order->order_number} placed successfully.";

        return back()->with('success', $message);
    }

    /**
     * Resolve or automatically create an active inpatient clinical encounter for this admission.
     */
    protected function resolveOrCreateInpatientEncounter(
        Admission $admission,
        Request $request,
        InpatientClinicalService $clinicalService,
    ): ClinicalEncounter {
        // 1. Check existing active inpatient encounters
        $encounter = $admission->inpatientEncounters()
            ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
            ->latest()
            ->first();

        if ($encounter) {
            return $encounter;
        }

        // 2. Check admission's initial clinical encounter
        if ($admission->clinical_encounter_id) {
            $admitEncounter = ClinicalEncounter::find($admission->clinical_encounter_id);
            if ($admitEncounter && in_array($admitEncounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
                return $admitEncounter;
            }
        }

        // 3. Open a new inpatient encounter
        $practitionerId = $admission->practitioner_id
            ?? $request->user()->staff?->practitioner?->id
            ?? Practitioner::where('status', Practitioner::STATUS_ACTIVE)->value('id');

        if (! $practitionerId) {
            $practitioner = Practitioner::firstOrCreate(
                ['status' => Practitioner::STATUS_ACTIVE],
                [
                    'practitioner_number' => 'PRC-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'staff_id' => $request->user()->staff?->id ?? 1,
                    'practitioner_type' => 'nurse',
                ]
            );
            $practitionerId = $practitioner->id;
        }

        return $clinicalService->openEncounter($admission, (int) $practitionerId, $request);
    }
}
