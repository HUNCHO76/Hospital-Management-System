<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Service is a global, stateless catalog, not a clinical or facility record
 * — no hospital/ownership scoping applies, only the base permission
 * (mirrors MedicationPolicy/LaboratoryTestPolicy/RadiologyProcedurePolicy
 * exactly, for the identical reason: this catalog has no `hospital_id`
 * column at all).
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-services.view');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('billing-services.view');
    }

    public function create(User $user): bool
    {
        return $user->can('billing-services.create');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('billing-services.update');
    }

    public function activate(User $user, Service $service): bool
    {
        return $user->can('billing-services.activate');
    }

    public function deactivate(User $user, Service $service): bool
    {
        return $user->can('billing-services.deactivate');
    }
}
