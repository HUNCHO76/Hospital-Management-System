<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admission\OpenEncounterRequest;
use App\Http\Requests\Admission\UpdateClinicalStatusRequest;
use App\Http\Requests\VitalSign\StoreVitalSignRequest;
use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Practitioner;
use App\Models\VitalSign;
use App\Services\InpatientClinicalService;
use App\Services\VitalSignsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9, Step 74 — inpatient ward dashboard and patient clinical profile.
 *
 * All clinical write actions (notes, diagnoses, care plans, orders) go
 * through the existing encounter-scoped controllers (ClinicalNoteController,
 * CarePlanController, LaboratoryOrderController, etc.) via the standard
 * /clinical-encounters/{encounter}/... routes — never duplicated here.
 * This controller only adds: the ward dashboard, the inpatient clinical
 * profile page, opening an inpatient encounter, and updating clinical status.
 */
class InpatientController extends Controller
{
    /**
     * Ward dashboard — active admissions with latest vitals and pending orders.
     * The "inpatient.view" permission matches the existing admission.view check,
     * avoiding a new permission for the same data.
     */
    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', Admission::class);

        $filters = $request->only(['ward_type', 'room_type', 'department_id', 'hospital_id', 'patient_id', 'practitioner_id', 'date_from']);

        $hospitalId = $this->scopedHospitalId($request);

        $admissions = Admission::query()
            ->where('status', Admission::STATUS_ACTIVE)
            ->when($hospitalId, fn ($q, $id) => $q->where('hospital_id', $id))
            ->when($filters['hospital_id'] ?? null, fn ($q, $id) => $q->where('hospital_id', $id))
            ->when($filters['ward_type'] ?? null, fn ($q, $v) => $q->where('ward_type', $v))
            ->when($filters['room_type'] ?? null, fn ($q, $v) => $q->where('room_type', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($filters['practitioner_id'] ?? null, fn ($q, $id) => $q->where('practitioner_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('admitted_at', '>=', $date))
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name',
                'hospital:id,name',
                'department:id,name',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
                // Latest vital sign per admission — subquery in the select is
                // expensive at scale; eager-load then take last in the
                // resource, acceptable for ward-size volumes (10–100 patients).
                'inpatientEncounters' => fn ($q) => $q->latest('created_at')->limit(1),
                'inpatientEncounters.clinicalNotes' => fn ($q) => $q->latest('created_at')->limit(1),
            ])
            ->withCount([
                'inpatientEncounters',
            ])
            ->orderBy('admitted_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('inpatient/dashboard', [
            'admissions' => $admissions,
            'filters' => $filters,
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'clinicalStatuses' => Admission::CLINICAL_STATUSES,
        ]);
    }

