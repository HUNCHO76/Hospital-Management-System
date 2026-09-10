<?php

namespace App\Policies;

use App\Models\LaboratoryBench;
use App\Models\User;

/**
 * A single combined 'laboratory-benches.manage' permission gates every
 * mutation, mirroring LaboratoryTestCategoryPolicy exactly — work areas
 * change about as rarely as test categories.
 */
class LaboratoryBenchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('laboratory-benches.view');
    }

    public function view(User $user, LaboratoryBench $laboratoryBench): bool
    {
        return $user->can('laboratory-benches.view');
    }

    public function create(User $user): bool
    {
        return $user->can('laboratory-benches.manage');
    }

    public function update(User $user, LaboratoryBench $laboratoryBench): bool
    {
        return $user->can('laboratory-benches.manage');
    }

    public function activate(User $user, LaboratoryBench $laboratoryBench): bool
    {
        return $user->can('laboratory-benches.manage');
    }

    public function deactivate(User $user, LaboratoryBench $laboratoryBench): bool
    {
        return $user->can('laboratory-benches.manage');
    }
}
