<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(AuditLog::class, 'auditLog');
    }

    /**
     * Display a paginated, searchable, filterable list of audit log entries.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $module = $request->string('module')->trim()->toString();

        $logs = AuditLog::query()
            ->with('user:id,UserName,email')
            ->when($request->string('action')->toString(), fn ($query, $action) => $query->where('action', $action))
            ->when($module !== '', fn ($query) => $this->applyModuleFilter($query, $module))
            ->when($request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->date('date_from'), fn ($query, $date) => $query->where('created_at', '>=', $date->startOfDay()))
            ->when($request->date('date_to'), fn ($query, $date) => $query->where('created_at', '<=', $date->endOfDay()))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('action', 'like', "%{$search}%")
                        ->orWhere('auditable_id', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('email', 'like', "%{$search}%")
                                ->orWhere('UserName', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'module' => $log->moduleName(),
                'record_id' => $log->auditable_id,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->UserName,
                    'email' => $log->user->email,
                ] : null,
            ]);

        return Inertia::render('audit-logs/index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'action', 'module', 'user_id', 'date_from', 'date_to']),
            'filterOptions' => [
                'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
                'modules' => $this->moduleOptions(),
            ],
        ]);
    }

    /**
     * Display a single audit log entry with a readable change diff.
     */
    public function show(AuditLog $auditLog): Response
    {
        $auditLog->load('user:id,UserName,email');

        return Inertia::render('audit-logs/show', [
            'auditLog' => [
                'id' => $auditLog->id,
                'action' => $auditLog->action,
                'module' => $auditLog->moduleName(),
                'auditable_type' => $auditLog->auditable_type,
                'auditable_id' => $auditLog->auditable_id,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
                'url' => $auditLog->url,
                'created_at' => $auditLog->created_at,
                'user' => $auditLog->user ? [
                    'id' => $auditLog->user->id,
                    'name' => $auditLog->user->UserName,
                    'email' => $auditLog->user->email,
                ] : null,
            ],
        ]);
    }

    /**
     * Constrain the query to a chosen module. "Authentication" and "System"
     * are the pseudo-modules for events with no auditable model.
     */
    protected function applyModuleFilter($query, string $module): void
    {
        if (in_array($module, ['Authentication', 'System'], true)) {
            $query->whereNull('auditable_type');

            return;
        }

        $query->where('auditable_type', 'App\\Models\\'.$module);
    }

    /**
     * Build the distinct module options present in the audit table.
     *
     * Derived from stored data rather than hard-coded, so future modules appear
     * automatically without touching this controller.
     *
     * @return list<string>
     */
    protected function moduleOptions(): array
    {
        $modules = AuditLog::query()
            ->whereNotNull('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->map(fn (string $type) => class_basename($type));

        $hasSystemEvents = AuditLog::query()->whereNull('auditable_type')->exists();

        if ($hasSystemEvents) {
            $modules->push('Authentication');
        }

        return $modules->unique()->sort()->values()->all();
    }
}
