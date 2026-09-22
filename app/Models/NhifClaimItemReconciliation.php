<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-line-item reconciliation within a claim reconciliation event (Phase 8, Step 74).
 */
class NhifClaimItemReconciliation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_reconciliation_id',
        'nhif_claim_item_id',
        'nhif_utilization_id',
        'module',
        'nhif_item_code',
        'amounts_confirmed',
        'submitted_amount',
        'nhif_tariff_amount',
        'approved_amount',
        'rejected_amount',
        'adjusted_amount',
        'nhif_item_status',
        'nhif_raw_item_status',
        'rejection_code',
        'rejection_message',
        'variance_amount',
        'variance_category',
    ];

    protected function casts(): array
    {
        return [
            'amounts_confirmed' => 'boolean',
            'submitted_amount' => 'decimal:2',
            'nhif_tariff_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'rejected_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(NhifClaimReconciliation::class, 'nhif_claim_reconciliation_id');
    }

    public function claimItem(): BelongsTo
    {
        return $this->belongsTo(NhifClaimItem::class, 'nhif_claim_item_id');
    }

    public function utilization(): BelongsTo
    {
        return $this->belongsTo(NhifUtilization::class, 'nhif_utilization_id');
    }
}
