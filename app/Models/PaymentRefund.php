<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partial or full refund against a posted payment (Phase 7, Step 58) —
 * records money returned to the patient. Multiple refunds per payment are
 * allowed; their sum must never exceed the original payment's amount
 * (PaymentRefundService::create() enforces this under a row lock). The
 * original Payment row is never modified.
 *
 * `refund_method`/`refund_reference` mirror Payment's own method/reference
 * vocabulary — a refund is typically returned via the same channel as the
 * original payment.
 */
class PaymentRefund extends Model
{
    /** @use HasFactory<PaymentRefundFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'REF';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'refund_number',
        'payment_id',
        'invoice_id',
        'hospital_id',
        'patient_id',
        'patient_account_id',
        'amount',
        'currency',
        'refund_method',
        'refund_reference',
        'reason',
        'processed_by',
        'processed_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $refund): void {
            if (empty($refund->refund_number)) {
                $refund->refund_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'refund_number');
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
