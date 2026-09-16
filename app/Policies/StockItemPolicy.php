<?php

namespace App\Policies;

use App\Models\StockItem;
use App\Models\User;

/**
 * Mirrors PrescriptionOrderPolicy/RadiologyOrderPolicy's own hospital-scoping
 * precedent exactly (Phase 6, Step 49): only 'hospital-admin' is fenced to
 * their own hospital; every other role holding the permission is currently
 * unrestricted across hospitals, the same systemic behavior every other
 * clinical/pharmacy policy in this app already has. StockItem carries
 * `hospital_id` directly (no encounter/department chain to walk), which is
 * what makes this policy simpler than PrescriptionOrderPolicy's own version
 * of the same check.
 */
class StockItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pharmacy-stock.view');
    }

    public function view(User $user, StockItem $stockItem): bool
    {
        if (! $user->can('pharmacy-stock.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $stockItem->hospital_id);
    }

    /**
     * Determine whether the user may receive stock into the given hospital —
     * checked before a StockItem necessarily exists yet (spec §5's
     * auto-provisioning on first receipt), so this takes a hospital id
     * rather than a model instance.
     */
    public function receiveInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('pharmacy-stock.receive')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    public function adjust(User $user, StockItem $stockItem): bool
    {
        if (! $user->can('pharmacy-stock.adjust')) {
            return false;
        }

        return $this->belongsToHospital($user, $stockItem->hospital_id);
    }

    public function quarantine(User $user, StockItem $stockItem): bool
    {
        if (! $user->can('pharmacy-stock.quarantine')) {
            return false;
        }

        return $this->belongsToHospital($user, $stockItem->hospital_id);
    }

    public function release(User $user, StockItem $stockItem): bool
    {
        if (! $user->can('pharmacy-stock.release')) {
            return false;
        }

        return $this->belongsToHospital($user, $stockItem->hospital_id);
    }

    public function viewTransactions(User $user, StockItem $stockItem): bool
    {
        if (! $user->can('pharmacy-stock.view_transactions')) {
            return false;
        }

        return $this->belongsToHospital($user, $stockItem->hospital_id);
    }

    /**
     * A hospital-admin is restricted to their own hospital's stock; every
     * other role holding the permission (currently pharmacist and
     * super-admin, which bypasses policies entirely via Gate::before) is
     * unrestricted — mirrors PrescriptionOrderPolicy::belongsToUsersHospital()
     * exactly.
     */
    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
