<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\BillingAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A financial adjustment directly against an invoice's outstanding balance
 * (Phase 7, Step 58) — neither a payment nor a correction of one, but a
 * deliberate approved change to how much the patient owes.
 *
 * `direction` is always explicit ('credit' reduces the outstanding; 'debit'
 * increases it) — never inferred from `type` at query time, so the
 * calculateOutstanding() query can sum credits and debits in one pass without
 * a case expression.
 *
 * An adjustment is immediately final — no status field, mirrors
 * PaymentReversal's own "no draft state" precedent.
 */
class BillingAdjustment extends Model
{
    /** @use HasFactory<BillingAdjustmentFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'ADJ';

    public const TYPE_CREDIT_ADJUSTMENT = 'credit_adjustment';

    public const TYPE_DEBIT_ADJUSTMENT = 'debit_adjustment';

    public const TYPE_APPROVED_DISCOUNT = 'approved_discount';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_BILLING_CORRECTION = 'billing_correction';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_CREDIT_ADJUSTMENT,
        self::TYPE_DEBIT_ADJUSTMENT,
        self::TYPE_APPROVED_DISCOUNT,
        self::TYPE_WRITE_OFF,
        self::TYPE_BILLING_CORRECTION,
    ];

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    /**
     * @var list<string>
     */
    public const DIRECTIONS = [
        self::DIRECTION_CREDIT,
        self::DIRECTION_DEBIT,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'adjustment_number',
        'invoice_id',
        'hospital_id',
        'patient_id',
        'patient_account_id',
        'type',
        'direction',
        'amount',
        'currency',
        'reason',
        'adjusted_by',
        'adjusted_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'adjusted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            if (empty($adjustment->adjustment_number)) {
                $adjustment->adjustment_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'adjustment_number');
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function patientAccount(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class);
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
