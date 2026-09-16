<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;

/**
 * Mirrors PaymentPolicy's hospital-scoping pattern exactly (Phase 7, Step 58).
 */
class PaymentRefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-refunds.view');
    }

    public function view(User $user, PaymentRefund $refund): bool
    {
        if (! $user->can('billing-refunds.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $refund->hospital_id);
    }

    /**
     * Determine whether the user may create a refund against a payment at the
     * given hospital — checked via the Payment model, before a PaymentRefund
     * necessarily exists yet.
     */
    public function createAgainst(User $user, Payment $payment): bool
    {
        if (! $user->can('billing-refunds.create')) {
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
