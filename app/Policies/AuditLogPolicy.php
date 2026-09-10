<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Determine whether the user can view the audit log list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('audit-logs.view');
    }

    /**
     * Determine whether the user can view a single audit log entry.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit-logs.view');
    }

    /**
     * Audit logs are immutable historical evidence. They may never be created,
     * edited, or deleted through the application. These abilities are declared
     * explicitly so the intent is unmistakable; no route exposes them.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
