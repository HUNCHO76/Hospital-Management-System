<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffAccountActivation;
use App\Services\StaffAccountActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class StaffAccountActivationController extends Controller
{
    public function __construct(
        protected StaffAccountActivationService $activationService,
    ) {}

    /**
     * Show the account activation form.
     */
    public function create(string $token): Response
    {
        $activation = StaffAccountActivation::findValidByPlainToken($token);

        if (! $activation) {
            return Inertia::render('auth/activate-account', [
                'valid' => false,
                'token' => $token,
                'user' => null,
            ]);
        }

        return Inertia::render('auth/activate-account', [
            'valid' => true,
            'token' => $token,
            'user' => [
                'name' => $activation->user->UserName,
                'email' => $activation->user->email,
                'department' => $activation->staff?->department?->name,
                'job_title' => $activation->staff?->job_title,
            ],
        ]);
    }

    /**
     * Complete the activation by setting the initial account password.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->activationService->completeActivation(
            $token,
            $request->string('password')->toString(),
            $request,
        );

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', 'Your staff account has been successfully activated.');
    }
}
