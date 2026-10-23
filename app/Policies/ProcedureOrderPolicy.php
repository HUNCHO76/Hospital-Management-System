<?php

namespace App\Policies;

use App\Models\ProcedureOrder;
use App\Models\User;

class ProcedureOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('procedures.view') || $user->can('inpatient-nursing.view');
    }

    public function view(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.view') || $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('procedures.order') || $user->can('inpatient-nursing.view');
    }

    public function manage(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.manage') || $user->can('inpatient-nursing.view');
    }
}
