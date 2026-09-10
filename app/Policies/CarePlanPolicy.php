<?php

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\User;

class CarePlanPolicy
{
    /**
     * Determine whether the user can view the care plan. No ownership
     * restriction — any authorized clinical staff in the same hospital may
     * need to read it for handoffs.
     */
    public function view(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can start a care plan for the given
     * encounter. Unlike TriageAssessment/Consultation (which have no prior
     * owner to check at creation time), the encounter's consultation already
     * has an assigned practitioner by this point, so ownership is enforced
     * here too, not deferred to a later action — mirrors
     * EncounterDiagnosisPolicy::create().
     */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('treatment.create')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $encounter);
    }

    /**
     * Determine whether the user can update the plan's goals/instructions.
     */
    public function update(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.update')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can activate the plan.
     */
    public function activate(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.activate')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can complete the plan.
     */
    public function complete(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.complete')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can cancel the plan.
     */
    public function cancel(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.cancel')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * Determine whether the user can amend a completed plan (spec §31's
     * controlled correction workflow).
     */
    public function correct(User $user, CarePlan $carePlan): bool
    {
        if (! $user->can('treatment.correct')) {
            return false;
        }

        return $this->ownsOrSupervises($user, $carePlan->clinicalEncounter);
    }

    /**
     * The encounter's treating practitioner (the consultation's assigned
     * practitioner) may act on its care plan; a hospital-admin or
     * super-admin (which bypasses policies entirely via Gate::before) may
     * act on any care plan within their scope.
     */
    public function ownsOrSupervises(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $this->belongsToUsersHospital($user, $encounter)) {
            return false;
        }

        if ($user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->practitioner?->id === $encounter->consultation?->practitioner_id;
    }

    /**
     * A hospital-admin is restricted to encounters booked under their own
     * hospital's departments; every other role holding the permission
     * (currently only super-admin, which bypasses policies via Gate::before)
     * is unrestricted.
     */
    public function belongsToUsersHospital(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $encounter->department->hospital_id;
    }
}
