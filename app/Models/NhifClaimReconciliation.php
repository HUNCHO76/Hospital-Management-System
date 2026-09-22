<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NHIF claim reconciliation event — records what NHIF approved/rejected (Phase 8, Step 74).
 */
class NhifClaimReconciliation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISPUTED = 'disputed';

    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_id',
        'nhif_claim_submission_attempt_id',
        'hospital_id',
        'source',
        'response_reference',
        'nhif_reference',
        'nhif_raw_status',
        'nhif_response_code',
        'hms_status',
        'amounts_confirmed',
        'submitted_amount',
        'nhif_approved_amount',
        'nhif_rejected_amount',
        'nhif_adjusted_amount',
        'patient_responsibility_expected',
        'patient_responsibility_nhif',
        'variance_amount',
        'variance_category',
        'reconciliation_status',
        'raw_response',
        'normalized_response',
        'received_at',
        'reconciled_by',
        'reconciled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amounts_confirmed' => 'boolean',
            'submitted_amount' => 'decimal:2',
            'nhif_approved_amount' => 'decimal:2',
            'nhif_rejected_amount' => 'decimal:2',
            'nhif_adjusted_amount' => 'decimal:2',
            'patient_responsibility_expected' => 'decimal:2',
            'patient_responsibility_nhif' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'received_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'raw_response' => 'array',
            'normalized_response' => 'array',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }

    public function submissionAttempt(): BelongsTo
    {
        return $this->belongsTo(NhifClaimSubmissionAttempt::class, 'nhif_claim_submission_attempt_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function itemReconciliations(): HasMany
    {
        return $this->hasMany(NhifClaimItemReconciliation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(NhifClaimDiscrepancy::class);
    }
}
