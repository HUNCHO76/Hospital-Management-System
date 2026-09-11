<?php

namespace App\Policies;

use App\Models\RadiologyProcedure;
use App\Models\User;

/**
 * RadiologyProcedure is a stateless catalog, not a clinical record — no
 * hospital/ownership scoping applies, only the base permission (mirrors
 * LaboratoryTestPolicy exactly). Catalog curation (create/update/activate/
 * deactivate) is an administrative function in this app — a normal doctor
 * can use the catalog when ordering (`.view`) but does not manage it,
 * mirroring how laboratory-tests.* is restricted to hospital-admin.
 */
class RadiologyProcedurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('radiology-procedures.view');
    }

    public function view(User $user, RadiologyProcedure $radiologyProcedure): bool
    {
        return $user->can('radiology-procedures.view');
    }

    public function create(User $user): bool
    {
        return $user->can('radiology-procedures.create');
    }

    public function update(User $user, RadiologyProcedure $radiologyProcedure): bool
    {
        return $user->can('radiology-procedures.update');
    }

    public function activate(User $user, RadiologyProcedure $radiologyProcedure): bool
    {
        return $user->can('radiology-procedures.activate');
    }

    public function deactivate(User $user, RadiologyProcedure $radiologyProcedure): bool
    {
        return $user->can('radiology-procedures.deactivate');
    }
}
