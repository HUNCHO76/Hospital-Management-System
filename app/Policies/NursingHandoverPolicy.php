<?php

namespace App\Policies;

use App\Models\NursingHandover;
use App\Models\User;

class NursingHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inpatient-nursing.view');
    }

    public function view(User $user, NursingHandover $handover): bool
    {
        return $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inpatient-nursing.handover-create');
    }

    public function correct(User $user, NursingHandover $handover): bool
    {
        return $user->can('inpatient-nursing.handover-create');
    }
}
