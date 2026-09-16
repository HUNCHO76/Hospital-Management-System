<?php

namespace App\Policies;

use App\Models\Medication;
use App\Models\User;

/**
 * Medication is a stateless catalog, not a clinical record — no
 * hospital/ownership scoping applies, only the base permission (mirrors
 * DiagnosisCodePolicy/LaboratoryTestPolicy/RadiologyProcedurePolicy for the
 * same reason). Catalog curation (create/update/activate/deactivate) is an
 * administrative function in this app — a doctor/pharmacist can use the
 * catalog when prescribing (`.view`) but does not manage it, mirroring how
 * laboratory-tests.* / radiology-procedures.* are restricted to hospital-admin.
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

    public function create(User $user): bool
    {
        return $user->can('medications.create');
    }

    public function update(User $user, Medication $medication): bool
    {
        return $user->can('medications.update');
    }

    public function activate(User $user, Medication $medication): bool
    {
        return $user->can('medications.activate');
    }

    public function deactivate(User $user, Medication $medication): bool
    {
        return $user->can('medications.deactivate');
    }
}
