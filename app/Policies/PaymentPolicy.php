<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Mirrors InvoicePolicy/ChargePolicy's own hospital-scoping precedent
 * exactly (Phase 7, Step 57): `Payment` carries `hospital_id` directly —
 * only 'hospital-admin' is fenced to their own hospital; every other role
 * holding the permission is currently unrestricted, the same systemic
 * behavior every other billing policy in this app already has.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        if (! $user->can('billing-payments.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $payment->hospital_id);
    }

    /**
     * Determine whether the user may record a payment against the given
     * hospital — checked before a Payment necessarily exists yet, mirroring
     * ChargePolicy::createInto()/InvoicePolicy::createInto()'s own "hospital
     * id, not a model instance" shape.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('billing-payments.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * The one controlled correction this step provides (markFailed()) —
     * kept as its own, separately-grantable ability, mirroring
     * InvoicePolicy::void()'s own precedent of not folding a corrective
     * action into the ordinary create ability.
     */
    public function void(User $user, Payment $payment): bool
    {
        if (! $user->can('billing-payments.void')) {
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
