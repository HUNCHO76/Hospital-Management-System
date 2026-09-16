<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row in the stock movement ledger (Phase 6, Step 49) — see
 * the create_stock_transactions_table migration's own docblock for the full
 * reasoning. Never updated after creation: StockService only ever inserts new
 * rows, even to correct a mistake (spec §11).
 */
class StockTransaction extends Model
{
    /** @use HasFactory<StockTransactionFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * No `updated_at` timestamp — this table is structurally append-only.
     */
    public const UPDATED_AT = null;

    /**
     * The prefix used when generating transaction numbers.
     */
    public const NUMBER_PREFIX = 'STK';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_DISPENSE = 'dispense';

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_QUARANTINE = 'quarantine';

    public const TYPE_RELEASE = 'release';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_RECEIPT,
        self::TYPE_DISPENSE,
        self::TYPE_ADJUSTMENT_IN,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_EXPIRED,
        self::TYPE_DAMAGED,
        self::TYPE_QUARANTINE,
        self::TYPE_RELEASE,
    ];

    /**
     * Transaction types that reduce a batch's quantity — used only to assert
     * balance_after = balance_before - quantity is internally consistent when
     * a row is written; never re-derived from this list afterward.
     *
     * @var list<string>
     */
    public const OUTBOUND_TYPES = [
        self::TYPE_DISPENSE,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_EXPIRED,
        self::TYPE_DAMAGED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'transaction_number',
        'stock_item_id',
        'stock_batch_id',
        'transaction_type',
        'quantity',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'reason',
        'performed_by',
        'occurred_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'transaction_number');
            }
        });
    }

    /**
     * The stock item this movement was recorded against.
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * The specific batch this movement affected.
     */
    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    /**
     * The user who performed (or recorded) this movement.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
