<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\PaymentReversalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable, full reversal of a posted payment (Phase 7, Step 58) — the
 * auditable correction event that negates the original payment's balance
 * contribution. The original Payment row is never modified; this row is the
 * financial fact "this payment was reversed on this date, by this person,
 * for this reason."
 *
 * No status field: a reversal has no lifecycle — it is posted the moment it
 * is created, mirrors StockService::receive()'s own precedent (a real,
 * immediate event, no meaningful draft state).
 */
class PaymentReversal extends Model
{
    /** @use HasFactory<PaymentReversalFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'REV';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reversal_number',
        'original_payment_id',
        'invoice_id',
        'hospital_id',
        'patient_id',
        'patient_account_id',
        'amount',
        'currency',
        'reason',
        'reversed_by',
        'reversed_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reversal): void {
            if (empty($reversal->reversal_number)) {
                $reversal->reversal_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'reversal_number');
            }
        });
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'original_payment_id');
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

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
