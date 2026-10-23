<?php

namespace App\Policies;

use App\Models\PatientAllergy;
use App\Models\User;

class PatientAllergyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('patient-allergies.view');
    }

    public function view(User $user, PatientAllergy $allergy): bool
    {
        return $user->can('patient-allergies.view');
    }

    public function create(User $user): bool
    {
        return $user->can('patient-allergies.manage');
    }

    public function update(User $user, PatientAllergy $allergy): bool
    {
        return $user->can('patient-allergies.manage');
    }

    public function resolve(User $user, PatientAllergy $allergy): bool
    {
        return $user->can('patient-allergies.manage');
    }
}
