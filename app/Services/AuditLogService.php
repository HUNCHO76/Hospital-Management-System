<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    /**
     * Substrings that mark a field as sensitive. Any key containing one of
     * these (case-insensitively) is stripped from audit values before they are
     * persisted. Keeping this list centralized means every audited model is
     * protected without each caller having to remember to exclude secrets.
     *
     * @var list<string>
     */
    public const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'secret',
        'client_secret',
        'api_key',
        'api_secret',
        'access_token',
        'refresh_token',
        'credential',
    ];

    /**
     * Record a security/data-change audit log entry.
     *
     * Sensitive fields are stripped from both value sets before storage, so a
     * caller can safely pass a full attribute array without leaking secrets.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(string $action, ?Model $auditable, ?array $oldValues, ?array $newValues, Request $request): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);
    }

    /**
     * Remove any sensitive fields from a set of audit values.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return collect($values)
            ->reject(fn ($value, $key) => $this->isSensitive((string) $key))
            ->all();
    }

    /**
     * Determine whether a field name should never be recorded.
     */
    protected function isSensitive(string $key): bool
    {
        return Str::contains(Str::lower($key), self::SENSITIVE_KEYS);
    }
}
