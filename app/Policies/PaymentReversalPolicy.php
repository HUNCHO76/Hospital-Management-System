<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;

/**
 * Mirrors PaymentPolicy's hospital-scoping pattern exactly (Phase 7, Step 58):
 * only 'hospital-admin' is fenced to their own hospital; every other role
 * holding the permission is unrestricted.
 */
class PaymentReversalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-reversals.view');
    }

    public function view(User $user, PaymentReversal $reversal): bool
    {
        if (! $user->can('billing-reversals.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $reversal->hospital_id);
    }

    /**
     * Determine whether the user may create a reversal against a payment at
     * the given hospital — checked via the Payment model, before a
     * PaymentReversal necessarily exists yet, mirroring PaymentPolicy::
     * createInto()'s own shape.
     */
    public function createAgainst(User $user, Payment $payment): bool
    {
        if (! $user->can('billing-reversals.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $payment->hospital_id);
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
