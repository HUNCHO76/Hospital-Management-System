<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\User;

class ClinicalEncounterPolicy
{
    /**
     * Determine whether the user can view any clinical encounters.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('clinical-encounters.view');
    }

    /**
     * Determine whether the user can view the clinical encounter.
     *
     * Clinical encounters carry sensitive healthcare information (§27), so
     * this is a distinct permission from reception.view — holding one does
     * not imply the other.
     */
    public function view(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('clinical-encounters.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $encounter);
    }

    /**
     * Determine whether the user can create a clinical encounter.
     *
     * The hospital of the selected department is validated separately in
     * StoreClinicalEncounterRequest, since no ClinicalEncounter instance
     * exists yet here.
     */
    public function create(User $user): bool
    {
        return $user->can('clinical-encounters.create');
    }

    /**
     * Determine whether the user can start the encounter.
     */
    public function start(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('clinical-encounters.start')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $encounter);
    }

    /**
     * Determine whether the user can complete the encounter.
     */
    public function complete(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('clinical-encounters.complete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $encounter);
    }

    /**
     * Determine whether the user can cancel the encounter.
     */
    public function cancel(User $user, ClinicalEncounter $encounter): bool
    {
        if (! $user->can('clinical-encounters.cancel')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $encounter);
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
