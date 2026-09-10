<?php

namespace App\Policies;

use App\Models\Medication;
use App\Models\User;

/**
 * Medication is a stateless catalog, not a clinical record — no
 * hospital/ownership scoping applies, only the base permission (mirrors
 * DiagnosisCodePolicy for the same reason).
 */
class MedicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('medications.view');
    }

    public function view(User $user, Medication $medication): bool
    {
        return $user->can('medications.view');
    }
}
