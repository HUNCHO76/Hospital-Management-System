<?php

namespace App\Policies;

use App\Models\NhifClaim;
use App\Models\User;

class NhifFolioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('nhif-claims.view');
    }

    public function view(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.view')
            && $this->sameHospital($user, $claim);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('nhif-claims.create');
    }

    public function update(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.update')
            && $this->sameHospital($user, $claim);
    }

    public function sign(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.sign')
            && $this->sameHospital($user, $claim);
    }

    public function submit(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.submit')
            && $this->sameHospital($user, $claim);
    }

    public function receipt(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.receipt')
            && $this->sameHospital($user, $claim);
    }

    public function reconcile(User $user, NhifClaim $claim): bool
    {
        return $user->hasPermissionTo('nhif-claims.reconcile')
            && $this->sameHospital($user, $claim);
    }

    public function monthlyView(User $user): bool
    {
        return $user->hasPermissionTo('nhif-claims.monthly-view');
    }

    public function monthlySubmit(User $user): bool
    {
        return $user->hasPermissionTo('nhif-claims.monthly-submit');
    }

    public function finalize(User $user, NhifClaim $claim): bool
    {
        return ($user->hasPermissionTo('nhif-claims.finalize') || $user->hasPermissionTo('nhif-claims.submit'))
            && $this->sameHospital($user, $claim);
    }

    public function billConfirmation(User $user): bool
    {
        return $user->hasPermissionTo('nhif-claims.bill-confirmation');
    }

    public function cancel(User $user, NhifClaim $claim): bool
    {
        return ($user->hasPermissionTo('nhif-claims.update') || $user->hasPermissionTo('nhif-claims.cancel'))
            && $this->sameHospital($user, $claim);
    }

    public function reopen(User $user, NhifClaim $claim): bool
    {
        return ($user->hasPermissionTo('nhif-claims.update') || $user->hasPermissionTo('nhif-claims.reopen'))
            && $this->sameHospital($user, $claim);
    }

    protected function sameHospital(User $user, NhifClaim $claim): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $staff = $user->staff;

        if ($staff === null) {
            return false;
        }

        return $staff->hospital_id === $claim->hospital_id;
    }
}
