<?php

namespace App\Policies;

use App\Models\PartographEntry;
use App\Models\User;

class PartographEntryPolicy
{
    public function view(User $user, PartographEntry $entry): bool
    {
        return $user->can('maternity.view') || $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('maternity.partograph-record') || $user->can('inpatient-nursing.view');
    }
}
