<?php

namespace App\Policies;

use App\Models\ClinicalEncounter;
use App\Models\User;
use App\Models\VitalSign;

class VitalSignPolicy
{
    /**
     * Determine whether the user holds the base vital-signs viewing
     * permission. Listing an encounter's vital-sign history is additionally
     * gated by ClinicalEncounterPolicy::view() in the controller (which
     * covers hospital scoping) before this is checked — there is no
     * VitalSign instance yet to scope against here.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('vital-signs.view');
    }

    /**
     * Determine whether the user can view a specific vital-sign
     * observation.
     */
    public function view(User $user, VitalSign $vitalSign): bool
    {
        if (! $user->can('vital-signs.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $vitalSign->clinicalEncounter);
    }

    /**
     * Determine whether the user holds the base vital-signs recording
     * permission. Recording against a specific encounter is additionally
     * gated by ClinicalEncounterPolicy::view() plus StoreVitalSignRequest's
     * own hospital-scoping check in the controller.
     */
    public function create(User $user): bool
    {
        return $user->can('vital-signs.create');
    }

    /**
     * Determine whether the user can correct a previously recorded
     * observation.
     */
    public function correct(User $user, VitalSign $vitalSign): bool
    {
        if (! $user->can('vital-signs.correct')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $vitalSign->clinicalEncounter);
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
