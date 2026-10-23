<?php

namespace App\Policies;

use App\Models\NhifClaim;
use App\Models\NhifClaimDocument;
use App\Models\User;

class NhifClaimPolicy
{
    /**
     * Determine whether the user can view any claims.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('nhif-claims.view');
    }

    /**
     * Determine whether the user can view the specific claim.
     */
    public function view(User $user, NhifClaim $claim): bool
    {
        return $user->can('nhif-claims.view')
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can create claims generally.
     */
    public function create(User $user): bool
    {
        return $user->can('nhif-claims.create');
    }

    /**
     * Determine whether the user may create a claim against a specific hospital facility.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        return $user->can('nhif-claims.create')
            && $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * Determine whether the user can update the claim.
     */
    public function update(User $user, NhifClaim $claim): bool
    {
        return $user->can('nhif-claims.update')
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can delete or cancel the claim.
     */
    public function delete(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.cancel') || $user->can('nhif-claims.update'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can sign the claim folio.
     */
    public function sign(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.sign') || $user->can('nhif-claims.update'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can submit the claim to NHIF.
     */
    public function submit(User $user, NhifClaim $claim): bool
    {
        return $user->can('nhif-claims.submit')
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can retrieve an NHIF submission receipt.
     */
    public function receipt(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.receipt') || $user->can('nhif-claims.view'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can reconcile an NHIF claim response.
     */
    public function reconcile(User $user, NhifClaim $claim): bool
    {
        return $user->can('nhif-claims.reconcile')
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can view monthly claim summaries.
     */
    public function monthlyView(User $user): bool
    {
        return $user->can('nhif-claims.monthly-view');
    }

    /**
     * Determine whether the user can calculate monthly claim totals.
     */
    public function monthlyCalculate(User $user): bool
    {
        return $user->can('nhif-claims.monthly-calculate')
            || $user->can('nhif-claims.monthly-view');
    }

    /**
     * Determine whether the user can submit monthly batch claims.
     */
    public function monthlySubmit(User $user): bool
    {
        return $user->can('nhif-claims.monthly-submit');
    }

    /**
     * Determine whether the user can finalize the claim.
     */
    public function finalize(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.finalize') || $user->can('nhif-claims.submit'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can perform or view bill confirmation requests.
     */
    public function billConfirmation(User $user): bool
    {
        return $user->can('nhif-claims.bill-confirmation');
    }

    /**
     * Determine whether the user can cancel the claim.
     */
    public function cancel(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.cancel') || $user->can('nhif-claims.update'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can reopen a cancelled claim.
     */
    public function reopen(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.reopen') || $user->can('nhif-claims.update'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can upload a supporting document to the claim.
     */
    public function uploadDocument(User $user, NhifClaim $claim): bool
    {
        return ($user->can('nhif-claims.update') || $user->can('nhif-claims.create'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can download a claim document.
     */
    public function downloadDocument(User $user, NhifClaim $claim, ?NhifClaimDocument $document = null): bool
    {
        return $user->can('nhif-claims.view')
            && $this->sameHospital($user, $claim);
    }

    /**
     * Determine whether the user can delete a claim document.
     */
    public function deleteDocument(User $user, NhifClaim $claim, ?NhifClaimDocument $document = null): bool
    {
        return ($user->can('nhif-claims.update') || $user->can('nhif-claims.cancel'))
            && $this->sameHospital($user, $claim);
    }

    /**
     * Check if user and claim belong to the same facility.
     */
    public function sameHospital(User $user, NhifClaim $claim): bool
    {
        return $this->belongsToHospital($user, (int) $claim->hospital_id);
    }

    /**
     * Check if user belongs to the given hospital facility ID.
     */
    public function belongsToHospital(User $user, int $hospitalId): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $staff = $user->staff;

        if ($staff === null) {
            return false;
        }

        return (int) $staff->hospital_id === $hospitalId;
    }
}
