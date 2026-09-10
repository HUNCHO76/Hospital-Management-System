<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Models\User;

class TriageAssessmentPolicy
{
    /**
     * Determine whether the user can view the triage assessment.
     */
    public function view(User $user, TriageAssessment $triage): bool
    {
        if (! $user->can('triage.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $triage->clinicalEncounter);
    }

    /**
     * Determine whether the user can start triage on some encounter. Since
     * no TriageAssessment instance exists yet at this point, the
     * hospital-scoping restriction for hospital-admins is enforced in
     * StoreTriageRequest instead, against the target encounter's own
     * department — the same split StoreClinicalEncounterRequest uses.
     */
    public function create(User $user): bool
    {
        return $user->can('triage.create');
    }

    /**
     * Determine whether the user can update the assessment's clinical
     * fields (category, chief complaint, pain score, notes).
     */
    public function update(User $user, TriageAssessment $triage): bool
    {
        if (! $user->can('triage.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $triage->clinicalEncounter);
    }

    /**
     * Determine whether the user can transition the assessment from
     * pending to in_progress. Reuses the 'triage.create' permission rather
     * than introducing a separate one — starting the assessment is done by
     * whoever just opened it, the same provider-side action split as
     * AppointmentPolicy::start().
     */
    public function start(User $user, TriageAssessment $triage): bool
    {
        if (! $user->can('triage.create')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $triage->clinicalEncounter);
    }

    /**
     * Determine whether the user can complete the assessment.
     */
    public function complete(User $user, TriageAssessment $triage): bool
    {
        if (! $user->can('triage.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $triage->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the assessment.
     */
    public function cancel(User $user, TriageAssessment $triage): bool
    {
        if (! $user->can('triage.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $triage->clinicalEncounter);
    }

    /**
     * A hospital-admin is restricted to encounters booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $encounter->department->hospital_id;
    }
}
