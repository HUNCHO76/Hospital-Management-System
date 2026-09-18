<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatientInsurance\StorePatientInsuranceRequest;
use App\Http\Requests\PatientInsurance\UpdatePatientInsuranceRequest;
use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\PatientInsuranceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PatientInsuranceController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PatientInsurance::class, 'patientInsurance');
    }

    /**
     * Display a paginated, searchable, filterable list of patient insurance
     * records across all patients — for accounts/admin staff verifying
     * coverage, not the per-patient view (that lives on the patient profile).
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $records = PatientInsurance::query()
            ->with(['patient:id,patient_number,first_name,middle_name,last_name', 'insuranceProvider:id,name', 'insuranceScheme:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('membership_number', 'like', "%{$search}%")
                        ->orWhere('policy_number', 'like', "%{$search}%")
                        ->orWhere('card_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($query) use ($search) {
                            $query->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->integer('insurance_provider_id'), fn ($query, $id) => $query->where('insurance_provider_id', $id))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('verification_status')->toString(), fn ($query, $status) => $query->where('verification_status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('insurance/patient-insurance/index', [
            'records' => $records,
            'filters' => $request->only(['search', 'insurance_provider_id', 'status', 'verification_status']),
            'providers' => InsuranceProvider::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Show the form for adding a new insurance record for the patient.
     *
     * When redirected back here after a possible-duplicate warning, the
     * previously submitted input and the matched records are surfaced.
     */
    public function create(Request $request, Patient $patient): Response
    {
        return Inertia::render('patients/insurance/create', [
            'patient' => $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            'providers' => InsuranceProvider::active()->orderBy('name')->get(['id', 'name', 'code']),
            'schemes' => InsuranceScheme::active()->orderBy('name')->get(['id', 'insurance_provider_id', 'name', 'code']),
            'possibleDuplicates' => $request->session()->get('possibleDuplicates', []),
            'old' => $request->old(),
        ]);
    }

    /**
     * Store a newly added patient insurance record, warning about possible
     * duplicate memberships before creating one unless confirmed.
     */
    public function store(StorePatientInsuranceRequest $request, Patient $patient, PatientInsuranceService $service): RedirectResponse
    {
        $validated = $request->validated();

        if (! $request->boolean('confirm_duplicate')) {
            $duplicates = $service->findPossibleDuplicates($patient->id, $validated);

            if ($duplicates->isNotEmpty()) {
                return redirect()->route('patients.insurance.create', $patient)
                    ->withInput()
                    ->with('possibleDuplicates', $duplicates->toArray());
            }
        }

        unset($validated['confirm_duplicate']);

        $service->create($patient, $validated, $request);

        return to_route('patients.show', $patient)->with('success', 'Insurance added successfully.');
    }

    /**
     * Display the specified patient insurance record.
     */
    public function show(Patient $patient, PatientInsurance $patientInsurance): Response
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);

        $patientInsurance->load([
            'insuranceProvider:id,name,code,api_enabled',
            'insuranceScheme:id,name,code',
            'latestNhifVerification',
            'latestNhifEligibility',
        ]);

        return Inertia::render('patients/insurance/show', [
            'patient' => $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'national_id']),
            'insurance' => $patientInsurance,
        ]);
    }

    /**
     * Show the form for editing the specified patient insurance record.
     */
    public function edit(Patient $patient, PatientInsurance $patientInsurance): Response
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);

        return Inertia::render('patients/insurance/edit', [
            'patient' => $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            'insurance' => $patientInsurance,
            'providers' => InsuranceProvider::active()->orderBy('name')->get(['id', 'name', 'code']),
            'schemes' => InsuranceScheme::active()->orderBy('name')->get(['id', 'insurance_provider_id', 'name', 'code']),
        ]);
    }

    /**
     * Update the specified patient insurance record.
     */
    public function update(UpdatePatientInsuranceRequest $request, Patient $patient, PatientInsurance $patientInsurance, PatientInsuranceService $service): RedirectResponse
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);

        $service->update($patientInsurance, $request->validated(), $request);

        return to_route('patients.show', $patient)->with('success', 'Insurance updated successfully.');
    }

    /**
     * Deactivate the specified patient insurance record. Historical data is
     * never permanently destroyed.
     */
    public function destroy(Request $request, Patient $patient, PatientInsurance $patientInsurance, PatientInsuranceService $service): RedirectResponse
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);

        $service->deactivate($patientInsurance, $request);

        return to_route('patients.show', $patient)->with('success', 'Insurance deactivated successfully.');
    }

    /**
     * Make the specified patient insurance record the patient's primary
     * insurance, safely unmarking any other primary record.
     */
    public function makePrimary(Request $request, Patient $patient, PatientInsurance $patientInsurance, PatientInsuranceService $service): RedirectResponse
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);
        $this->authorize('update', $patientInsurance);

        $service->makePrimary($patientInsurance, $request);

        return to_route('patients.show', $patient)->with('success', 'Primary insurance updated.');
    }

    /**
     * Set the internal verification status of the specified patient
     * insurance record. Never calls an external API.
     */
    public function verify(Request $request, Patient $patient, PatientInsurance $patientInsurance, PatientInsuranceService $service): RedirectResponse
    {
        $this->ensureBelongsToPatient($patient, $patientInsurance);
        $this->authorize('verify', $patientInsurance);

        $validated = $request->validate([
            'verification_status' => ['required', Rule::in(PatientInsurance::VERIFICATION_STATUSES)],
        ]);

        $service->verify($patientInsurance, $validated['verification_status'], $request);

        return to_route('patients.show', $patient)->with('success', 'Insurance verification status updated.');
    }

    /**
     * Guard against a patient_insurance id belonging to a different patient
     * than the one in the URL — the nested route is not scoped by Laravel
     * automatically here since it is hand-registered rather than a
     * Route::resource(), so this is enforced explicitly instead.
     */
    protected function ensureBelongsToPatient(Patient $patient, PatientInsurance $patientInsurance): void
    {
        abort_unless($patientInsurance->patient_id === $patient->id, 404);
    }
}
