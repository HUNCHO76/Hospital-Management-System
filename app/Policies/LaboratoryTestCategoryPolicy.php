<?php

namespace App\Policies;

use App\Models\LaboratoryTestCategory;
use App\Models\User;

/**
 * A single combined 'laboratory-categories.manage' permission gates every
 * mutation (create/update/activate/deactivate) — categories change far less
 * often than tests, so the more granular per-action split used for
 * LaboratoryTestPolicy isn't warranted here (spec §26's own suggested list
 * only splits .view/.manage for categories).
 */
class LaboratoryTestCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-categories.view');
    }

    public function view(User $user, LaboratoryTestCategory $laboratoryTestCategory): bool
    {
        return $user->can('laboratory-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('laboratory-categories.manage');
    }

    public function update(User $user, LaboratoryTestCategory $laboratoryTestCategory): bool
    {
        return $user->can('laboratory-categories.manage');
    }

    public function activate(User $user, LaboratoryTestCategory $laboratoryTestCategory): bool
    {
        return $user->can('laboratory-categories.manage');
    }

    public function deactivate(User $user, LaboratoryTestCategory $laboratoryTestCategory): bool
    {
        return $user->can('laboratory-categories.manage');
    }
}
