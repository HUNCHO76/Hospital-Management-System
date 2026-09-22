<?php

namespace App\Http\Controllers;

use App\Models\NhifMemberVerification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifEligibilityService;
use App\Services\NHIF\NhifMemberVerificationService;
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

        $validated = $request->validate([
            'identifier_type' => ['required', Rule::in([NhifMemberVerification::TYPE_NIN, NhifMemberVerification::TYPE_CARD_NUMBER])],
            'identifier_value' => ['required', 'string', 'max:50'],
        ]);

        $record = $verifier->verify(
            $patientInsurance,
            $validated['identifier_type'],
            $validated['identifier_value'],
            $request,
        );

        // Run eligibility immediately after a successful verification so that
        // coverage information is available for the current visit.
        if ($record->isSuccessful()) {
            // Refresh to pick up card_number written by NhifMemberVerificationService.
            $patientInsurance->refresh();
            $eligibility->check($patientInsurance, $request);
        }

        $message = match ($record->verification_status) {
            NhifMemberVerification::STATUS_VERIFIED => 'NHIF member verified successfully.',
            NhifMemberVerification::STATUS_NOT_FOUND => 'NHIF member not found. Check the membership number.',
            NhifMemberVerification::STATUS_INVALID => 'NHIF identity mismatch: the name or NIN returned by NHIF does not match this patient.',
            NhifMemberVerification::STATUS_INACTIVE => 'NHIF membership is inactive, expired, or suspended.',
            default => 'NHIF verification failed. Please try again or contact NHIF.',
        };

        $flashKey = $record->isSuccessful() ? 'success' : 'error';

        return to_route('patients.insurance.show', [$patient, $patientInsurance])
            ->with($flashKey, $message);
    }
}
