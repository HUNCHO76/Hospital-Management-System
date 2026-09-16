<?php

namespace App\Policies;

use App\Models\Charge;
use App\Models\User;

/**
 * Mirrors PatientAccountPolicy/StockItemPolicy's own hospital-scoping
 * precedent exactly (Phase 7, Step 52).
 */
class ChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-charges.view');
    }

    public function view(User $user, Charge $charge): bool
    {
        if (! $user->can('billing-charges.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $charge->hospital_id);
    }

    /**
     * Determine whether the user may create a charge against the given
     * hospital — checked before a Charge necessarily exists yet, mirroring
     * StockItemPolicy::receiveInto()'s own "hospital id, not a model
     * instance" shape.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('billing-charges.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * Post/void/cancel all share this ability (spec §29's own permission
     * list has no separate ability for each transition) — the same actor
     * trusted to create a charge is trusted to manage its lifecycle before
     * any payment/insurance involvement exists to complicate that.
     */
    public function manage(User $user, Charge $charge): bool
    {
        if (! $user->can('billing-charges.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $charge->hospital_id);
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
