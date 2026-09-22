<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to submit an NHIF claim to the OCS endpoint (Phase 8, Step 73).
 */
class NhifClaimSubmissionAttempt extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_id',
        'hospital_id',
        'submission_key',
        'attempt_number',
        'submission_status',
        'nhif_response_status',
        'nhif_claim_reference',
        'http_status',
        'error_message',
        'raw_response',
        'submitted_at',
        'resolved_at',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function wasAccepted(): bool
    {
        return $this->submission_status === self::STATUS_ACCEPTED;
    }
}
