<?php

namespace App\Http\Controllers;

use App\Models\Practitioner;
use App\Services\NHIF\NhifPractitionerAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * NHIF practitioner login/logout attendance (Phase 8, Step 67).
 *
 * These routes are typically triggered from the practitioner's profile page or
 * the daily attendance panel. Each call is idempotent from the HMS side: a new
 * append-only record is always written, and the NHIF API is always called even
 * if the practitioner is already "logged in" (NHIF may need the duplicate to
 * refresh a session).
 */
class NhifPractitionerAttendanceController extends Controller
{
    /**
     * POST practitioners/{practitioner}/nhif-login
     */
    public function login(
        Request $request,
        Practitioner $practitioner,
        NhifPractitionerAttendanceService $attendance,
    ): RedirectResponse {
        $this->authorize('update', $practitioner);

        $practitioner->load('staff.hospital');
        $hospital = $practitioner->staff?->hospital;

        if (! $hospital) {
            return back()->with('error', 'Practitioner is not associated with a hospital.');
        }

        $record = $attendance->login($practitioner, $hospital, $request);

        $message = $record->isSuccessful()
            ? 'NHIF login recorded successfully.'
            : 'NHIF login failed: '.($record->response_data['Message'] ?? 'NHIF service error. Please try again.');

        return back()->with($record->isSuccessful() ? 'success' : 'error', $message);
    }

    /**
     * POST practitioners/{practitioner}/nhif-logout
     */
    public function logout(
        Request $request,
        Practitioner $practitioner,
        NhifPractitionerAttendanceService $attendance,
    ): RedirectResponse {
        $this->authorize('update', $practitioner);

        $practitioner->load('staff.hospital');
        $hospital = $practitioner->staff?->hospital;

        if (! $hospital) {
            return back()->with('error', 'Practitioner is not associated with a hospital.');
        }

        $record = $attendance->logout($practitioner, $hospital, $request);

        $message = $record->isSuccessful()
            ? 'NHIF logout recorded successfully.'
            : 'NHIF logout failed: '.($record->response_data['Message'] ?? 'NHIF service error. Please try again.');

        return back()->with($record->isSuccessful() ? 'success' : 'error', $message);
    }
}
