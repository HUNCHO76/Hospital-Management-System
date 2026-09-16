<?php

namespace App\Policies;

use App\Models\CashierSession;
use App\Models\User;

/**
 * Mirrors PaymentReversalPolicy's hospital-scoping pattern exactly (Phase
 * 7, Step 59): only 'hospital-admin' is fenced to their own hospital;
 * every other role holding the permission is unrestricted. Session ownership
 * (only the session's own cashier may close it) is enforced additionally in
 * close() and in CashierSessionService::close().
 */
class CashierSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-cashier.view');
    }

    public function view(User $user, CashierSession $session): bool
    {
        if (! $user->can('billing-cashier.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $session->hospital_id);
    }

    /**
     * Whether the user may open a new session at the given hospital_id —
     * checked before any session row exists yet.
     */
    public function open(User $user, int $hospitalId): bool
    {
        if (! $user->can('billing-cashier.open')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * Whether the user may close the given session — requires close
     * permission, same-hospital scoping, AND ownership: only the cashier
     * who opened the session may close it (spec §59.4).
     */
    public function close(User $user, CashierSession $session): bool
    {
        if (! $user->can('billing-cashier.close')) {
            return false;
        }

        if (! $this->belongsToHospital($user, $session->hospital_id)) {
            return false;
        }

        return $user->id === $session->cashier_id;
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
