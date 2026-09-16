<?php

namespace App\Policies;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Models\User;

/**
 * Mirrors RadiologyAppointmentPolicy/RadiologyOrderPolicy's shape: hospital
 * scoping through the same department chain every other Radiology policy
 * already uses, plus an ownership restriction on update()/submit() (only
 * the report's own reporting practitioner, or an unrestricted
 * hospital-admin/super-admin, may modify it) — mirrors
 * RadiologyOrderPolicy::ownsOrSupervises()'s identical "only the owner or
 * an admin" shape.
 */
class RadiologyReportPolicy
{
    /**
     * Viewing the reporting worklist.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('radiology-results.view');
    }

    /**
     * Determine whether the user can view the report.
     */
    public function view(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $report->radiologyAppointment);
    }

    /**
     * Determine whether the user can create a report for the given
     * examination. No RadiologyReport instance exists yet here, so hospital
     * scoping is checked directly against the appointment's own chain — no
     * ownership restriction, since nobody has authored anything yet (any
     * authorized reporting practitioner in the hospital may start it).
     */
    public function create(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->can('radiology-results.create')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $appointment);
    }

    /**
     * Determine whether the user can edit the report's draft content.
     */
    public function update(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.update')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $report->radiologyAppointment)) {
            return false;
        }

        return $this->ownsReport($user, $report);
    }

    /**
     * Determine whether the user can submit the report.
     */
    public function submit(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.submit')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $report->radiologyAppointment)) {
            return false;
        }

        return $this->ownsReport($user, $report);
    }

    /**
     * Determine whether the user can open the report for verification review
     * (Phase 5, Step 43, Part 9/18). Requires independence from the report's
     * own author, the same as verify()/returnForCorrection() — a reporting
     * practitioner should not walk their own report through the review
     * screen either.
     */
    public function review(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.review')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $report->radiologyAppointment)) {
            return false;
        }

        return $this->independentOfAuthor($user, $report);
    }

    /**
     * Determine whether the user can verify (approve) the report.
     */
    public function verify(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.verify')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $report->radiologyAppointment)) {
            return false;
        }

        return $this->independentOfAuthor($user, $report);
    }

    /**
     * Determine whether the user can return the report for correction.
     */
    public function returnForCorrection(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.return')) {
            return false;
        }

        if (! $this->belongsToUsersHospital($user, $report->radiologyAppointment)) {
            return false;
        }

        return $this->independentOfAuthor($user, $report);
    }

    /**
     * Determine whether the user can release the report as the official
     * clinical result (Phase 5, Step 44, Part 9/23). No independence-from-
     * author restriction is required here (Part 9-11 of Step 44 do not ask
     * for one) — only the permission and hospital scope.
     */
    public function release(User $user, RadiologyReport $report): bool
    {
        if (! $user->can('radiology-results.release')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $report->radiologyAppointment);
    }

    /**
     * A hospital-admin is restricted to reports booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, RadiologyAppointment $appointment): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $appointment->radiologyOrderItem->radiologyOrder->clinicalEncounter->department->hospital_id;
    }

    /**
     * A report may only be modified by the practitioner who authored it, or
     * by a hospital-admin (unrestricted management within their own
     * hospital, already confirmed by belongsToUsersHospital() above) —
     * mirrors RadiologyOrderPolicy::ownsOrSupervises()'s identical shape.
     */
    protected function ownsReport(User $user, RadiologyReport $report): bool
    {
        if ($user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->practitioner?->id === $report->reporting_practitioner_id;
    }

    /**
     * The self-verification protection (Phase 5, Step 43, Part 5): a user
     * with no practitioner profile is trivially independent (they cannot be
     * the reporting practitioner); otherwise the acting practitioner must
     * differ from the report's own reporting practitioner, unless hospital
     * policy explicitly allows self-verification (never hard-coded — Part
     * 5's own "make that behavior configurable" instruction).
     */
    protected function independentOfAuthor(User $user, RadiologyReport $report): bool
    {
        if (config('radiology.allow_self_verification')) {
            return true;
        }

        $practitionerId = $user->staff?->practitioner?->id;

        return $practitionerId === null || $practitionerId !== $report->reporting_practitioner_id;
    }
}
