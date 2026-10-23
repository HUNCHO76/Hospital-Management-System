<?php

namespace App\Http\Controllers;

use App\Http\Requests\Patient\StorePatientRequest;
use App\Http\Requests\Patient\UpdatePatientRequest;
use App\Http\Resources\ClinicalHistoryEncounterResource;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\NhifMemberVerification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Services\AuditLogService;
use App\Services\ClinicalHistoryService;
use App\Services\DispensingService;
use App\Services\NHIF\NhifEligibilityService;
use App\Services\NHIF\NhifMemberVerificationService;
use App\Services\PatientHistoryService;
use App\Services\PatientSearchService;
use App\Services\PatientService;
use App\Support\Patients\PhoneMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->withExists(['insurances as has_active_nhif' => function ($query): void {
                $query->where('status', PatientInsurance::STATUS_ACTIVE)
                    ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
                    ->whereNotNull('nhif_authorization_no')
                    ->where('verified_at', '>=', now()->subHours(PatientInsurance::AUTH_EXPIRATION_HOURS));
            }])
            ->with(['insurances' => function ($query): void {
                $query->where('status', PatientInsurance::STATUS_ACTIVE)
                    ->whereNotNull('nhif_authorization_no')
                    ->latest();
            }])
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'phone', 'gender', 'date_of_birth'])
            ->map(function (Patient $patient) {
                $nhifIns = $patient->insurances->first();
                $isExpired = $nhifIns ? $nhifIns->isAuthorizationExpired() : false;

                return [
                    ...$patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'gender']),
                    'date_of_birth' => $patient->date_of_birth?->toDateString(),
                    'phone' => PhoneMasker::mask($patient->phone),
                    'has_active_nhif' => (bool) $patient->has_active_nhif,
                    'is_nhif_auth_expired' => $isExpired,
                    'active_nhif_authorization_no' => ($nhifIns && ! $isExpired) ? $nhifIns->nhif_authorization_no : null,
                    'nhif_authorization_no' => $nhifIns?->nhif_authorization_no,
                    'nhif_auth_expires_at' => $nhifIns?->authorizationExpiresAt()?->toIso8601String(),
                    'patient_insurance_id' => $nhifIns?->id,
                ];
            });

        return response()->json(['data' => $patients]);
    }

    /**
     * Show the form for registering a new patient.
     *
     * When redirected back here after a possible-duplicate warning, the
     * previously submitted input and the matched patients are surfaced so
     * the form can be repopulated and the warning re-displayed.
     *
     * nhifProvider is the api_enabled InsuranceProvider record for NHIF; it
     * is null when no NHIF provider has been configured (e.g. during tests
     * or fresh installs), so the form hides the Insurance option gracefully.
     */
    public function create(Request $request): Response
    {
        $nhifProvider = InsuranceProvider::query()
            ->where('api_enabled', true)
            ->orderBy('id')
            ->first(['id', 'name', 'code']);

        $nhifSchemes = $nhifProvider
            ? InsuranceScheme::where('insurance_provider_id', $nhifProvider->id)
                ->active()
                ->orderBy('name')
                ->get(['id', 'insurance_provider_id', 'name', 'code'])
            : [];

        return Inertia::render('patients/create', [
            'possibleDuplicates' => $request->session()->get('possibleDuplicates', []),
            'confirmedDuplicate' => $request->session()->get('confirmedDuplicate'),
            'old' => $request->old(),
            'nhifProvider' => $nhifProvider,
            'nhifSchemes' => $nhifSchemes,
        ]);
    }

    /**
     * Store a newly registered patient, warning about possible duplicates
     * before creating one unless the registration officer has confirmed.
     *
     * When payment_type is 'insurance', a PatientInsurance record is created
     * atomically with the patient and NHIF verification is triggered
     * immediately after the transaction commits. NHIF API credentials are
     * resolved server-side by NhifMemberVerificationService — they are never
     * passed to React or returned in any response.
     */
    public function store(
        StorePatientRequest $request,
        PatientService $patients,
        NhifMemberVerificationService $verifier,
        NhifEligibilityService $eligibility,
        AuditLogService $auditLog,
    ): RedirectResponse {
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

        // Extract and remove form-level fields that don't map to Patient columns.
        $paymentType = $validated['payment_type'];
        $nhifInsuranceData = null;
        $nhifIdentifierType = null;
        $nhifIdentifierValue = null;
        $nhifVisitTypeId = 1;
        $nhifVerifierId = null;
        $nhifCardTypeId = null;
        $nhifRemarks = null;
        $nhifReferralNo = null;

        unset($validated['confirm_duplicate'], $validated['payment_type']);

        if ($paymentType === 'insurance') {
            $providerId = $validated['nhif_provider_id'];
            $membershipNumber = $validated['nhif_membership_number'];
            $nhifIdentifierType = $validated['nhif_identifier_type'];
            $nhifIdentifierValue = $nhifIdentifierType === NhifMemberVerification::TYPE_NIN
                ? $validated['national_id']
                : $validated['nhif_card_number'];

            $nhifVisitTypeId = (int) ($validated['nhif_visit_type_id'] ?? 1);
            $nhifVerifierId = $validated['nhif_verifier_id'] ?? null;
            $nhifCardTypeId = $validated['nhif_card_type_id'] ?? null;
            $nhifRemarks = $validated['nhif_remarks'] ?? null;
            $nhifReferralNo = $validated['nhif_referral_no'] ?? null;

            // Hard duplicate-membership check: two active patients cannot share
            // the same NHIF membership number.
            $this->assertNoActiveDuplicateMembership((int) $providerId, (string) $membershipNumber);

            $nhifInsuranceData = [
                'insurance_provider_id' => $providerId,
                'insurance_scheme_id' => $validated['nhif_scheme_id'] ?? null,
                'membership_number' => $membershipNumber,
                'card_number' => $validated['nhif_card_number'] ?? null,
                'verification_status' => PatientInsurance::VERIFICATION_PENDING,
                'status' => PatientInsurance::STATUS_ACTIVE,
            ];
        }

        unset(
            $validated['nhif_provider_id'],
            $validated['nhif_membership_number'],
            $validated['nhif_card_number'],
            $validated['nhif_identifier_type'],
            $validated['nhif_scheme_id'],
            $validated['nhif_visit_type_id'],
            $validated['nhif_verifier_id'],
            $validated['nhif_card_type_id'],
            $validated['nhif_remarks'],
            $validated['nhif_referral_no'],
        );

        // For insurance patients: create patient + insurance + NHIF VerifyCard inside a
        // single transaction. If VerifyCard fails the patient and insurance records are
        // rolled back — registration is only committed after a successful STATUS_VERIFIED.
        if ($nhifInsuranceData !== null && $nhifIdentifierType !== null && $nhifIdentifierValue !== null) {
            $patient = null;
            $insurance = null;
            $verificationRecord = null;

            try {
                DB::transaction(function () use (
                    &$patient, &$insurance, &$verificationRecord,
                    $patients, $validated, $request, $nhifInsuranceData,
                    $nhifIdentifierType, $nhifIdentifierValue,
                    $nhifVisitTypeId, $nhifVerifierId, $nhifCardTypeId,
                    $nhifRemarks, $nhifReferralNo, $verifier,
                ) {
                    $patient = $patients->create($validated, $request, $nhifInsuranceData);

                    /** @var PatientInsurance $insurance */
                    $insurance = $patient->insurances()->latest('id')->first();

                    $verificationRecord = $verifier->verify(
                        $insurance,
                        $nhifIdentifierType,
                        $nhifIdentifierValue,
                        $request,
                        $nhifVisitTypeId,
                        $nhifVerifierId,
                        $nhifCardTypeId,
                        $nhifRemarks,
                        $nhifReferralNo,
                    );

                    if (! $verificationRecord->isSuccessful()) {
                        $nhifDetail = filled($verificationRecord->error_message)
                            ? ' ('.$verificationRecord->error_message.')'
                            : '';

                        $statusMessage = match ($verificationRecord->verification_status) {
                            NhifMemberVerification::STATUS_NOT_FOUND => 'NHIF member not found.',
                            NhifMemberVerification::STATUS_INVALID => 'NHIF identity mismatch: name or NIN does not match.',
                            NhifMemberVerification::STATUS_INACTIVE => 'NHIF membership is inactive, expired, or suspended.',
                            NhifMemberVerification::STATUS_VERIFICATION_FAILED => 'NHIF card verification (VerifyCard) failed'.$nhifDetail.'.',
                            default => 'NHIF verification failed.',
                        };

                        throw ValidationException::withMessages([
                            'nhif_verification' => $statusMessage.' Registration cancelled. Resolve the NHIF issue before registering.',
                        ]);
                    }
                });
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);

                return redirect()->route('patients.create')
                    ->withInput()
                    ->withErrors(['nhif_verification' => 'NHIF service unavailable. Please try again.']);
            }

            $insurance->refresh();
            $eligibility->check($insurance, $request);
            $auditLog->record('nhif_member_linked_to_patient', $insurance, null, [
                'patient_id' => $patient->id,
                'membership_number' => $insurance->membership_number,
            ], $request);

            return to_route('patients.show', $patient)
                ->with('success', "Patient registered successfully. Patient Number: {$patient->patient_number}. NHIF membership verified.");
        }

        // Cash / self-pay patient — no NHIF verification.
        $patient = $patients->create($validated, $request, null);

        return to_route('patients.show', $patient)
            ->with('success', "Patient registered successfully. Patient Number: {$patient->patient_number}.");
    }

    /**
     * Block registration when another active patient already holds the same
     * NHIF membership number under the same provider. Two people cannot
     * share one membership.
     *
     * @throws ValidationException
     */
    protected function assertNoActiveDuplicateMembership(int $providerId, string $membershipNumber): void
    {
        $existing = PatientInsurance::where('insurance_provider_id', $providerId)
            ->where('membership_number', $membershipNumber)
            ->where('status', '!=', PatientInsurance::STATUS_INACTIVE)
            ->first(['id', 'patient_id']);

        if ($existing) {
            throw ValidationException::withMessages([
                'nhif_membership_number' => 'This NHIF membership number is already registered to another patient. Please verify the number or search for the existing patient.',
            ]);
        }
    }

    /**
     * Display the specified patient, including their contacts.
     */
    public function show(Request $request, Patient $patient, AuditLogService $auditLog): Response
    {
        $auditLog->record('patient.viewed', $patient, null, [
            'patient_id' => $patient->id,
            'patient_number' => $patient->patient_number,
        ], $request);

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
