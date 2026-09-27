<?php

namespace App\Policies;

use App\Models\LaboratoryTestParameter;
use App\Models\User;

/**
 * Parameters are stateless catalog data — no hospital/ownership scoping,
 * only the permission check (mirrors LaboratoryTestPolicy / LaboratoryBenchPolicy).
 * A single 'laboratory-test-parameters.manage' permission gates all mutations;
 * viewing is covered by the parent test's 'laboratory-tests.view'.
 */
class LaboratoryTestParameterPolicy
{
    public function manage(User $user, LaboratoryTestParameter $parameter): bool
    {
        return $user->can('laboratory-test-parameters.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('laboratory-test-parameters.manage');
    }

    public function delete(User $user, LaboratoryTestParameter $parameter): bool
    {
        return $user->can('laboratory-test-parameters.manage');
    }
}
