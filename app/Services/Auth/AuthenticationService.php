<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuthenticationService
{
    /**
     * Record a successful authentication.
     */
    public function recordLoginSuccess(User $user, Request $request): void
    {
        $this->record('login_success', $user, $request);
    }

    /**
     * Record a failed authentication attempt.
     *
     * The user may be null when the submitted email does not match any account.
     */
    public function recordLoginFailed(Request $request, ?User $user): void
    {
        $this->record('login_failed', $user, $request);
    }

    /**
     * Record an authentication attempt rejected because the account is not active.
     */
    public function recordLoginBlocked(User $user, Request $request): void
    {
        $this->record('login_blocked', $user, $request);
    }

    /**
     * Record a logout event.
     */
    public function recordLogout(User $user, Request $request): void
    {
        $this->record('logout', $user, $request);
    }

    /**
     * Write a security audit log entry.
     */
    protected function record(string $action, ?User $user, Request $request): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);
    }
}
