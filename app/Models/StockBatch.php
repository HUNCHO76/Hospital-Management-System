<?php

namespace App\Models;

use Database\Factories\StockBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single received lot of a StockItem (Phase 6, Step 49) — see the
 * create_stock_batches_table migration's own docblock for the full reasoning
 * behind its shape (no reserved/available split, why expiry_date is
 * required, why `status` includes DEPLETED).
 */
class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use HasFactory;

    /**
     * ACTIVE: normal, counts toward availability when not expired.
     * QUARANTINED: held pending investigation — never dispensable, reversible
     * via StockService::release(). EXPIRED/DAMAGED: the batch's remaining
     * quantity was fully written off for that reason (StockService::adjust())
     * — terminal, quantity is always 0 by the time this status is set.
     * DEPLETED: quantity reached 0 through ordinary dispensing/adjustment
     * (not itself an expiry/damage write-off) — always set atomically by
     * StockService within the same transaction that zeroed the quantity, so
     * it can never drift out of sync with the actual balance.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_DEPLETED = 'depleted';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_QUARANTINED,
        self::STATUS_EXPIRED,
        self::STATUS_DAMAGED,
        self::STATUS_DEPLETED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'stock_item_id',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'quantity',
        'unit_cost',
        'supplier_name',
        'status',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * Whether this batch's own expiry date has already passed — computed
     * live, never read from `status`, so it can never be bypassed by a
     * client trying to set status directly (spec §16/§40's "never rely on the
     * UI" / "cannot bypass via status manipulation").
     */
    public function isExpired(): bool
    {
        return $this->expiry_date->isPast();
    }

    /**
     * Whether this batch may currently be allocated to a dispensing request —
     * the single authoritative check StockService::allocateFefo() relies on.
     */
    public function isDispensable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired() && $this->quantity > 0;
    }

    /**
     * The stock item (medication-at-hospital) this batch belongs to.
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * Every ledger movement recorded against this specific batch, oldest
     * first.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class)->orderBy('occurred_at');
    }

    /**
     * The user who first received this batch.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
