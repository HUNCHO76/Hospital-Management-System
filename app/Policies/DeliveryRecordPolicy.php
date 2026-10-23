<?php

namespace App\Policies;

use App\Models\DeliveryRecord;
use App\Models\User;

class DeliveryRecordPolicy
{
    public function view(User $user, DeliveryRecord $record): bool
    {
        return $user->can('maternity.view') || $user->can('inpatient-nursing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('maternity.delivery-record') || $user->can('inpatient-nursing.view');
    }
}
