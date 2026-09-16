<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\PrescriptionOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A prescription order — a clinician's medication decision placed directly
 * against a ClinicalEncounter (Phase 6, Step 46). Prescription is its own
 * sibling branch off the encounter, alongside Care Plan/Laboratory/Radiology
 * — never nested under a CarePlanItem, mirroring RadiologyOrder's own
 * promotion out of CarePlanItem nesting (Phase 5, Step 36) exactly. The
 * pre-existing `Prescription` model (nested 1:1 on a medication-type
 * CarePlanItem) is deliberately left untouched — see docs/pharmacy.md.
 */
class PrescriptionOrder extends Model
{
    /** @use HasFactory<PrescriptionOrderFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating prescription numbers.
     */
    public const NUMBER_PREFIX = 'RX';

    /**
     * DRAFT is declared for completeness but never persisted — an order is
     * created directly as ACTIVE, mirroring RadiologyOrder's own
     * STATUS_ORDERED precedent (item selection/review happens client-side
     * before one atomic submission, not as a separately-saved draft row).
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * The clean, immediately-usable state Step 47 (Verification) will
     * consume — this step deliberately does not implement any pharmacy-side
     * processing status beyond this.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * PrescriptionOrderService — never trust a status change supplied
     * directly by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_encounter_id',
        'prescription_number',
        'status',
        'clinical_notes',
        'prescriber_id',
        'prescribed_at',
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
            'prescribed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (empty($order->prescription_number)) {
                $order->prescription_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'prescription_number');
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
     * The clinical encounter this prescription was written against.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The medications prescribed on this order (one prescription, many
     * medications).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionOrderItem::class)->orderBy('id');
    }

    /**
     * The clinician who wrote this prescription.
     */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }

    /**
     * The user who last updated this order, if any.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who cancelled this order, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The pharmacy-owned verification review for this order (Phase 6, Step
     * 47) — a genuinely separate entity, never a status column on this row
     * itself (see PrescriptionVerification's own docblock for why).
     */
    public function verification(): HasOne
    {
        return $this->hasOne(PrescriptionVerification::class);
    }

    /**
     * Every pharmacy dispensing event against this order (Phase 6, Step 48),
     * oldest first — a prescription may be fulfilled across several separate
     * events (partial dispensing), so this is deliberately 1:many, never a
     * status column on this row itself (see Dispensing's own docblock).
     */
    public function dispensings(): HasMany
    {
        return $this->hasMany(Dispensing::class)->orderBy('started_at');
    }
}
