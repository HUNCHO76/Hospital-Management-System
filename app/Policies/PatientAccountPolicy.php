<?php

namespace App\Policies;

use App\Models\PatientAccount;
use App\Models\User;

/**
 * Mirrors StockItemPolicy's own hospital-scoping precedent exactly (Phase 7,
 * Step 52): only 'hospital-admin' is fenced to their own hospital; every
 * other role holding the permission is currently unrestricted across
 * hospitals, the same systemic behavior every other policy in this app
 * already has.
 */
class PatientAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-accounts.view');
    }

    public function view(User $user, PatientAccount $account): bool
    {
        if (! $user->can('billing-accounts.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $account->hospital_id);
    }

    /**
     * A hospital-admin is restricted to their own hospital's accounts;
     * every other role holding the permission (currently 'accountant' and
     * super-admin, which bypasses policies entirely via Gate::before) is
     * unrestricted — mirrors StockItemPolicy::belongsToHospital() exactly.
     */
    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
