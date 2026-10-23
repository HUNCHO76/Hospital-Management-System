<?php

namespace App\Policies;

use App\Models\SurgicalCase;
use App\Models\User;

class SurgicalCasePolicy
{
    public function view(User $user, SurgicalCase $case): bool
    {
        return $user->can('procedures.view') || $user->can('surgical.theatre-manage') || $user->can('inpatient-nursing.view');
    }

    public function manage(User $user, SurgicalCase $case): bool
    {
        return $user->can('surgical.theatre-manage') || $user->can('surgical.operate') || $user->can('inpatient-nursing.view');
    }

    public function operate(User $user, SurgicalCase $case): bool
    {
        return $user->can('surgical.operate') || $user->can('inpatient-nursing.view');
    }
}
