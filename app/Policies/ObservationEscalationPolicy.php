<?php

namespace App\Policies;

use App\Models\ObservationEscalation;
use App\Models\User;

class ObservationEscalationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inpatient-escalations.view') || $user->can('inpatient-nursing.view');
    }

    public function view(User $user, ObservationEscalation $escalation): bool
    {
        return $user->can('inpatient-escalations.view') || $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inpatient-nursing.escalate') || $user->can('inpatient-nursing.view');
    }

    public function acknowledge(User $user, ObservationEscalation $escalation): bool
    {
        return $user->can('inpatient-escalations.acknowledge');
    }

    public function resolve(User $user, ObservationEscalation $escalation): bool
    {
        return $user->can('inpatient-escalations.resolve');
    }
}
