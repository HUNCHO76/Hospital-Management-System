<?php

namespace App\Models\Concerns;

use App\Models\UserPermissionOverride;
use App\Services\PermissionResolutionService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Trait providing individual user permission override resolution for the User model.
 */
trait HasPermissionOverrides
{
    /**
     * Relationship to the user's explicit permission overrides.
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /**
     * Determine if the user may perform the given permission, taking individual overrides into account.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     *
     * @throws PermissionDoesNotExist
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = $this->extractPermissionName($permission);

        if ($permissionName !== null) {
            $overrides = $this->getCachedPermissionOverrides();

            if (isset($overrides[$permissionName])) {
                if ($overrides[$permissionName] === UserPermissionOverride::EFFECT_DENY) {
                    return false;
                }
                if ($overrides[$permissionName] === UserPermissionOverride::EFFECT_ALLOW) {
                    return true;
                }
            }
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * An alias to hasPermissionTo(), but avoids throwing an exception if the permission does not exist.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        try {
            return $this->hasPermissionTo($permission, $guardName);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Return all effective permissions the user has (roles + direct + allowed overrides, minus denied overrides).
     */
    public function getAllPermissions(): Collection
    {
        /** @var Collection $permissions */
        $permissions = $this->spatieGetAllPermissions()->keyBy('name');

        $overrides = $this->getCachedPermissionOverrides();

        foreach ($overrides as $name => $effect) {
            if ($effect === UserPermissionOverride::EFFECT_DENY) {
                $permissions->forget($name);
            } elseif ($effect === UserPermissionOverride::EFFECT_ALLOW && ! $permissions->has($name)) {
                try {
                    $perm = Permission::findByName($name, 'web');
                    if ($perm) {
                        $permissions->put($name, $perm);
                    }
                } catch (\Throwable) {
                    // Ignore non-existent permission
                }
            }
        }

        return $permissions->values();
    }

    /**
     * Return the cached map of [permission_name => 'allow' | 'deny'].
     *
     * @return array<string, string>
     */
    public function getCachedPermissionOverrides(): array
    {
        return app(PermissionResolutionService::class)->getOverridesForUser($this);
    }

    /**
     * Flush cached overrides for this user.
     */
    public function forgetCachedPermissionOverrides(): void
    {
        app(PermissionResolutionService::class)->clearUserCache($this);
    }

    /**
     * Safely extract permission name string from various permission types.
     */
    protected function extractPermissionName($permission): ?string
    {
        if (is_string($permission)) {
            return $permission;
        }

        if ($permission instanceof Permission) {
            return $permission->name;
        }

        if ($permission instanceof \BackedEnum) {
            return (string) $permission->value;
        }

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            try {
                return Permission::findById($permission, 'web')?->name;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
