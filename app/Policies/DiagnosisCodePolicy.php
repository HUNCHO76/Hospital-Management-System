<?php

namespace App\Policies;

use App\Models\DiagnosisCode;
use App\Models\User;

/**
 * DiagnosisCode is a stateless catalog, not a clinical record — no
 * hospital/ownership scoping applies, only the base permission (mirrors
 * VitalSignPolicy::viewAny() for the same reason).
 */
class DiagnosisCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('diagnosis-codes.view');
    }

    public function view(User $user, DiagnosisCode $diagnosisCode): bool
    {
        return $user->can('diagnosis-codes.view');
    }
}
