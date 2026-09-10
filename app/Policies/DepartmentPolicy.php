<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    /**
     * Determine whether the user can view any departments.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('departments.view');
    }

    /**
     * Determine whether the user can view the department.
     */
    public function view(User $user, Department $department): bool
    {
        return $user->can('departments.view');
    }

    /**
     * Determine whether the user can create departments.
     *
     * The hospital the department will belong to is validated separately in
     * StoreDepartmentRequest, since no Department instance exists yet here.
     */
    public function create(User $user): bool
    {
        return $user->can('departments.create');
    }

    /**
     * Determine whether the user can update the department.
     *
     * A hospital-admin may only manage departments within the hospital their
     * own staff record belongs to — never another facility's departments.
     */
    public function update(User $user, Department $department): bool
    {
        if (! $user->can('departments.update')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $department);
    }

    /**
     * Determine whether the user can delete the department.
     */
    public function delete(User $user, Department $department): bool
    {
        if (! $user->can('departments.delete')) {
            return false;
        }

        return $this->belongsToUsersHospital($user, $department);
    }

    /**
     * A hospital-admin is restricted to their own hospital's departments;
     * every other role holding the permission (currently only super-admin,
     * which bypasses policies entirely via Gate::before) is unrestricted.
     */
    protected function belongsToUsersHospital(User $user, Department $department): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $department->hospital_id;
    }
}
