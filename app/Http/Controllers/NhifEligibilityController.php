<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * NHIF eligibility check for a PatientInsurance record (Phase 8, Step 66).
 *
 * The eligibility check is typically triggered automatically after a successful
 * member verification (see NhifMemberVerificationController), but staff may
 * also trigger it manually from the insurance detail page to refresh coverage
 * information.
 */
class NhifEligibilityController extends Controller
{
    /**
     * Trigger an NHIF eligibility check for the given PatientInsurance record.
     *
     * Route: POST patients/{patient}/insurance/{patientInsurance}/nhif-eligibility
     */
    public function verify(
        Request $request,
        Patient $patient,
        PatientInsurance $patientInsurance,
        NhifEligibilityService $eligibility,
    ): RedirectResponse {
        abort_unless($patientInsurance->patient_id === $patient->id, 404);

        $this->authorize('verify', $patientInsurance);

        $record = $eligibility->check($patientInsurance, $request);

        $message = $record->isEligible()
            ? 'NHIF eligibility confirmed. Coverage: '.($record->nhif_coverage_percent ?? 'N/A').'%.'
            : 'NHIF eligibility check completed: '.$record->eligibility_status.'.';

        $flashKey = $record->isEligible() ? 'success' : 'error';

        return to_route('patients.insurance.show', [$patient, $patientInsurance])
            ->with($flashKey, $message);
    }
}
