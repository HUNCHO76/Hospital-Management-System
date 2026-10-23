<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WoundCareEvent;

class WoundCareEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inpatient-nursing.view');
    }

    public function view(User $user, WoundCareEvent $event): bool
    {
        return $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inpatient-nursing.wound-care-manage');
    }

    public function correct(User $user, WoundCareEvent $event): bool
    {
        return $user->can('inpatient-nursing.wound-care-manage');
    }
}
