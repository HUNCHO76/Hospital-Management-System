<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A patient's payment against one invoice's own outstanding patient
 * responsibility (Phase 7, Step 57) — see the create_payments_table
 * migration's own docblock for the full reasoning behind its shape.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'PAY';

    public const RECEIPT_PREFIX = 'RCT';

    /**
     * POSTED: a real, immutable financial fact the moment it is created —
     * there is no meaningful "draft payment" state (money is already in
     * hand by the time anyone records it). FAILED: a controlled correction
     * after the fact (e.g. a bounced bank transfer, a reference later found
     * invalid) — the row is never edited or deleted, only its status
     * changes, with a mandatory reason. Genuine reversal/refund is Step
     * 58's job.
     */
    public const STATUS_POSTED = 'posted';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_FAILED,
    ];

    /**
     * The single source of truth for valid status transitions. POSTED is
     * this model's only entry point (never created as anything else); the
     * only transition available in this step is the one controlled
     * correction, POSTED -> FAILED.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_POSTED => [self::STATUS_FAILED],
        self::STATUS_FAILED => [],
    ];

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_MOBILE_MONEY = 'mobile_money';

    public const METHOD_OTHER = 'other';

    /**
     * A plain validated list (spec §57.5), not a manageable table — mirrors
     * Service::CATEGORIES' own "standard vocabulary, not admin-
     * reconfigurable" precedent. No per-provider entries (M-Pesa/Tigo Pesa/
     * Airtel Money/HaloPesa): no existing provider integration exists to
     * reuse, and building one is explicitly out of this step's scope (spec
     * §57.5's own "do not create unnecessary provider-specific
     * integrations").
     *
     * @var list<string>
     */
    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_CARD,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_MOBILE_MONEY,
        self::METHOD_OTHER,
    ];

    /**
     * Methods that require a reference number (spec §57.5) — cash is the
     * one method with nothing to reference.
     *
     * @var list<string>
     */
    public const METHODS_REQUIRING_REFERENCE = [
        self::METHOD_CARD,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_MOBILE_MONEY,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'payment_number',
        'receipt_number',
        'hospital_id',
        'patient_id',
        'patient_account_id',
        'invoice_id',
        'payment_date',
        'amount',
        'currency',
        'payment_method',
        'reference_number',
        'status',
        'notes',
        'received_by',
        'posted_at',
        'failed_at',
        'failed_by',
        'failure_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            if (empty($payment->payment_number)) {
                $payment->payment_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'payment_number');
            }

            if (empty($payment->receipt_number)) {
                $payment->receipt_number = static::generateBusinessNumber(self::RECEIPT_PREFIX, 'receipt_number');
            }
        });
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
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

    /**
     * The single invoice this payment was recorded against (spec §57.10's
     * own permitted "one invoice per payment" simplification for this
     * step — see this model's own class docblock).
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * This payment's own allocation history — exactly one row in this
     * step's own scope, structured to allow more later without a schema
     * change (see create_payment_allocations_table's own docblock).
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The single reversal of this payment, if one has been created (Phase 7,
     * Step 58) — UNIQUE(original_payment_id) means at most one can ever exist.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(PaymentReversal::class, 'original_payment_id');
    }

    /**
     * Every refund issued against this payment (Phase 7, Step 58) — multiple
     * are allowed; their sum must not exceed payment.amount.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function failedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'failed_by');
    }
}
