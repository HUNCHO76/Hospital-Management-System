<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserPermission\UpdateUserPermissionsRequest;
use App\Models\User;
use App\Services\PermissionResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserPermissionController extends Controller
{
    public function __construct(
        protected PermissionResolutionService $permissionService,
    ) {}

    /**
     * Show the user-specific permission override management interface.
     */
    public function edit(Request $request, User $user): Response
    {
        $this->authorizeManage($request->user(), $user);

        $matrix = $this->permissionService->getPermissionMatrix($user);

        return Inertia::render('users/permissions', [
            'matrix' => $matrix,
            'canManage' => true,
        ]);
    }

    /**
     * Update individual permission overrides for the specified user.
     */
    public function update(UpdateUserPermissionsRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $summary = $this->permissionService->saveOverrides(
            $user,
            $validated['overrides'],
            $request->user(),
            $validated['reason'] ?? null,
            $request,
        );

        $msg = "Permissions for {$user->UserName} updated successfully ({$summary['allowed']} allowed, {$summary['denied']} denied, {$summary['reset']} reset to inherited).";

        return back()->with('success', $msg);
    }

    /**
     * Reset all overrides for the user back to inherited role defaults.
     */
    public function reset(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request->user(), $user);

        $reason = $request->string('reason')->trim()->toString() ?: 'Administrative reset to inherited role permissions';

        $count = $this->permissionService->resetOverrides($user, $request->user(), $reason, $request);

        return back()->with('success', "All {$count} permission overrides for {$user->UserName} have been reset to inherited role defaults.");
    }

    /**
     * Authorize that the actor has permission to view or manage the target user's permissions.
     *
     * @throws ValidationException
     */
    protected function authorizeManage(User $actor, User $targetUser): void
    {
        if ($actor->hasRole('super-admin')) {
            return;
        }

        // Prevent self-escalation
        if ($actor->id === $targetUser->id) {
            abort(403, 'You cannot modify or view your own permission management settings.');
        }

        // Prevent non-super-admins from modifying a super-admin
        if ($targetUser->hasRole('super-admin')) {
            abort(403, 'Only super-administrators can view or modify permissions for another super-administrator.');
        }

        // Must hold permission management authorization
        if (! ($actor->can('permissions.assign') || $actor->can('users.update') || $actor->can('staff.update') || $actor->hasAnyRole(['hospital-admin', 'Manager', 'manager']))) {
            abort(403, 'You do not have permission to manage individual user permissions.');
        }
    }
}
