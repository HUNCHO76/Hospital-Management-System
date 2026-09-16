<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single billable line item against a PatientAccount (Phase 7, Step 52,
 * extended in Step 54 for automated charge capture) — see the
 * create_charges_table / add_charge_capture_fields_to_charges_table
 * migrations' own docblocks for the full reasoning behind its shape
 * (nullable `service_id`, snapshot fields, shared cancellation columns, the
 * `(source_type, source_id)` uniqueness that is Step 54's own duplicate-
 * capture protection).
 */
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating charge numbers.
     */
    public const NUMBER_PREFIX = 'CHG';

    /**
     * PENDING: just created, not yet a financial fact (spec §14/§15 — this
     * step's own foundation only ever reaches PENDING/POSTED itself; nothing
     * here auto-triggers from a clinical event yet, that is Step 54's job).
     * POSTED: a real, immutable financial fact — counted in the account's
     * balance. VOIDED: a PENDING charge invalidated before ever becoming a
     * financial fact (e.g. a data-entry mistake caught immediately) — never
     * counted, never a "real" correction. CANCELLED: a POSTED charge
     * reversed after the fact — the row itself is never edited or deleted
     * (spec §25's financial immutability), only its status changes, with a
     * mandatory reason; a full Adjustment/credit-note entity belongs to a
     * later step (spec §24), this is the minimal, controlled correction the
     * foundation itself needs to not "prevent" that later work.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * ChargeService — never trust a status change supplied directly by the
     * frontend (spec §14/§40).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_POSTED, self::STATUS_VOIDED],
        self::STATUS_POSTED => [self::STATUS_CANCELLED],
        self::STATUS_VOIDED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'charge_number',
        'hospital_id',
        'patient_account_id',
        'patient_id',
        'encounter_id',
        'invoice_id',
        'service_id',
        'source_type',
        'source_id',
        'service_code_snapshot',
        'description',
        'notes',
        'quantity',
        'unit_price',
        'gross_amount',
        'discount_amount',
        'net_amount',
        'currency',
        'status',
        'charged_at',
        'posted_at',
        'created_by',
        'updated_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
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
            'unit_price' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'charged_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $charge): void {
            if (empty($charge->charge_number)) {
                $charge->charge_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'charge_number');
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

    /**
     * Whether this charge currently counts toward the account's balance.
     */
    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * The account this charge belongs to.
     */
    public function patientAccount(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class);
    }

    /**
     * The catalog Service this charge was priced against, if any (Phase 7,
     * Step 54) — always null for a pharmacy-sourced charge (Medication is
     * deliberately never mapped into the Service catalog, see
     * ChargeCaptureService's own docblock).
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The invoice this charge is currently included in, if any (Phase 7,
     * Step 55) — a charge is "invoiced" if and only if this is not null; no
     * separate `is_billed`/`invoice_status` field exists anywhere (spec
     * §31's own "choose one authoritative mechanism").
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The hospital this charge was raised at.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The patient this charge was raised against.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The clinical encounter this charge originated from, if any.
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    /**
     * The user who recorded this charge.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this charge, if any.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who voided or cancelled this charge, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * This charge's own insurance responsibility split, if it has ever been
     * calculated (Phase 7, Step 56) — null until
     * InsuranceBillingService::applyToInvoice() has run for the invoice this
     * charge belongs to.
     */
    public function insuranceAllocation(): HasOne
    {
        return $this->hasOne(ChargeInsuranceAllocation::class);
    }
}
