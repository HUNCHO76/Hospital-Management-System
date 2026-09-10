<?php

namespace App\Policies;

use App\Models\User;

/**
 * Prescription is never independently authorized against a model instance —
 * its create/update/cancel actions are always reached through the parent
 * CarePlanItem's own authorized action (CarePlanItemPolicy). This exists
 * purely as the extra, genuinely separate 'prescription.*' permission gate
 * spec §32 requires on top of 'treatment.*' whenever an item's type is
 * medication — mirrors DiagnosisCodePolicy's permission-only shape.
 */
class PrescriptionPolicy
{
    public function create(User $user): bool
    {
        return $user->can('prescription.create');
    }

    public function update(User $user): bool
    {
        return $user->can('prescription.update');
    }

    public function cancel(User $user): bool
    {
        return $user->can('prescription.cancel');
    }
}
