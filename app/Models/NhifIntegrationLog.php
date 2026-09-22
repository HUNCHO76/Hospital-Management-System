<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of all outbound NHIF API calls (Phase 8, Step 64).
 */
class NhifIntegrationLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'operation',
        'endpoint',
        'http_method',
        'http_status',
        'success',
        'error_code',
        'error_message',
        'raw_response',
        'correlation_id',
        'facility_id',
        'patient_id',
        'user_id',
        'requested_at',
        'responded_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'facility_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
