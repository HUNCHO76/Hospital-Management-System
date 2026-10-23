<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inpatient\ResolveEscalationRequest;
use App\Http\Requests\Inpatient\ResolvePatientAllergyRequest;
use App\Http\Requests\Inpatient\StoreEscalationRequest;
use App\Http\Requests\Inpatient\StoreNursingHandoverRequest;
use App\Http\Requests\Inpatient\StorePatientAllergyRequest;
use App\Http\Requests\Inpatient\StoreWoundCareRequest;
use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\MedicationAdministration;
use App\Models\NursingHandover;
use App\Models\ObservationEscalation;
use App\Models\PatientAllergy;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\VitalSign;
use App\Models\WoundCareEvent;
use App\Services\AuditLogService;
use App\Services\NursingAccessService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InpatientNursingController extends Controller
{
    public function __construct(
        protected NursingAccessService $accessService,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Ward nursing dashboard — admitted patients in the nurse's authorized ward/department.
     */
    public function dashboard(Request $request): Response
    {
        abort_unless($request->user()->can('inpatient-nursing.view') || $request->user()->can('admission.view'), 403);

        $query = Admission::query()->where('status', Admission::STATUS_ACTIVE);
        $query = $this->accessService->scope($query, $request->user());

        $filters = $request->only(['ward_type', 'room_type', 'department_id', 'clinical_status', 'search']);

        if (! empty($filters['ward_type'])) {
            $query->where('ward_type', $filters['ward_type']);
        }
        if (! empty($filters['room_type'])) {
            $query->where('room_type', $filters['room_type']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (! empty($filters['clinical_status'])) {
            $query->where('clinical_status', $filters['clinical_status']);
        }
        if (! empty($filters['search'])) {
            $term = "%{$filters['search']}%";
            $query->where(function ($q) use ($term) {
                $q->where('admission_number', 'like', $term)
                    ->orWhereHas('patient', function ($pq) use ($term) {
                        $pq->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('patient_number', 'like', $term);
                    });
            });
        }

        $admissions = $query->with([
            'patient:id,patient_number,first_name,middle_name,last_name,gender,date_of_birth',
            'department:id,name',
            'hospital:id,name',
            'practitioner.staff:id,first_name,last_name',
        ])
            ->orderBy('admitted_at')
            ->paginate(20)
            ->withQueryString();

        $admissionIds = $admissions->pluck('id')->all();
        $patientIds = $admissions->pluck('patient_id')->unique()->all();

        // Count open escalations per admission
        $openEscalations = ObservationEscalation::query()
            ->whereIn('admission_id', $admissionIds)
            ->where('status', ObservationEscalation::STATUS_OPEN)
            ->selectRaw('admission_id, count(*) as count')
            ->groupBy('admission_id')
            ->pluck('count', 'admission_id')
            ->all();

        // Active allergies count per patient
        $activeAllergiesCount = PatientAllergy::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', PatientAllergy::STATUS_ACTIVE)
            ->selectRaw('patient_id, count(*) as count')
            ->groupBy('patient_id')
            ->pluck('count', 'patient_id')
            ->all();

        // Latest vitals per admission
        $latestVitals = VitalSign::query()
            ->whereIn('admission_id', $admissionIds)
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('admission_id')
            ->keyBy('admission_id');

        return Inertia::render('inpatient/nursing-dashboard', [
            'admissions' => $admissions,
            'filters' => $filters,
            'openEscalations' => $openEscalations,
            'activeAllergiesCount' => $activeAllergiesCount,
            'latestVitals' => $latestVitals,
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'clinicalStatuses' => Admission::CLINICAL_STATUSES,
            'canViewAllWards' => $request->user()->can('inpatient-nursing.view-all-wards'),
        ]);
    }

    /**
     * Dedicated Nursing Patient Workspace for an individual admitted patient.
     */
    public function workspace(Request $request, Admission $admission): Response
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $admission->load([
            'patient:id,patient_number,first_name,middle_name,last_name,gender,date_of_birth,blood_group',
            'hospital:id,name',
            'department:id,name',
            'practitioner.staff:id,first_name,last_name',
        ]);

        // Allergies
        $allergies = PatientAllergy::query()
            ->where('patient_id', $admission->patient_id)
            ->with(['medication:id,generic_name,brand_name', 'recordedBy:id,UserName', 'resolvedBy:id,UserName'])
            ->orderByDesc('created_at')
            ->get();

        // Encounters & Admission Context
        $admissionWindowStart = ($admission->admitted_at ?? $admission->created_at)->copy()->subHours(24);
        $encounters = ClinicalEncounter::query()
            ->where(function ($query) use ($admission, $admissionWindowStart) {
                $query->where('admission_id', $admission->id);
                if ($admission->clinical_encounter_id) {
                    $query->orWhere('id', $admission->clinical_encounter_id);
                }
                $query->orWhere(function ($q) use ($admission, $admissionWindowStart) {
                    $q->where('patient_id', $admission->patient_id)
                        ->where('created_at', '>=', $admissionWindowStart)
                        ->when($admission->discharged_at, fn ($sub) => $sub->where('created_at', '<=', $admission->discharged_at));
                });
            })
            ->with([
                'practitioner.staff:id,first_name,last_name',
                'diagnoses.diagnosisCode',
                'carePlan.items',
            ])
            ->orderByDesc('created_at')
            ->get();

        $encounterIds = $encounters->pluck('id')->all();

        // Inpatient Vitals
        $vitals = VitalSign::query()
            ->where(function ($q) use ($admission, $encounterIds) {
                $q->where('admission_id', $admission->id);
                if (! empty($encounterIds)) {
                    $q->orWhereIn('clinical_encounter_id', $encounterIds);
                }
            })
            ->with('recordedBy:id,UserName')
            ->orderByDesc('recorded_at')
            ->limit(30)
            ->get();

        // Nursing Notes (notes of type 'nursing', 'progress', or 'clinical_observation')
        $notes = ClinicalNote::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->with(['author:id,UserName'])
            ->orderByDesc('created_at')
            ->get();

        // Active Prescription Orders & MAR history
        $prescriptionOrders = PrescriptionOrder::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->where('status', PrescriptionOrder::STATUS_ACTIVE)
            ->with([
                'items' => fn ($q) => $q->where('status', PrescriptionOrderItem::STATUS_ACTIVE)->with('medication:id,generic_name,brand_name'),
                'items.dispensingItems',
            ])
            ->get();

        $itemIds = $prescriptionOrders->flatMap(fn ($o) => $o->items->pluck('id'))->unique();

        $marHistory = MedicationAdministration::query()
            ->whereIn('prescription_order_item_id', $itemIds)
            ->where('admission_id', $admission->id)
            ->with('administeredBy:id,UserName')
            ->orderByDesc('administered_at')
            ->limit(50)
            ->get();

        // Handovers
        $handovers = NursingHandover::query()
            ->where('admission_id', $admission->id)
            ->with(['recordedBy:id,UserName', 'receivedBy:id,UserName'])
            ->orderByDesc('handover_at')
            ->limit(20)
            ->get();

        // Wound care events
        $woundCareEvents = WoundCareEvent::query()
            ->where('admission_id', $admission->id)
            ->with('recordedBy:id,UserName')
            ->orderByDesc('performed_at')
            ->limit(20)
            ->get();

        // Escalations
        $escalations = ObservationEscalation::query()
            ->where('admission_id', $admission->id)
            ->with(['raisedBy:id,UserName', 'notifiedPractitioner.staff:id,first_name,last_name', 'vitalSign'])
            ->orderByDesc('raised_at')
            ->get();

        // Generate schedule matrix for today (treatment sheet schedule)
        $scheduleTimesConfig = config('mar.schedule_times', [
            'once_daily' => ['08:00'],
            'twice_daily' => ['08:00', '20:00'],
            'three_times_daily' => ['08:00', '14:00', '20:00'],
            'four_times_daily' => ['06:00', '12:00', '18:00', '22:00'],
        ]);

        $activeItems = $prescriptionOrders->flatMap->items;
        $todayScheduledDoses = [];
        $today = now()->format('Y-m-d');

        foreach ($activeItems as $item) {
            $times = $scheduleTimesConfig[$item->frequency] ?? [];
            foreach ($times as $time) {
                $slotDt = Carbon::parse("{$today} {$time}:00");
                $slotKey = "item:{$item->id}:".$slotDt->format('YmdHi');

                $adminRecord = $marHistory->first(function ($mar) use ($item, $slotKey, $slotDt) {
                    if ($mar->prescription_order_item_id !== $item->id) {
                        return false;
                    }
                    if ($mar->slot_key === $slotKey) {
                        return true;
                    }
                    if ($mar->scheduled_at && Carbon::parse($mar->scheduled_at)->format('Y-m-d H:i') === $slotDt->format('Y-m-d H:i')) {
                        return true;
                    }

                    return false;
                });

                $isOverdue = ! $adminRecord && now()->isAfter($slotDt->copy()->addMinutes(config('mar.overdue_after_minutes', 60)));

                $todayScheduledDoses[] = [
                    'prescription_order_item_id' => $item->id,
                    'medication_name' => $item->medication?->generic_name ?? $item->medication_name_snapshot,
                    'dose' => $item->dose,
                    'dose_unit' => $item->dose_unit,
                    'route' => $item->route,
                    'frequency' => $item->frequency,
                    'scheduled_time' => $time,
                    'scheduled_at' => $slotDt->toIso8601String(),
                    'slot_key' => $slotKey,
                    'status' => $adminRecord?->status ?? 'pending',
                    'administered_at' => $adminRecord?->administered_at?->toIso8601String(),
                    'administered_by' => $adminRecord?->administeredBy?->UserName,
                    'is_overdue' => $isOverdue,
                    'administration_id' => $adminRecord?->id,
                ];
            }
        }

        return Inertia::render('inpatient/nursing-workspace', [
            'admission' => $admission,
            'encounters' => $encounters,
            'allergies' => $allergies,
            'vitals' => $vitals,
            'notes' => $notes,
            'prescriptionOrders' => $prescriptionOrders,
            'marHistory' => $marHistory,
            'todayScheduledDoses' => $todayScheduledDoses,
            'handovers' => $handovers,
            'woundCareEvents' => $woundCareEvents,
            'escalations' => $escalations,
            'marStatuses' => MedicationAdministration::STATUSES,
            'routes' => PrescriptionOrderItem::ROUTES,
            'canRecordVitals' => $request->user()->can('vital-signs.create'),
            'canCreateNotes' => $request->user()->can('clinical-notes.create') || $request->user()->can('inpatient-nursing.notes-create'),
            'canManageWoundCare' => $request->user()->can('inpatient-nursing.wound-care-manage'),
            'canCreateHandover' => $request->user()->can('inpatient-nursing.handover-create'),
            'canEscalate' => $request->user()->can('inpatient-nursing.escalate'),
            'canAcknowledgeEscalation' => $request->user()->can('inpatient-escalations.acknowledge'),
            'canResolveEscalation' => $request->user()->can('inpatient-escalations.resolve'),
            'canManageAllergies' => $request->user()->can('patient-allergies.manage'),
            'canAdministerMedication' => $request->user()->can('inpatient.medications.administer'),
        ]);
    }

    /**
     * Record a nursing shift handover.
     */
    public function storeHandover(StoreNursingHandoverRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $handover = NursingHandover::create([
            'admission_id' => $admission->id,
            'patient_id' => $admission->patient_id,
            'shift' => $request->validated('shift'),
            'handover_at' => $request->validated('handover_at'),
            'situation_summary' => $request->validated('situation_summary'),
            'pending_tasks' => $request->validated('pending_tasks'),
            'outstanding_doses' => $request->validated('outstanding_doses'),
            'recorded_by' => $request->user()->id,
            'received_by' => $request->validated('received_by'),
        ]);

        $this->auditLog->record(
            'nursing_handover_created',
            $handover,
            null,
            ['admission_id' => $admission->id, 'shift' => $handover->shift],
            $request,
        );

        return back()->with('success', 'Nursing shift handover recorded.');
    }

    /**
     * Record a wound care / dressing event.
     */
    public function storeWoundCare(StoreWoundCareRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $event = WoundCareEvent::create([
            ...$request->validated(),
            'admission_id' => $admission->id,
            'patient_id' => $admission->patient_id,
            'recorded_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'wound_care_recorded',
            $event,
            null,
            ['admission_id' => $admission->id, 'site' => $event->site, 'concerning' => $event->concerning_findings],
            $request,
        );

        return back()->with('success', 'Wound care event documented.');
    }

    /**
     * Record a manual observation escalation.
     */
    public function storeEscalation(StoreEscalationRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $escalation = ObservationEscalation::create([
            'admission_id' => $admission->id,
            'patient_id' => $admission->patient_id,
            'vital_sign_id' => $request->validated('vital_sign_id'),
            'source' => ObservationEscalation::SOURCE_MANUAL,
            'reason' => $request->validated('reason'),
            'flags' => $request->validated('flags'),
            'notified_practitioner_id' => $request->validated('notified_practitioner_id'),
            'raised_by' => $request->user()->id,
            'raised_at' => now(),
            'status' => ObservationEscalation::STATUS_OPEN,
        ]);

        $this->auditLog->record(
            'observation_escalation_raised',
            $escalation,
            null,
            ['admission_id' => $admission->id, 'source' => 'manual'],
            $request,
        );

        return back()->with('success', 'Observation escalation communicated to clinician.');
    }

    /**
     * Acknowledge an open escalation.
     */
    public function acknowledgeEscalation(Request $request, Admission $admission, ObservationEscalation $escalation): RedirectResponse
    {
        abort_unless($request->user()->can('inpatient-escalations.acknowledge'), 403);
        abort_unless((int) $escalation->admission_id === (int) $admission->id, 404);

        $escalation->update([
            'status' => ObservationEscalation::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        $this->auditLog->record(
            'observation_escalation_acknowledged',
            $escalation,
            ['status' => ObservationEscalation::STATUS_OPEN],
            ['status' => ObservationEscalation::STATUS_ACKNOWLEDGED],
            $request,
        );

        return back()->with('success', 'Escalation acknowledged.');
    }

    /**
     * Resolve / close an escalation with a clinical resolution note.
     */
    public function resolveEscalation(ResolveEscalationRequest $request, Admission $admission, ObservationEscalation $escalation): RedirectResponse
    {
        abort_unless($request->user()->can('inpatient-escalations.resolve'), 403);
        abort_unless((int) $escalation->admission_id === (int) $admission->id, 404);

        $escalation->update([
            'status' => ObservationEscalation::STATUS_RESOLVED,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_note' => $request->validated('resolution_note'),
        ]);

        $this->auditLog->record(
            'observation_escalation_resolved',
            $escalation,
            ['status' => $escalation->getOriginal('status')],
            ['status' => ObservationEscalation::STATUS_RESOLVED, 'resolution' => $escalation->resolution_note],
            $request,
        );

        return back()->with('success', 'Escalation resolved and documented.');
    }

    /**
     * Document a patient allergy.
     */
    public function storeAllergy(StorePatientAllergyRequest $request, Admission $admission): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);

        $allergy = PatientAllergy::create([
            ...$request->validated(),
            'patient_id' => $admission->patient_id,
            'status' => PatientAllergy::STATUS_ACTIVE,
            'recorded_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'patient_allergy_recorded',
            $allergy,
            null,
            ['patient_id' => $admission->patient_id, 'allergen' => $allergy->allergen, 'severity' => $allergy->severity],
            $request,
        );

        return back()->with('success', "Allergy to {$allergy->allergen} documented.");
    }

    /**
     * Mark an allergy as resolved with an auditable reason.
     */
    public function resolveAllergy(ResolvePatientAllergyRequest $request, Admission $admission, PatientAllergy $allergy): RedirectResponse
    {
        abort_unless($this->accessService->canAccess($request->user(), $admission), 403);
        abort_unless((int) $allergy->patient_id === (int) $admission->patient_id, 404);

        $allergy->update([
            'status' => PatientAllergy::STATUS_RESOLVED,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_reason' => $request->validated('resolution_reason'),
        ]);

        $this->auditLog->record(
            'patient_allergy_resolved',
            $allergy,
            ['status' => PatientAllergy::STATUS_ACTIVE],
            ['status' => PatientAllergy::STATUS_RESOLVED, 'reason' => $allergy->resolution_reason],
            $request,
        );

        return back()->with('success', "Allergy to {$allergy->allergen} resolved.");
    }
}
