<?php

namespace App\Policies;

use App\Models\BillingAdjustment;
use App\Models\Invoice;
use App\Models\User;

/**
 * Mirrors InvoicePolicy's hospital-scoping pattern (Phase 7, Step 58).
 */
class BillingAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-adjustments.view');
    }

    public function view(User $user, BillingAdjustment $adjustment): bool
    {
        if (! $user->can('billing-adjustments.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $adjustment->hospital_id);
    }

    /**
     * Determine whether the user may create an adjustment against the given
     * invoice — checked via the Invoice model, mirroring PaymentPolicy::
     * createInto()'s own shape.
     */
    public function createAgainst(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-adjustments.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    protected function belongsToHospital(User $user, int $hospitalId): bool
    {
        if (! $user->hasRole('hospital-admin')) {
            return true;
        }

        return $user->staff?->hospital_id === $hospitalId;
    }
}
