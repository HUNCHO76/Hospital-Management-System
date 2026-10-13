<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the page a user should land on right after logging in, based on
 * the permissions they actually hold, so they never land on a page they
 * cannot open.
 */
class RoleHomeResolver
{
    /**
     * Roles that oversee the whole system and always land on the dashboard.
     *
     * @var list<string>
     */
    protected const ADMIN_ROLES = ['super-admin', 'hospital-admin', 'Manager'];

    /**
     * Ordered [permission, route name] pairs; the first one the user can use wins.
     *
     * @var list<array{0: string, 1: string}>
     */
    protected const HOMES = [
        ['reception.dashboard', 'reception.dashboard'],
        ['clinical-encounters.view', 'clinical-encounters.index'],
        ['laboratory.view', 'laboratory.index'],
        ['radiology.view', 'radiology.index'],
        ['pharmacy.view', 'pharmacy.index'],
        ['billing.view', 'billing.index'],
        ['procurement.view', 'procurement.dashboard'],
        ['stores.view', 'stores.dashboard'],
    ];

    public static function for(User $user): string
    {
        if (! $user->hasAnyRole(self::ADMIN_ROLES)) {
            foreach (self::HOMES as [$permission, $route]) {
                if ($user->can($permission) && Route::has($route)) {
                    return route($route, absolute: false);
                }
            }
        }

        return route('dashboard', absolute: false);
    }
}
