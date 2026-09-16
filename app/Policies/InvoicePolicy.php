<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Mirrors ChargePolicy's own hospital-scoping precedent exactly (Phase 7,
 * Step 55) — only `hospital-admin` is fenced to its own hospital, every
 * other permission-holding role is unrestricted across hospitals, the same
 * systemic behavior every other billing policy already has.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-invoices.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    /**
     * Determine whether the user may start a draft invoice against the
     * given hospital — checked before an Invoice necessarily exists yet,
     * mirroring ChargePolicy::createInto()'s own shape.
     */
    public function createInto(User $user, int $hospitalId): bool
    {
        if (! $user->can('billing-invoices.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $hospitalId);
    }

    /**
     * Add/remove a charge while still DRAFT — part of the same "build the
     * invoice" activity as creating it, so shares its permission (mirrors
     * ChargePolicy::manage() folding post/void/cancel into 'create').
     */
    public function manage(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-invoices.create')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    public function post(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-invoices.post')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    /**
     * Void (DRAFT) and cancel (POSTED) share this ability — spec §33's own
     * suggested permission list has no separate ability per terminal
     * transition, the same reasoning ChargePolicy::manage() already applies
     * to charge void/cancel.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-invoices.void')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->void($user, $invoice);
    }

    /**
     * View the invoice's insurance/coverage breakdown (Phase 7, Step 56) —
     * a distinct permission from 'billing-invoices.view' since not every
     * invoice viewer needs to see insurance calculation detail (spec §34).
     */
    public function viewInsurance(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-insurance.view')) {
            return false;
        }

        return $this->belongsToHospital($user, $invoice->hospital_id);
    }

    /**
     * Calculate (or recalculate, while still DRAFT) the invoice's insurance
     * coverage allocation.
     */
    public function calculateInsurance(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing-insurance.calculate')) {
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
