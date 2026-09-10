<?php

namespace App\Policies;

use App\Models\LaboratoryTest;
use App\Models\User;

/**
 * LaboratoryTest is a stateless catalog, not a clinical record — no
 * hospital/ownership scoping applies, only the base permission (mirrors
 * DiagnosisCodePolicy/MedicationPolicy for the same reason). Catalog
 * curation (create/update/activate/deactivate) is an administrative
 * function in this app — no non-admin role manages DiagnosisCode/Medication
 * either (Phase 4, Step 29).
 */
class LaboratoryTestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-tests.view');
    }

    public function view(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->can('laboratory-tests.view');
    }

    public function create(User $user): bool
    {
        return $user->can('laboratory-tests.create');
    }

    public function update(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->can('laboratory-tests.update');
    }

    public function activate(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->can('laboratory-tests.activate');
    }

    public function deactivate(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->can('laboratory-tests.deactivate');
    }
}
