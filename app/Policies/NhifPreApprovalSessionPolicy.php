<?php

namespace App\Policies;

use App\Models\NhifPreApprovalSession;
use App\Models\User;

class NhifPreApprovalSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('nhif-preapproval.view');
    }

    public function view(User $user, NhifPreApprovalSession $session): bool
    {
        return $user->hasPermissionTo('nhif-preapproval.view')
            && $this->sameHospital($user, $session);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('nhif-preapproval.create');
    }

    public function update(User $user, NhifPreApprovalSession $session): bool
    {
        return $user->hasPermissionTo('nhif-preapproval.create')
            && $this->sameHospital($user, $session);
    }

    protected function sameHospital(User $user, NhifPreApprovalSession $session): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $staff = $user->staff;

        if ($staff === null) {
            return false;
        }

        return $staff->hospital_id === $session->hospital_id;
    }
}
