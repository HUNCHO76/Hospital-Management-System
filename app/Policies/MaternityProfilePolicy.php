<?php

namespace App\Policies;

use App\Models\MaternityProfile;
use App\Models\User;

class MaternityProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('maternity.view') || $user->can('inpatient-nursing.view');
    }

    public function view(User $user, MaternityProfile $profile): bool
    {
        return $user->can('maternity.view') || $user->can('inpatient-nursing.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('maternity.manage') || $user->can('inpatient-nursing.view');
    }
}
