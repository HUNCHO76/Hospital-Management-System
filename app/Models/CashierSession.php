<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cashier's working session at a hospital (Phase 7, Step 59) — opened
 * with an opening balance and closed once reconciliation is complete. The
 * session brackets a time window; all payment/refund/reversal activity
 * during that window by this cashier at this hospital is attributed to it
 * via CashierSessionService::getCollectionSummary().
 */
class CashierSession extends Model
{
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'CSN';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'session_number',
        'hospital_id',
        'cashier_id',
        'status',
        'opening_balance',
        'expected_closing_balance',
        'actual_closing_balance',
        'variance',
        'variance_reason',
        'opened_at',
        'closed_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2',
            'actual_closing_balance' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if (empty($session->session_number)) {
                $session->session_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'session_number');
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
