<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Facility and ward scoping for inpatient nursing access.
 *
 * Rule set (deny by default):
 *  - super-admin may access everything.
 *  - everyone else must have a Staff profile; the admission must belong to the
 *    staff member's hospital (a nurse never sees another facility's patients).
 *  - unless the user also holds 'inpatient-nursing.view-all-wards', the
 *    admission's department (ward/unit) must be the staff member's own
 *    department.
 */
class NursingAccessService
{
    public function canAccess(User $user, Admission $admission): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $staff = $user->staff;

        if ($staff === null || $staff->hospital_id === null) {
            return false;
        }

        if ((int) $staff->hospital_id !== (int) $admission->hospital_id) {
            return false;
        }

        if ($user->can('inpatient-nursing.view-all-wards')) {
            return true;
        }

        return $staff->department_id !== null
            && (int) $staff->department_id === (int) $admission->department_id;
    }

    /**
     * Restrict an admissions query to what the user may access.
     *
     * @param  Builder<Admission>  $query
     * @return Builder<Admission>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super-admin')) {
            return $query;
        }

        $staff = $user->staff;

        if ($staff === null || $staff->hospital_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('hospital_id', $staff->hospital_id);

        if ($user->can('inpatient-nursing.view-all-wards')) {
            return $query;
        }

        if ($staff->department_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('department_id', $staff->department_id);
    }
}
