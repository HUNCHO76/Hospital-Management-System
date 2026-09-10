<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClinicalEncounter\CancelClinicalEncounterRequest;
use App\Http\Requests\ClinicalEncounter\CompleteClinicalEncounterRequest;
use App\Http\Requests\ClinicalEncounter\StoreClinicalEncounterRequest;
use App\Http\Resources\CarePlanResource;
use App\Http\Resources\ClinicalEncounterResource;
use App\Http\Resources\ClinicalNoteResource;
use App\Http\Resources\ConsultationResource;
use App\Http\Resources\EncounterDiagnosisResource;
use App\Http\Resources\LaboratoryOrderResource;
use App\Http\Resources\TriageAssessmentResource;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Services\ClinicalEncounterSearchService;
use App\Services\ClinicalEncounterService;
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
            'practitioner:id,practitioner_number,practitioner_type,staff_id',
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
    public function show(Request $request, ClinicalEncounter $clinicalEncounter): Response
    {
        $this->authorize('view', $clinicalEncounter);

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
            'items.prescription.medication:id,name',
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
            'items',
            'orderedBy:id,UserName',
            'cancelledBy:id,UserName',
        ])->get();

        return Inertia::render('clinical-encounters/show', [
            'encounter' => (new ClinicalEncounterResource($clinicalEncounter))->resolve($request),
            'triage' => $triage ? (new TriageAssessmentResource($triage))->resolve($request) : null,
            'consultation' => $consultation ? (new ConsultationResource($consultation))->resolve($request) : null,
            'diagnoses' => EncounterDiagnosisResource::collection($diagnoses)->resolve($request),
            'carePlan' => $carePlan ? (new CarePlanResource($carePlan))->resolve($request) : null,
            'laboratoryOrders' => LaboratoryOrderResource::collection($laboratoryOrders)->resolve($request),
            'clinicalNotes' => ClinicalNoteResource::collection($clinicalNotes)->resolve($request),
            // Only needed for the care plan's referral item form.
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'referralPractitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
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
