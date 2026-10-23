<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationService;
use App\Support\Auth\RoleHomeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Ignore any remembered URL: it may be a page this user has no access to.
        $request->session()->forget('url.intended');

        return redirect(RoleHomeResolver::for($request->user()));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, AuthenticationService $authenticationService): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        if ($user) {
            $authenticationService->recordLogout($user, $request);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->boolean('inactivity') || $request->query('reason') === 'inactivity') {
            return redirect()->route('login')->with('status', 'You have been logged out after 5 minutes of inactivity for security.');
        }

        return redirect()->route('login');
    }
}
