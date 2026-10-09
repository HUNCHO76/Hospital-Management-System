<?php

namespace App\Http\Controllers;

use App\Models\InsuranceProvider;
use App\Models\NhifMemberVerification;
use App\Models\NhifSchemeService;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use App\Services\NHIF\NhifEligibilityService;
use App\Services\NHIF\NhifMemberVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * NHIF member/card verification for a PatientInsurance record (Phase 8, Step 65).
 *
 * Security contract (enforced here and in NhifMemberVerificationService):
 *  - Only HMS staff with patient-insurance.verify permission may trigger this.
 *  - NHIF credentials are resolved in NhifHttpClient from config(); they are
 *    never sent to or received from the browser.
 *  - The identifier value (NIN or card number) travels server-side only:
 *    React posts it to this controller via the normal Inertia form POST, and
 *    this controller passes it directly to the service — it is never echoed
 *    back into a page prop.
 */
class NhifMemberVerificationController extends Controller
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Look up an NHIF member by card number or NIN BEFORE a patient exists,
     * for the NHIF-first registration auto-fill flow (Phase 8). Internal HMS
     * endpoint — React never calls the NHIF API directly; this delegates to
     * the same NhifMemberVerificationService/NhifHttpClient the
     * post-registration nhif-verify action already uses.
     *
     * Authorized via the 'create' ability on Patient (the same permission
     * that already gates the registration page/form) rather than a new
     * bespoke permission, since this is only a pre-fill step within
     * registration itself.
     *
     * Route: POST patients/nhif-lookup
     */
    public function lookup(Request $request, NhifMemberVerificationService $verifier): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $validated = $request->validate([
            'identifier_type' => ['required', Rule::in([NhifMemberVerification::TYPE_NIN, NhifMemberVerification::TYPE_CARD_NUMBER])],
            'identifier_value' => ['required', 'string', 'max:50'],
        ]);

        $result = $verifier->lookup($validated['identifier_type'], $validated['identifier_value'], $request);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'status' => $result['status'],
                'message' => $result['error'],
                'member' => null,
                'existing_patient' => null,
            ]);
        }

        $member = $result['data'];
        $existingPatient = $this->findExistingPatient($validated['identifier_type'], $validated['identifier_value'], $member);

        if ($existingPatient) {
            $this->auditLog->record('nhif_duplicate_member_detected', null, null, [
                'identifier_type' => $validated['identifier_type'],
                'existing_patient_id' => $existingPatient->id,
            ], $request);

            return response()->json([
                'success' => true,
                'status' => 'duplicate_patient',
                'message' => 'This NHIF member is already registered.',
                'member' => $member,
                'existing_patient' => $existingPatient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'found',
            'message' => null,
            'member' => $member,
            'existing_patient' => null,
        ]);
    }

    /**
     * Find an existing HMS patient already linked to this NHIF member — by
     * membership/card number under the NHIF provider, or by NIN on the
     * patient record itself — so registration never creates a duplicate for
     * a member who is already in the system (spec §11/§12).
     *
     * @param  array<string, mixed>  $member
     */
    protected function findExistingPatient(string $identifierType, string $identifierValue, array $member): ?Patient
    {
        $nhifProvider = InsuranceProvider::query()->where('api_enabled', true)->orderBy('id')->first(['id']);

        if ($nhifProvider) {
            $membershipNumber = $member['membership_number'] ?? null;
            $cardNumber = $member['card_number'] ?? null;

            if ($membershipNumber || $cardNumber) {
                $insurance = PatientInsurance::query()
                    ->where('insurance_provider_id', $nhifProvider->id)
                    ->where(function ($query) use ($membershipNumber, $cardNumber) {
                        if ($membershipNumber) {
                            $query->orWhere('membership_number', $membershipNumber);
                        }
                        if ($cardNumber) {
                            $query->orWhere('card_number', $cardNumber);
                        }
                    })
                    ->with('patient')
                    ->first();

                if ($insurance?->patient) {
                    return $insurance->patient;
                }
            }
        }

        if ($identifierType === NhifMemberVerification::TYPE_NIN) {
            return Patient::where('national_id', $identifierValue)->first();
        }

        return null;
    }

    /**
     * Return the list of card verifiers for the given card number from NHIF.
     * Used by the NHIF-first registration form to populate the verifier selector.
     *
     * Route: POST patients/nhif-verifiers
     */
    public function getVerifiers(Request $request, NhifMemberVerificationService $verifier): JsonResponse
    {
        abort_unless(
            $request->user()?->can('patients.create') || $request->user()?->can('patient-insurance.verify'),
            403
        );

        $validated = $request->validate([
            'card_no' => ['required', 'string', 'max:50'],
        ]);

        $result = $verifier->fetchVerifiers($validated['card_no'], $request);

        return response()->json([
            'success' => $result['success'],
            'data' => $result['data'],
            'error' => $result['error'],
        ]);
    }

    /**
     * Return the list of visit types from NHIF.
     * Used by the NHIF-first registration form to populate the visit type selector.
     *
     * Route: GET patients/nhif-visit-types
     */
    public function getVisitTypes(Request $request, NhifMemberVerificationService $verifier): JsonResponse
    {
        abort_unless(
            $request->user()?->can('patients.create') || $request->user()?->can('patient-insurance.verify'),
            403
        );

        $result = $verifier->fetchVisitTypes($request);

        return response()->json([
            'success' => $result['success'],
            'data' => $result['data'],
            'error' => $result['error'],
        ]);
    }

    /**
     * Return the active NHIF-covered services for a scheme.
     * Used by the registration form to display what is covered for the patient.
     *
     * Route: GET patients/nhif-scheme-services?scheme_id=1001
     */
    public function getSchemeServices(Request $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $validated = $request->validate(['scheme_id' => ['required', 'integer', 'min:1']]);

        $base = NhifSchemeService::query()->exists()
            ? NhifSchemeService::active()
            : NhifSchemeService::catalog();

        $services = $base
            ->where('scheme_id', $validated['scheme_id'])
            ->orderBy('type_name')
            ->orderBy('item_name')
            ->get(['item_code', 'item_name', 'item_type_id', 'type_name']);

        return response()->json(['success' => true, 'data' => $services]);
    }

    /**
     * Trigger an NHIF member/card verification for the given PatientInsurance
     * record, immediately followed by an eligibility check when verification
     * succeeds.
     *
     * Route: POST patients/{patient}/insurance/{patientInsurance}/nhif-verify
     */
    public function verify(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifMemberVerificationService $verifier,
        NhifEligibilityService $eligibility,
    ): RedirectResponse {
        abort_unless($patientInsurance->patient_id === $patient->id, 404);

        $this->authorize('verify', $patientInsurance);

        if (! $request->filled('identifier_type') || ! $request->filled('identifier_value')) {
            $identifierValue = $patientInsurance->card_number 
                ?: $patientInsurance->membership_number;
            $identifierType = NhifMemberVerification::TYPE_CARD_NUMBER;

            if (blank($identifierValue) && filled($patient->national_id)) {
                $identifierType = NhifMemberVerification::TYPE_NIN;
                $identifierValue = $patient->national_id;
            }

            if (blank($identifierValue)) {
                return back()->with('error', 'Cannot verify: No card number, membership number, or NIN found on file for this patient.');
            }

            $request->merge([
                'identifier_type' => $identifierType,
                'identifier_value' => $identifierValue,
            ]);
        }

        $validated = $request->validate([
            'identifier_type' => ['required', Rule::in([NhifMemberVerification::TYPE_NIN, NhifMemberVerification::TYPE_CARD_NUMBER])],
            'identifier_value' => ['required', 'string', 'max:50'],
            'visit_type_id' => ['nullable', 'integer'],
            'verifier_id' => ['nullable', 'string', 'max:50'],
            'card_type_id' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'referral_no' => ['nullable', 'string', 'max:100'],
            'biometric_method' => ['nullable', 'string', Rule::in(['NONE'])],
        ]);

        $record = $verifier->verify(
            $patientInsurance,
            $validated['identifier_type'],
            $validated['identifier_value'],
            $request,
            (int) ($validated['visit_type_id'] ?? 1),
            $validated['verifier_id'] ?? null,
            $validated['card_type_id'] ?? null,
            $validated['remarks'] ?? null,
            $validated['referral_no'] ?? null,
            $validated['biometric_method'] ?? 'NONE',
        );

        if ($record->isSuccessful()) {
            $patientInsurance->refresh();
            $eligibility->check($patientInsurance, $request);
        }

        $message = match ($record->verification_status) {
            NhifMemberVerification::STATUS_VERIFIED => 'NHIF member verified successfully.',
            NhifMemberVerification::STATUS_NOT_FOUND => 'NHIF member not found. Check the membership number.',
            NhifMemberVerification::STATUS_INVALID => 'NHIF identity mismatch: the name or NIN returned by NHIF does not match this patient.',
            NhifMemberVerification::STATUS_INACTIVE => 'NHIF membership is inactive, expired, or suspended.',
            NhifMemberVerification::STATUS_LOOKUP_FOUND => 'NHIF card details found but VerifyCard step is required.',
            NhifMemberVerification::STATUS_VERIFICATION_FAILED => 'NHIF card verification (VerifyCard) failed. Please check the verifier and visit type.',
            default => 'NHIF verification failed. Please try again or contact NHIF.',
        };

        if (! $record->isSuccessful() && filled($record->error_message)) {
            $message .= ' Reason: '.$record->error_message;
        }

        $flashKey = $record->isSuccessful() ? 'success' : 'error';

        return to_route('patients.insurance.show', [$patient, $patientInsurance])
            ->with($flashKey, $message);
    }

    public function updateVisitType(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifMemberVerificationService $verifier,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'visit_type_id' => ['required', 'integer'],
            'authorization_no' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $authNo = $validated['authorization_no'] ?? $patientInsurance->nhif_authorization_no;
        if (blank($authNo)) {
            $msg = 'No active NHIF authorization found for this patient insurance.';
            if ($request->wantsJson()) {
                return response()->json(['error' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $result = $verifier->updateVisitType(
            $patientInsurance,
            $authNo,
            (int) $validated['visit_type_id'],
            $validated['remarks'] ?? 'Updated visit type from portal',
            $request,
        );

        if (! $result['success']) {
            $msg = 'Failed to update NHIF visit type: '.($result['error'] ?? 'Unknown error');
            if ($request->wantsJson()) {
                return response()->json(['error' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'NHIF authorization visit type updated successfully.',
                'data' => $result['data'],
            ]);
        }

        return back()->with('success', 'NHIF authorization visit type updated successfully.');
    }
}
