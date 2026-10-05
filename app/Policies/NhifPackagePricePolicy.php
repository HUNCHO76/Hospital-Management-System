<?php

namespace App\Policies;

use App\Models\User;

class NhifPackagePricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('nhif-package-prices.view');
    }

    public function sync(User $user): bool
    {
        return $user->hasPermissionTo('nhif-package-prices.sync');
    }
}
