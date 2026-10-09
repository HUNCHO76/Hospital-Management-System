<?php

namespace App\Policies;

use App\Models\Admission;
use App\Models\User;

/**
 * Mirrors ChargePolicy's own hospital-scoping precedent exactly (Phase 9,
 * Step 71) — each action checks its own distinct `admission.*` ability
 * first (unlike ChargePolicy's single shared "manage" ability, the
 * `admission` permission group has a separate ability per transition), then
 * the same `belongsToHospital()` scoping.
 */
class AdmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admission.view');
    }

    public function view(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    /**
     * Determine whether the user may create an admission request against
     * the given hospital — checked before an Admission necessarily exists
     * yet, mirroring ChargePolicy::createInto()'s own "hospital id, not a
     * model instance" shape.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('admission.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    public function review(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.review')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function approve(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.approve')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function reject(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.reject')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function returnForCorrection(User $user, Admission $admission): bool
    {
        return $this->review($user, $admission);
    }

    public function confirm(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.confirm')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function discharge(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.discharge')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function cancel(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.cancel')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function submitNhif(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.nhif-submit')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function storeTransfer(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.transfer')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function completeTransfer(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.transfer')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function submitTransferNhif(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.nhif-submit')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    public function submitDischargeNhif(User $user, Admission $admission): bool
    {
        if (! $user->can('admission.nhif-submit')) {
            return false;
        }

        return $this->belongsToHospital($user, $admission->hospital_id);
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
