<?php

namespace App\Http\Controllers;

use App\Http\Requests\Patient\StorePatientRequest;
use App\Http\Requests\Patient\UpdatePatientRequest;
use App\Http\Resources\ClinicalHistoryEncounterResource;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Services\AuditLogService;
use App\Services\ClinicalHistoryService;
use App\Services\DispensingService;
use App\Services\PatientHistoryService;
use App\Services\PatientSearchService;
use App\Services\PatientService;
use App\Support\Patients\PhoneMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(protected PatientSearchService $search)
    {
        $this->authorizeResource(Patient::class, 'patient');
    }

    /**
     * Display a paginated, searchable, filterable list of patients.
     *
     * An exact patient_number short-circuits to a direct lookup; national_id/
     * passport_number/phone are matched exactly; `search` is the only fuzzy
     * free-text path. Phone numbers are masked in this list view — the full
     * number is only shown on the patient's own profile page.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'min:2', 'max:255'],
            'patient_number' => ['nullable', 'string', 'max:50'],
            'national_id' => ['nullable', 'string', 'max:255'],
            'passport_number' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'status' => ['nullable', Rule::in([Patient::STATUS_ACTIVE, Patient::STATUS_INACTIVE, Patient::STATUS_DECEASED])],
            'region' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $this->search->query($validated)
            ->when($validated['region'] ?? null, fn ($query, $region) => $query->where('region', $region))
            ->when($validated['district'] ?? null, fn ($query, $district) => $query->where('district', $district));

        $this->search->applySort($query, $validated['sort'] ?? null);

        $patients = $query->paginate(15)->withQueryString()
            ->through(fn (Patient $patient) => [
                ...$patient->only([
                    'id', 'patient_number', 'first_name', 'middle_name', 'last_name',
                    'gender', 'date_of_birth', 'status', 'created_at',
                ]),
                'phone' => PhoneMasker::mask($patient->phone),
            ]);

        return Inertia::render('patients/index', [
            'patients' => $patients,
            'filters' => $request->only([
                'search', 'patient_number', 'national_id', 'passport_number', 'phone',
                'date_of_birth', 'gender', 'status', 'region', 'district', 'sort',
            ]),
        ]);
    }

    /**
     * A lightweight, session-authenticated JSON search endpoint for in-app
     * pickers (e.g. the appointment booking form's patient selector) — a
     * plain web route, not a versioned API (see docs/api-standards.md for
     * why internal HMS functionality never uses one). Reuses
     * PatientSearchService rather than re-implementing search. Results are
     * capped and phone-masked, matching the patient index list's exposure
     * level.
     */
    public function searchJson(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $search = $request->string('search')->trim()->toString();

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $patients = $this->search->query(['search' => $search])
            ->limit(10)
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'phone'])
            ->map(fn (Patient $patient) => [
                ...$patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
                'phone' => PhoneMasker::mask($patient->phone),
            ]);

        return response()->json(['data' => $patients]);
    }

    /**
     * Show the form for registering a new patient.
     *
     * When redirected back here after a possible-duplicate warning, the
     * previously submitted input and the matched patients are surfaced so
     * the form can be repopulated and the warning re-displayed.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('patients/create', [
            'possibleDuplicates' => $request->session()->get('possibleDuplicates', []),
            'confirmedDuplicate' => $request->session()->get('confirmedDuplicate'),
            'old' => $request->old(),
        ]);
    }

    /**
     * Store a newly registered patient, warning about possible duplicates
     * before creating one unless the registration officer has confirmed.
     */
    public function store(StorePatientRequest $request, PatientService $patients): RedirectResponse
    {
        $validated = $request->validated();

        // A confirmed duplicate (same national ID or passport) always blocks
        // registration and cannot be overridden — the same government
        // identifier cannot belong to two different people.
        $confirmed = $patients->findConfirmedDuplicate($validated);

        if ($confirmed) {
            return redirect()->route('patients.create')
                ->withInput()
                ->with('confirmedDuplicate', $confirmed->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'phone', 'national_id', 'passport_number']));
        }

        // A possible duplicate (weaker demographic signals) only warns; staff
        // may proceed by confirming.
        if (! $request->boolean('confirm_duplicate')) {
            $duplicates = $patients->findPossibleDuplicates($validated);

            if ($duplicates->isNotEmpty()) {
                return redirect()->route('patients.create')
                    ->withInput()
                    ->with('possibleDuplicates', $duplicates->toArray());
            }
        }

        unset($validated['confirm_duplicate']);

        $patient = $patients->create($validated, $request);

        return to_route('patients.show', $patient)->with('success', "Patient registered successfully. Patient Number: {$patient->patient_number}");
    }

    /**
     * Display the specified patient, including their contacts.
     */
    public function show(Patient $patient): Response
    {
        $patient->load([
            'contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('name'),
            'insurances' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
            'insurances.insuranceProvider:id,name,code',
            'insurances.insuranceScheme:id,name,code',
        ]);

        return Inertia::render('patients/show', [
            'patient' => $patient,
        ]);
    }

    /**
     * Display a read-only activity timeline for the specified patient,
     * aggregated from the existing audit log — see PatientHistoryService.
     */
    public function history(Patient $patient, PatientHistoryService $history): Response
    {
        $this->authorize('view', $patient);

        return Inertia::render('patients/history', [
            'patient' => $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            'timeline' => $history->timeline($patient),
        ]);
    }

    /**
     * Display the patient's clinical timeline — a separately-permissioned
     * aggregation of their clinical encounters (triage, consultation,
     * diagnosis, treatment, clinical notes), distinct from history() above
     * which is deliberately non-clinical (see PatientHistoryService). No new
     * PatientHistoryController is introduced; this is a sibling action on
     * the existing controller per the Step 26 anti-duplication requirement.
     */
    public function clinicalHistory(Request $request, Patient $patient, ClinicalHistoryService $history, AuditLogService $auditLog, DispensingService $dispensingService): Response
    {
        $this->authorize('viewClinicalHistory', $patient);

        $filters = $request->only(['date_from', 'date_to', 'department_id', 'practitioner_id', 'encounter_type', 'status', 'diagnosis', 'note_type']);

        $auditLog->record('clinical_history_viewed', $patient, null, ['patient_id' => $patient->id], $request);

        $encounters = $history->timeline($patient, $filters)
            ->through(fn (ClinicalEncounter $encounter) => (new ClinicalHistoryEncounterResource($encounter, $dispensingService))->resolve($request));

        return Inertia::render('patients/clinical-history', [
            'patient' => $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            'encounters' => $encounters,
            'filters' => $filters,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
            'practitioners' => Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->with('staff:id,first_name,last_name')
                ->get(['id', 'staff_id', 'practitioner_number']),
            'encounterTypes' => ClinicalEncounter::TYPES,
        ]);
    }

    /**
     * Show the form for editing the specified patient.
     */
    public function edit(Patient $patient): Response
    {
        $patient->load(['contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('name')]);

        return Inertia::render('patients/edit', [
            'patient' => $patient,
        ]);
    }

    /**
     * Update the specified patient and their contacts.
     */
    public function update(UpdatePatientRequest $request, Patient $patient, PatientService $patients): RedirectResponse
    {
        $patients->update($patient, $request->validated(), $request);

        return to_route('patients.show', $patient)->with('success', 'Patient updated successfully.');
    }

    /**
     * Deactivate the specified patient. Historical data is never
     * permanently destroyed — this marks the patient inactive and
     * soft-deletes the record.
     */
    public function destroy(Request $request, Patient $patient, PatientService $patients): RedirectResponse
    {
        $patients->deactivate($patient, $request);

        return to_route('patients.index')->with('success', 'Patient deactivated successfully.');
    }
}
