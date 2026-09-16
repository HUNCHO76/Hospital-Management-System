<?php

namespace App\Policies;

use App\Models\InsuranceCoverageRule;
use App\Models\User;

/**
 * InsuranceCoverageRule is global, not hospital-scoped — mirrors
 * ServicePolicy exactly, for the identical reason: `InsuranceScheme` (Phase
 * 2) and `Service` (Phase 7, Step 53), the two catalogs a rule is keyed
 * against, are both already global with no `hospital_id` column
 * (Phase 7, Step 56).
 */
class InsuranceCoverageRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing-insurance-rules.view');
    }

    public function view(User $user, InsuranceCoverageRule $rule): bool
    {
        return $user->can('billing-insurance-rules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('billing-insurance-rules.create');
    }

    public function cancel(User $user, InsuranceCoverageRule $rule): bool
    {
        return $user->can('billing-insurance-rules.deactivate');
    }
}
