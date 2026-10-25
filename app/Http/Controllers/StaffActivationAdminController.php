<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use App\Services\StaffAccountActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffActivationAdminController extends Controller
{
    public function __construct(
        protected StaffAccountActivationService $activationService,
    ) {}

    /**
     * Generate or regenerate an activation link for a staff member.
     */
    public function store(Request $request, Staff $staff): RedirectResponse
    {
        $actor = $request->user();
        if (! ($actor->can('staff.update') || $actor->can('users.update') || $actor->hasAnyRole(['hospital-admin', 'Manager', 'super-admin']))) {
            abort(403, 'Unauthorized to manage staff activations.');
        }

        $request->validate([
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($staff->user_id),
            ],
        ]);

        $verifiedEmail = $request->filled('email') ? $request->string('email')->toString() : null;

        // Ensure staff has a user account
        $user = $staff->user;
        if (! $user) {
            throw ValidationException::withMessages([
                'user' => 'This staff member does not have an attached user account yet.',
            ]);
        }

        $invitation = $this->activationService->createActivationInvitation(
            $user,
            $verifiedEmail,
            $actor,
            $request,
        );

        return back()->with('success', "Activation link generated for {$staff->first_name} {$staff->last_name}: {$invitation['activationUrl']}");
    }
}
