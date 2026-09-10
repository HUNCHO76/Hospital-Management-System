<?php

namespace App\Policies;

use App\Models\Hospital;
use App\Models\User;

class HospitalPolicy
{
    /**
     * Determine whether the user can view any hospitals.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('hospitals.view');
    }

    /**
     * Determine whether the user can view the hospital.
     */
    public function view(User $user, Hospital $hospital): bool
    {
        return $user->can('hospitals.view');
    }

    /**
     * Determine whether the user can create hospitals.
     */
    public function create(User $user): bool
    {
        return $user->can('hospitals.create');
    }

    /**
     * Determine whether the user can update the hospital.
     *
     * A hospital-admin may only manage the hospital their own staff record
     * belongs to — never another facility — per the HMS hospital-scoping rule.
     */
    public function update(User $user, Hospital $hospital): bool
    {
        if (! $user->can('hospitals.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $hospital);
    }

    /**
     * Determine whether the user can delete the hospital.
     */
    public function delete(User $user, Hospital $hospital): bool
    {
        if (! $user->can('hospitals.delete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $hospital);
    }

    /**
     * A hospital-admin is restricted to the hospital they are staff at; every
     * other role holding the permission (currently only super-admin, which
     * bypasses policies entirely via Gate::before) is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, Hospital $hospital): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospital->id;
    }
}
