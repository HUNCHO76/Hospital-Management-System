<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Centralized service for resolving, auditing, and managing individual user permission overrides.
 *
 * Rules:
 * 1. A user inherits permissions from their assigned roles.
 * 2. A user-specific grant ('allow') allows an additional permission.
 * 3. A user-specific denial ('deny') overrides permissions inherited from roles or granted directly.
 * 4. Removing a user-specific override ('inherit' or deletion) returns permission to inherited/default state.
 * 5. If no override exists, standard Spatie role and direct-permission rules apply.
 */
class PermissionResolutionService
{
    public const CACHE_PREFIX = 'user_permission_overrides_';

    public const CACHE_TTL_SECONDS = 3600; // 1 hour

    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Get a map of [permission_name => effect ('allow' | 'deny')] for the user, with caching.
     *
     * @return array<string, string>
     */
    public function getOverridesForUser(User $user): array
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user) {
            return UserPermissionOverride::query()
                ->where('user_id', $user->id)
                ->join('permissions', 'user_permission_overrides.permission_id', '=', 'permissions.id')
                ->pluck('user_permission_overrides.effect', 'permissions.name')
                ->all();
        });
    }

    /**
     * Invalidate cached overrides for a specific user.
     */
    public function clearUserCache(User $user): void
    {
        Cache::forget(self::CACHE_PREFIX . $user->id);

        // Also flush Spatie's permission cache so gate re-evaluates
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Alias for clearUserCache.
     */
    public function clearUserPermissionCache(User $user): void
    {
        $this->clearUserCache($user);
    }

    /**
     * Resolve whether the user has effective authorization for a permission.
     */
    public function resolveEffectivePermission(User $user, string $permissionName): bool
    {
        $overrides = $this->getOverridesForUser($user);

        if (isset($overrides[$permissionName])) {
            if ($overrides[$permissionName] === UserPermissionOverride::EFFECT_DENY) {
                return false;
            }
            if ($overrides[$permissionName] === UserPermissionOverride::EFFECT_ALLOW) {
                return true;
            }
        }

        // Super-admin default bypass if not explicitly denied
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Standard Spatie inheritance
        try {
            return $user->spatieHasPermissionTo($permissionName, 'web');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return all effective permission models for the given user.
     *
     * @return Collection<int, Permission>
     */
    public function getEffectivePermissions(User $user): Collection
    {
        // 1. Base permissions (direct + role)
        $permissions = $user->getPermissionsViaRoles()->merge($user->permissions)->keyBy('name');

        // 2. Apply user-specific overrides
        $overrides = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->with('permission')
            ->get();

        foreach ($overrides as $override) {
            if (! $override->permission) {
                continue;
            }
            $permName = $override->permission->name;

            if ($override->effect === UserPermissionOverride::EFFECT_DENY) {
                $permissions->forget($permName);
            } elseif ($override->effect === UserPermissionOverride::EFFECT_ALLOW) {
                $permissions->put($permName, $override->permission);
            }
        }

        return $permissions->values();
    }

    /**
     * Build the detailed permission matrix for the User Management UI.
     *
     * @return array<string, mixed>
     */
    public function getPermissionMatrix(User $user): array
    {
        // Load all system permissions
        $allPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        // Roles assigned to user
        $roles = $user->roles()->with('permissions')->get();
        $roleNames = $roles->pluck('name')->all();

        // Map permission_id -> roles that grant it
        $roleInheritanceMap = [];
        foreach ($roles as $role) {
            // super-admin implicitly grants everything
            $grantsAll = $role->name === 'super-admin';
            if ($grantsAll) {
                foreach ($allPermissions as $perm) {
                    $roleInheritanceMap[$perm->id][] = $role->name;
                }
            } else {
                foreach ($role->permissions as $perm) {
                    $roleInheritanceMap[$perm->id][] = $role->name;
                }
            }
        }

        // Direct user permissions
        $directPermissionIds = $user->permissions->pluck('id')->all();

        // Current user overrides
        $overrides = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('permission_id');

        $groups = [];

        foreach ($allPermissions as $perm) {
            $inheritedRoles = array_unique($roleInheritanceMap[$perm->id] ?? []);
            $isInherited = ! empty($inheritedRoles);
            $isDirect = in_array($perm->id, $directPermissionIds, true);
            $override = $overrides->get($perm->id);
            $overrideEffect = $override?->effect; // 'allow', 'deny', or null

            // Determine effective status
            $isEffective = false;
            if ($overrideEffect === UserPermissionOverride::EFFECT_DENY) {
                $isEffective = false;
            } elseif ($overrideEffect === UserPermissionOverride::EFFECT_ALLOW) {
                $isEffective = true;
            } else {
                $isEffective = $isInherited || $isDirect;
            }

            $moduleInfo = $this->classifyPermission($perm->name);
            $groupName = $moduleInfo['group'];
            $moduleName = $moduleInfo['module'];

            $groups[$groupName][$moduleName][] = [
                'id' => $perm->id,
                'name' => $perm->name,
                'title' => $moduleInfo['title'],
                'description' => $moduleInfo['description'],
                'is_inherited' => $isInherited,
                'inherited_from_roles' => $inheritedRoles,
                'is_direct' => $isDirect,
                'override' => $overrideEffect,
                'override_reason' => $override?->reason,
                'override_updated_at' => $override?->updated_at?->toIso8601String(),
                'is_effective' => $isEffective,
            ];
        }

        // Format into structured array for React frontend
        $formattedGroups = [];
        foreach ($groups as $groupTitle => $modules) {
            $moduleList = [];
            foreach ($modules as $moduleTitle => $items) {
                $moduleList[] = [
                    'module' => $moduleTitle,
                    'permissions' => $items,
                ];
            }
            $formattedGroups[] = [
                'group' => $groupTitle,
                'modules' => $moduleList,
            ];
        }

        return [
            'user' => [
                'id' => $user->id,
                'username' => $user->UserName,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $roleNames,
                'staff' => $user->staff ? [
                    'id' => $user->staff->id,
                    'staff_number' => $user->staff->staff_number,
                    'full_name' => "{$user->staff->first_name} {$user->staff->last_name}",
                    'job_title' => $user->staff->job_title,
                    'department' => $user->staff->department?->name,
                    'hospital' => $user->staff->hospital?->name,
                ] : null,
            ],
            'permission_groups' => $formattedGroups,
            'counts' => [
                'total_system_permissions' => $allPermissions->count(),
                'inherited_count' => collect($roleInheritanceMap)->count(),
                'overridden_allow_count' => $overrides->where('effect', UserPermissionOverride::EFFECT_ALLOW)->count(),
                'overridden_deny_count' => $overrides->where('effect', UserPermissionOverride::EFFECT_DENY)->count(),
            ],
        ];
    }

    /**
     * Save an array of permission overrides for a user with security validations.
     *
     * @param  array<int, array{permission_id: int, effect: string|null, reason?: string|null}>  $overrides
     *
     * @throws ValidationException
     */
    public function saveOverrides(
        User $targetUser,
        array $overrides,
        User $actor,
        ?string $globalReason = null,
        ?Request $request = null,
    ): array {
        // Security checks:
        // 1. Prevent self-escalation
        if ($actor->id === $targetUser->id && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'user' => 'You cannot modify your own permission overrides.',
            ]);
        }

        // 2. Prevent non-super-admins from modifying a super-admin
        if ($targetUser->hasRole('super-admin') && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'user' => 'Only super-administrators can modify permissions for another super-administrator.',
            ]);
        }

        // 3. Prevent privilege escalation: Actor can only grant permissions they themselves possess
        if (! $actor->hasRole('super-admin')) {
            foreach ($overrides as $item) {
                if (($item['effect'] ?? null) === UserPermissionOverride::EFFECT_ALLOW) {
                    $perm = Permission::find($item['permission_id']);
                    if ($perm && ! $this->resolveEffectivePermission($actor, $perm->name)) {
                        throw ValidationException::withMessages([
                            'overrides' => "You cannot grant permission \"{$perm->name}\" because you do not possess it yourself.",
                        ]);
                    }
                }
            }
        }

        $applied = [
            'allowed' => 0,
            'denied' => 0,
            'reset' => 0,
        ];

        $oldOverrides = UserPermissionOverride::where('user_id', $targetUser->id)->get()->toArray();

        DB::transaction(function () use ($targetUser, $overrides, $actor, $globalReason, &$applied) {
            foreach ($overrides as $item) {
                $permissionId = (int) $item['permission_id'];
                $effect = $item['effect'] ?? null;
                $reason = $item['reason'] ?? $globalReason;

                if (! in_array($effect, [UserPermissionOverride::EFFECT_ALLOW, UserPermissionOverride::EFFECT_DENY], true)) {
                    // Reset to inherited
                    $deleted = UserPermissionOverride::where('user_id', $targetUser->id)
                        ->where('permission_id', $permissionId)
                        ->delete();
                    if ($deleted > 0) {
                        $applied['reset']++;
                    }
                } else {
                    $existing = UserPermissionOverride::where('user_id', $targetUser->id)
                        ->where('permission_id', $permissionId)
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'effect' => $effect,
                            'reason' => $reason,
                            'updated_by' => $actor->id,
                        ]);
                    } else {
                        UserPermissionOverride::create([
                            'user_id' => $targetUser->id,
                            'permission_id' => $permissionId,
                            'effect' => $effect,
                            'reason' => $reason,
                            'created_by' => $actor->id,
                            'updated_by' => $actor->id,
                        ]);
                    }

                    if ($effect === UserPermissionOverride::EFFECT_ALLOW) {
                        $applied['allowed']++;
                    } else {
                        $applied['denied']++;
                    }
                }
            }
        });

        // Invalidate cached overrides for this user
        $this->clearUserCache($targetUser);

        // Audit logging
        $newOverrides = UserPermissionOverride::where('user_id', $targetUser->id)->get()->toArray();
        if ($request) {
            $this->auditLog->record(
                'user_permissions_updated',
                $targetUser,
                ['overrides' => $oldOverrides],
                [
                    'overrides' => $newOverrides,
                    'summary' => $applied,
                    'reason' => $globalReason,
                ],
                $request
            );
        }

        return $applied;
    }

    /**
     * Reset all overrides for a user back to normal inherited role defaults.
     */
    public function resetOverrides(User $targetUser, User $actor, ?string $reason = null, ?Request $request = null): int
    {
        if ($targetUser->hasRole('super-admin') && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'user' => 'Only super-administrators can modify permissions for another super-administrator.',
            ]);
        }

        $oldOverrides = UserPermissionOverride::where('user_id', $targetUser->id)->get()->toArray();

        $count = UserPermissionOverride::where('user_id', $targetUser->id)->delete();

        $this->clearUserCache($targetUser);

        if ($request && $count > 0) {
            $this->auditLog->record(
                'user_permissions_reset',
                $targetUser,
                ['overrides' => $oldOverrides],
                ['reason' => $reason, 'count' => $count],
                $request
            );
        }

        return $count;
    }

    /**
     * Classify permission into group, module, title, and descriptive explanation.
     *
     * @return array{group: string, module: string, title: string, description: string}
     */
    public function classifyPermission(string $name): array
    {
        $parts = explode('.', $name, 2);
        $moduleKey = $parts[0];
        $actionKey = $parts[1] ?? 'action';

        $actionTitles = [
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'cancel' => 'Cancel',
            'start' => 'Start',
            'complete' => 'Complete',
            'order' => 'Order',
            'execute' => 'Execute',
            'manage' => 'Manage',
            'review' => 'Review',
            'verify' => 'Verify',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'release' => 'Release',
            'export' => 'Export',
            'print' => 'Print',
            'download' => 'Download',
            'post' => 'Post / Lock',
            'void' => 'Void',
            'sync' => 'Synchronize',
            'submit' => 'Submit',
            'reconcile' => 'Reconcile',
        ];

        $actionWord = $actionTitles[$actionKey] ?? Str::headline($actionKey);
        $moduleHeadline = Str::headline($moduleKey);

        $title = "{$actionWord} {$moduleHeadline}";

        // Group mapping
        $group = 'Administration & Security';

        if (Str::startsWith($moduleKey, ['clinical', 'consultation', 'diagnosis', 'treatment', 'vital', 'triage', 'care', 'discharge', 'maternity'])) {
            $group = 'Clinical & Consultations';
        } elseif (Str::startsWith($moduleKey, ['procedure', 'surgical'])) {
            $group = 'Procedures & Surgical';
        } elseif (Str::startsWith($moduleKey, 'laboratory')) {
            $group = 'Laboratory';
        } elseif (Str::startsWith($moduleKey, 'radiology')) {
            $group = 'Radiology';
        } elseif (Str::startsWith($moduleKey, ['pharmacy', 'prescription', 'medication'])) {
            $group = 'Pharmacy & Prescriptions';
        } elseif (Str::startsWith($moduleKey, ['admission', 'inpatient', 'patient-allergies'])) {
            $group = 'Inpatient & Nursing';
        } elseif (Str::startsWith($moduleKey, 'billing')) {
            $group = 'Billing & Finance';
        } elseif (Str::startsWith($moduleKey, 'nhif')) {
            $group = 'NHIF Integration';
        } elseif (Str::startsWith($moduleKey, ['procurement', 'stores'])) {
            $group = 'Procurement & Stores';
        } elseif (Str::startsWith($moduleKey, ['patient', 'reception', 'appointment', 'insurance'])) {
            $group = 'Patients & Reception';
        }

        $description = "Allows users to perform \"{$actionKey}\" operations on {$moduleHeadline}.";

        return [
            'group' => $group,
            'module' => $moduleHeadline,
            'title' => $title,
            'description' => $description,
        ];
    }
}
