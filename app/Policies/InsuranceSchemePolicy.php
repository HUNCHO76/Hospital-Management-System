<?php

namespace App\Policies;

use App\Models\InsuranceScheme;
use App\Models\User;

class InsuranceSchemePolicy
{
    /**
     * Determine whether the user can view any insurance schemes.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('insurance-schemes.view');
    }

    /**
     * Determine whether the user can view the insurance scheme.
     */
    public function view(User $user, InsuranceScheme $insuranceScheme): bool
    {
        return $user->can('insurance-schemes.view');
    }

    /**
     * Determine whether the user can create insurance schemes.
     */
    public function create(User $user): bool
    {
        return $user->can('insurance-schemes.create');
    }

    /**
     * Determine whether the user can update the insurance scheme.
     */
    public function update(User $user, InsuranceScheme $insuranceScheme): bool
    {
        return $user->can('insurance-schemes.update');
    }

    /**
     * Determine whether the user can delete the insurance scheme.
     */
    public function delete(User $user, InsuranceScheme $insuranceScheme): bool
    {
        return $user->can('insurance-schemes.delete');
    }
}
