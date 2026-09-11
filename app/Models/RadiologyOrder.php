<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\RadiologyOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A radiology order — a clinical request, placed directly against a
 * ClinicalEncounter, for one or more procedures from the Step 36 catalog
 * (Phase 5, Step 36). Radiology is its own sibling branch off the
 * encounter, alongside Prescription/Laboratory/Diagnosis/Clinical Notes —
 * never nested under a CarePlanItem (Part 5's explicit correction of the
 * same anti-pattern Laboratory had until its own Step 30). Mirrors
 * LaboratoryOrder's shape deliberately, but only as far as this step's own
 * scope goes: no sample/scheduling/examination/result rollup exists here
 * yet — those belong to Steps 38-44.
 */
class RadiologyOrder extends Model
{
    /** @use HasFactory<RadiologyOrderFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating order numbers.
     */
    public const NUMBER_PREFIX = 'RAD';

    /**
     * Priority values recognized for a radiology order (Part 12 — only the
     * levels an OPD workflow actually distinguishes, not a medical decision
     * engine).
     */
    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_STAT = 'stat';

    /**
     * @var list<string>
     */
    public const PRIORITIES = [
        self::PRIORITY_ROUTINE,
        self::PRIORITY_URGENT,
        self::PRIORITY_STAT,
    ];

    /**
     * The foundation status set only (Part 11 — explicitly do not
     * forward-declare Steps 37-47's scheduling/examination/reporting/
     * release states "unless the existing architecture already requires
     * them," which it does not yet). DRAFT is declared for completeness but
     * never persisted — an order is created directly as ORDERED, mirroring
     * LaboratoryOrder's own DRAFT precedent.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    /**
     * A derived rollup, not a directly user-driven transition (Phase 5,
     * Step 39, Part 21) — set by RadiologySchedulingService once every
     * non-cancelled item has an active appointment, and reverted back to
     * ORDERED the moment that stops being true (an item's appointment is
     * cancelled, or a new unscheduled item is added). Mirrors Laboratory's
     * own derived order-status-rollup precedent
     * (`laboratory_order_status_changed`).
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ORDERED,
        self::STATUS_SCHEDULED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * RadiologyOrderService/RadiologySchedulingService — never trust a
     * status change supplied directly by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [],
        self::STATUS_ORDERED => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_ORDERED, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_encounter_id',
        'order_number',
        'priority',
        'clinical_indication',
        'notes',
        'status',
        'ordered_by',
        'ordered_at',
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
            'ordered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (empty($order->order_number)) {
                $order->order_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'order_number');
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
     * The clinical encounter this order was placed against.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The procedures requested on this order (Part 9 — one order, many
     * procedures).
     */
    public function items(): HasMany
    {
        return $this->hasMany(RadiologyOrderItem::class)->orderBy('id');
    }

    /**
     * The user who placed this order.
     */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
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
}
