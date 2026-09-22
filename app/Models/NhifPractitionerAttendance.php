<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One NHIF practitioner attendance (login or logout) record (Phase 8, Step 67).
 * Append-only; the most recent successful login determines whether the
 * practitioner is currently "logged in" to NHIF.
 */
class NhifPractitionerAttendance extends Model
{
    public const OPERATION_LOGIN = 'login';

    public const OPERATION_LOGOUT = 'logout';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'practitioner_id',
        'operation',
        'status',
        'request_data',
        'response_data',
        'recorded_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'response_data' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
