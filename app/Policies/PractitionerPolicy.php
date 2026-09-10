<?php

namespace App\Policies;

use App\Models\Practitioner;
use App\Models\User;

class PractitionerPolicy
{
    /**
     * Determine whether the user can view any practitioners.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('practitioners.view');
    }

    /**
     * Determine whether the user can view the practitioner.
     */
    public function view(User $user, Practitioner $practitioner): bool
    {
        if (! $user->can('practitioners.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $practitioner);
    }

    /**
     * Determine whether the user can create practitioners.
     *
     * The hospital of the staff member being promoted to a practitioner is
     * validated separately in StorePractitionerRequest, since no
     * Practitioner instance exists yet here.
     */
    public function create(User $user): bool
    {
        return $user->can('practitioners.create');
    }

    /**
     * Determine whether the user can update the practitioner.
     */
    public function update(User $user, Practitioner $practitioner): bool
    {
        if (! $user->can('practitioners.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $practitioner);
    }

    /**
     * Determine whether the user can delete the practitioner.
     */
    public function delete(User $user, Practitioner $practitioner): bool
    {
        if (! $user->can('practitioners.delete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $practitioner);
    }

    /**
     * A hospital-admin is restricted to practitioners on their own
     * hospital's staff; every other role holding the permission (currently
     * only super-admin, which bypasses policies via Gate::before) is
     * unrestricted.
     */
    protected function belongsToUsersHospital(User $user, Practitioner $practitioner): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $practitioner->staff->hospital_id;
    }
}
