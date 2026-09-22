<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Discrepancy surfaced during claim reconciliation, requiring review/resolution (Phase 8, Step 74).
 */
class NhifClaimDiscrepancy extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ACCEPTED = 'accepted';

    public const CATEGORY_AMOUNT_VARIANCE = 'amount_variance';

    public const CATEGORY_REJECTED_ITEM = 'rejected_item';

    public const CATEGORY_MISSING_ITEM = 'missing_item';

    public const CATEGORY_TARIFF_MISMATCH = 'tariff_mismatch';

    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_id',
        'nhif_claim_reconciliation_id',
        'nhif_claim_item_id',
        'hospital_id',
        'category',
        'description',
        'submitted_amount',
        'nhif_amount',
        'variance_amount',
        'status',
        'opened_at',
        'reviewed_by',
        'reviewed_at',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_amount' => 'decimal:2',
            'nhif_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(NhifClaimReconciliation::class, 'nhif_claim_reconciliation_id');
    }

    public function claimItem(): BelongsTo
    {
        return $this->belongsTo(NhifClaimItem::class, 'nhif_claim_item_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_ACCEPTED], true);
    }
}
