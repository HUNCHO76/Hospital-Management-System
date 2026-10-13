<?php

namespace App\Policies;

use App\Models\ServicePrice;
use App\Models\User;

/**
 * Mirrors StockItemPolicy/ChargePolicy's own hospital-scoping precedent
 * exactly (Phase 7, Step 53): `ServicePrice` carries `hospital_id` directly
 * — only 'hospital-admin' is fenced to their own hospital; every other role
 * holding the permission is currently unrestricted across hospitals, the
 * same systemic behavior every other policy in this app already has.
 */
class ServicePricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-pricing.view');
    }

    public function view(User $user, ServicePrice $price): bool
    {
        if (! $user->can('billing-pricing.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $price->hospital_id);
    }

    /**
     * Determine whether the user may create a price into the given
     * hospital — checked before a ServicePrice necessarily exists yet,
     * mirroring StockItemPolicy::receiveInto()/ChargePolicy::createInto()'s
     * own "hospital id, not a model instance" shape.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('billing-pricing.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * Correct the amount of the current price in place — only an active,
     * open-ended price; closed/superseded/cancelled prices stay immutable.
     */
    public function update(User $user, ServicePrice $price): bool
    {
        if (! $user->can('billing-pricing.update')) {
            return false;
        }

        if ($price->status !== ServicePrice::STATUS_ACTIVE || $price->effective_to !== null) {
            return false;
        }

        return $this->belongsToHospital($user, $price->hospital_id);
    }

    public function cancel(User $user, ServicePrice $price): bool
    {
        if (! $user->can('billing-pricing.deactivate')) {
            return false;
        }

        return $this->belongsToHospital($user, $price->hospital_id);
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