    /**
     * Full inpatient clinical profile — patient + admission + all encounters,
     * vitals, notes, diagnoses, care plan, and orders during this admission.
     */
    public function clinicalProfile(Request $request, Admission $admission): Response
    {
        $this->authorize('view', $admission);

        $admission->load([
            'patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender,blood_group',
            'hospital:id,name',
            'department:id,name',
            'practitioner:id,practitioner_number,staff_id',
            'practitioner.staff:id,first_name,last_name',
            'service:id,code,name',
            'createdBy:id,UserName',
            'admittedBy:id,UserName',
            'clinicalStatusUpdatedBy:id,UserName',
        ]);

        // All encounters related to this admission episode (originating encounter + inpatient ward rounds), newest first.
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
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
                'diagnoses.diagnosisCode',
                'carePlan.items',
                'clinicalNotes.author:id,UserName',
                'clinicalNotes.versions',
                'laboratoryOrders.items.result',
                'radiologyOrders.items',
                'prescriptionOrders.items.medication:id,generic_name,brand_name',
            ])
            ->orderByDesc('created_at')
            ->get();

        // Ensure encounters belonging to this admission episode are linked
        $unlinkedEncounters = $encounters->filter(fn ($e) => empty($e->admission_id));
        if ($unlinkedEncounters->isNotEmpty()) {
            ClinicalEncounter::whereIn('id', $unlinkedEncounters->pluck('id'))->update(['admission_id' => $admission->id]);
        }

        if (empty($admission->clinical_encounter_id) && $encounters->isNotEmpty()) {
            $earliestEncounter = $encounters->sortBy('created_at')->first();
            $admission->update(['clinical_encounter_id' => $earliestEncounter->id]);
        }

        $admissionEncounterIds = $encounters->pluck('id')->toArray();

        // Ensure any vitals recorded under encounters belonging to this admission have admission_id backfilled
        if (! empty($admissionEncounterIds)) {
            VitalSign::query()
                ->whereNull('admission_id')
                ->whereIn('clinical_encounter_id', $admissionEncounterIds)
                ->update(['admission_id' => $admission->id]);
        }

        // Inpatient vitals — all vitals recorded against this admission
        // across all its encounters, linked encounters, or for this patient.
        $vitals = VitalSign::query()
            ->where(function ($query) use ($admission, $admissionEncounterIds) {
                $query->where('admission_id', $admission->id);
                if (! empty($admissionEncounterIds)) {
                    $query->orWhereIn('clinical_encounter_id', $admissionEncounterIds);
                }
                $query->orWhereHas('clinicalEncounter', function ($q) use ($admission) {
                    $q->where('patient_id', $admission->patient_id);
                });
            })
            ->with('recordedBy:id,UserName')
            ->orderByDesc('recorded_at')
            ->get();

        $practitioners = Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->with('staff:id,first_name,last_name')
            ->get(['id', 'practitioner_number', 'staff_id']);

        return Inertia::render('inpatient/clinical-profile', [
            'admission' => $admission,
            'encounters' => $encounters,
            'vitals' => $vitals,
            'practitioners' => $practitioners,
            'clinicalStatuses' => Admission::CLINICAL_STATUSES,
            'canOpenEncounter' => $request->user()->can('inpatient.encounter.create'),
            'canUpdateClinicalStatus' => $request->user()->can('inpatient.clinical-status.update'),
            'canRecordVitals' => $request->user()->can('vital-signs.create'),
            'canCreateNote' => $request->user()->can('clinical-notes.create'),
            'canManageCarePlan' => $request->user()->can('treatment.create'),
            'canCreateOrders' => $request->user()->can('laboratory-orders.create'),
            'canViewMedications' => $request->user()->can('inpatient.medications.view'),
        ]);
    }

    /**
     * Open a new inpatient clinical encounter against an active admission.
     * The encounter is then used as the context for all clinical actions
     * (notes, vitals, diagnoses, orders) during this care episode.
     */
    public function openEncounter(OpenEncounterRequest $request, Admission $admission, InpatientClinicalService $service): RedirectResponse
    {
        $this->authorize('view', $admission);

        try {
            $encounter = $service->openEncounter($admission, (int) $request->validated('practitioner_id'), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return to_route('inpatient.clinical-profile', $admission)
            ->with('success', "Inpatient encounter {$encounter->encounter_number} opened.");
    }

    /**
     * Update the patient's clinical status (stable/critical/etc.) during
     * an active admission. Separate from the workflow status (active/
     * discharged) — this is a clinical assessment, not an administrative one.
     */
    public function updateClinicalStatus(UpdateClinicalStatusRequest $request, Admission $admission, InpatientClinicalService $service): RedirectResponse
    {
        $this->authorize('view', $admission);

        try {
            $service->updateClinicalStatus($admission, $request->validated('clinical_status'), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Clinical status updated.');
    }

    /**
     * Record inpatient vital signs against the most recent open encounter
     * for this admission. Uses the existing VitalSignsService::recordForAdmission()
     * method added in Step 74.
     */
    public function storeVitals(StoreVitalSignRequest $request, Admission $admission, VitalSignsService $vitalSignsService): RedirectResponse
    {
        $this->authorize('view', $admission);
        $this->authorize('create', VitalSign::class);

        $encounter = ClinicalEncounter::query()
            ->where('admission_id', $admission->id)
            ->where('encounter_type', 'inpatient')
            ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
            ->latest('created_at')
            ->first();

        if ($encounter === null) {
            $encounter = ClinicalEncounter::query()
                ->where('admission_id', $admission->id)
                ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
                ->latest('created_at')
                ->first();
        }

        if ($encounter === null) {
            return back()->with('error', 'No open clinical encounter exists for this admission. Please open an encounter first.');
        }

        try {
            $vitalSignsService->recordForAdmission($admission, $encounter, $request->validated(), $request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Vital signs recorded.');
    }

    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
