<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class StaffPolicy
{
    /**
     * Determine whether the user can view any staff.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('staff.view');
    }

    /**
     * Determine whether the user can view the staff member.
     */
    public function view(User $user, Staff $staff): bool
    {
        if (! $user->can('staff.view')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $staff);
    }

    /**
     * Determine whether the user can create staff.
     *
     * The hospital the staff member will belong to is validated separately
     * in StoreStaffRequest, since no Staff instance exists yet here.
     */
    public function create(User $user): bool
    {
        return $user->can('staff.create');
    }

    /**
     * Determine whether the user can update the staff member.
     *
     * A hospital-admin may only manage staff within the hospital their own
     * staff record belongs to — never another facility's staff.
     */
    public function update(User $user, Staff $staff): bool
    {
        if (! $user->can('staff.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $staff);
    }

    /**
     * Determine whether the user can delete the staff member.
     */
    public function delete(User $user, Staff $staff): bool
    {
        if (! $user->can('staff.delete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $staff);
    }

    /**
     * A hospital-admin is restricted to their own hospital's staff; every
     * other role holding the permission (currently only super-admin, which
     * bypasses policies entirely via Gate::before) is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, Staff $staff): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $staff->hospital_id;
    }
}
