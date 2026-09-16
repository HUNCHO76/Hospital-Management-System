<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use App\Models\Concerns\HasStatus;
use Database\Factories\PatientAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient's financial account at one hospital (Phase 7, Step 52) — see the
 * create_patient_accounts_table migration's own docblock for the full
 * reasoning behind its hospital-scoped, one-per-patient shape.
 */
class PatientAccount extends Model
{
    /** @use HasFactory<PatientAccountFactory> */
    use GeneratesBusinessNumber, HasFactory, HasStatus;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_CLOSED,
    ];

    /**
     * The prefix used when generating account numbers.
     */
    public const NUMBER_PREFIX = 'ACC';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'hospital_id',
        'patient_id',
        'account_number',
        'status',
        'currency',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if (empty($account->account_number)) {
                $account->account_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'account_number');
            }
        });
    }

    /**
     * The hospital this account belongs to.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The patient this account belongs to.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Every charge ever posted (or pending/voided/cancelled) against this
     * account, oldest first — the account's own financial timeline (spec
     * §37).
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class)->orderBy('charged_at');
    }

    /**
     * Every invoice ever raised against this account (Phase 7, Step 57 —
     * needed by PaymentService::outstandingBalanceForAccount()).
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Every payment ever recorded against this account, oldest first (Phase
     * 7, Step 57) — extends the same financial timeline `charges()` already
     * surfaces.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('payment_date');
    }

    /**
     * The user who opened this account (its first charge's actor).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
