<?php

namespace App\Policies;

use App\Models\InsuranceProvider;
use App\Models\User;

class InsuranceProviderPolicy
{
    /**
     * Determine whether the user can view any insurance providers.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('insurance-providers.view');
    }

    /**
     * Determine whether the user can view the insurance provider.
     */
    public function view(User $user, InsuranceProvider $insuranceProvider): bool
    {
        return $user->can('insurance-providers.view');
    }

    /**
     * Determine whether the user can create insurance providers.
     */
    public function create(User $user): bool
    {
        return $user->can('insurance-providers.create');
    }

    /**
     * Determine whether the user can update the insurance provider.
     */
    public function update(User $user, InsuranceProvider $insuranceProvider): bool
    {
        return $user->can('insurance-providers.update');
    }

    /**
     * Determine whether the user can delete the insurance provider.
     */
    public function delete(User $user, InsuranceProvider $insuranceProvider): bool
    {
        return $user->can('insurance-providers.delete');
    }
}
