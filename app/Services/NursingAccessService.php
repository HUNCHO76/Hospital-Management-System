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

        if ($staff->department_id !== null && (int) $staff->department_id === (int) $admission->department_id) {
            return true;
        }

        $admission->loadMissing(['department', 'maternityProfile']);
        $staff->loadMissing('department');

        $admissionDeptCode = strtoupper($admission->department?->code ?? '');
        $staffDeptCode = strtoupper($staff->department?->code ?? '');

        // Maternity Patient Access: If patient is in Maternity or has a maternity profile,
        // authorized maternity clinicians, midwives, and nurses in the facility have access.
        $isMaternity = $admissionDeptCode === 'MATERNITY'
            || strtolower($admission->department?->name ?? '') === 'maternity'
            || $admission->maternityProfile !== null
            || in_array(strtolower($admission->ward_type ?? ''), ['maternity', 'labour', 'postpartum', 'antenatal'], true);

        if ($isMaternity && ($user->can('maternity.view') || $user->can('maternity.manage') || $user->hasAnyRole(['midwife', 'nurse', 'doctor']))) {
            return true;
        }

        // Clinical Inpatient & Maternity ward cross-access for ward nursing staff
        $inpatientDeptCodes = ['INPATIENT', 'MATERNITY'];
        if (in_array($staffDeptCode, $inpatientDeptCodes, true) && in_array($admissionDeptCode, $inpatientDeptCodes, true)) {
            if ($user->can('inpatient-nursing.view') || $user->can('maternity.view') || $user->hasAnyRole(['nurse', 'midwife', 'doctor'])) {
                return true;
            }
        }

        return false;
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

        $staff->loadMissing('department');
        $staffDeptCode = strtoupper($staff->department?->code ?? '');
        $inpatientDeptCodes = ['INPATIENT', 'MATERNITY'];

        if (in_array($staffDeptCode, $inpatientDeptCodes, true) || $user->can('maternity.view')) {
            return $query->where(function ($q) use ($staff, $inpatientDeptCodes) {
                if ($staff->department_id !== null) {
                    $q->where('department_id', $staff->department_id);
                }
                $q->orWhereHas('department', function ($dq) use ($inpatientDeptCodes) {
                    $dq->whereIn('code', $inpatientDeptCodes);
                })
                    ->orWhereHas('maternityProfile');
            });
        }

        if ($staff->department_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('department_id', $staff->department_id);
    }
}
