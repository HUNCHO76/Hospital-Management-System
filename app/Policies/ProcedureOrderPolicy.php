<?php

namespace App\Policies;

use App\Models\ProcedureOrder;
use App\Models\User;

class ProcedureOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('procedures.view') || $user->can('inpatient-nursing.view') || $user->can('clinical-encounters.view');
    }

    public function view(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.view') || $user->can('inpatient-nursing.view') || $user->can('clinical-encounters.view');
    }

    public function create(User $user): bool
    {
        return $user->can('procedures.order') || $user->can('clinical-encounters.manage') || $user->can('inpatient-nursing.view');
    }

    public function update(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.order') || $user->can('procedures.manage') || $user->can('clinical-encounters.manage');
    }

    public function execute(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.manage') || $user->can('surgical.operate') || $user->can('inpatient-nursing.view') || $user->can('clinical-encounters.manage');
    }

    public function cancel(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.order') || $user->can('procedures.manage') || $user->can('clinical-encounters.manage');
    }

    public function manage(User $user, ProcedureOrder $order): bool
    {
        return $user->can('procedures.manage') || $user->can('inpatient-nursing.view') || $user->can('clinical-encounters.manage');
    }
}
