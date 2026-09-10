<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * The audit_logs table only tracks a created_at column.
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * The user who performed the audited action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The model that was affected by the audited action.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A human-readable module label for the affected record.
     *
     * Data-change events are grouped by their auditable model; events without
     * an auditable (authentication, system jobs) are grouped by their action.
     */
    public function moduleName(): string
    {
        if ($this->auditable_type !== null) {
            return class_basename($this->auditable_type);
        }

        return str_starts_with($this->action, 'login') || $this->action === 'logout'
            ? 'Authentication'
            : 'System';
    }
}
