<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\LaboratoryOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A laboratory order — a clinical request, placed directly against a
 * ClinicalEncounter, for one or more tests from the Step 29 catalog
 * (Phase 4, Step 30). Laboratory is its own sibling branch off the
 * encounter, alongside Diagnosis/Treatment/Clinical Notes — never nested
 * under a CarePlanItem the way Step 28 originally modeled it ("do NOT make
 * Prescription -> Laboratory").
 */
class LaboratoryOrder extends Model
{
    /** @use HasFactory<LaboratoryOrderFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating order numbers.
     */
    public const NUMBER_PREFIX = 'LAB';

    /**
     * Priority values recognized for a laboratory order (spec §12 — only
     * the levels an OPD workflow actually distinguishes, not a medical
     * decision engine). The catalog's own default_priority (Step 29) is
     * only ever a starting suggestion — the order always preserves the
     * clinician's actual requested priority.
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
     * The full laboratory workflow state model (spec §10). Only ORDERED and
     * CANCELLED are reachable in this step. DRAFT is declared for
     * completeness but never persisted — nothing in the order-creation
     * workflow needs a saved multi-session draft, so an order is created
     * directly as ORDERED. RECEIVED onward are reserved for Steps 31-34.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SAMPLE_PENDING = 'sample_pending';

    public const STATUS_SAMPLE_COLLECTED = 'sample_collected';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESULT_PENDING = 'result_pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ORDERED,
        self::STATUS_SAMPLE_PENDING,
        self::STATUS_SAMPLE_COLLECTED,
        self::STATUS_RECEIVED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESULT_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * LaboratoryOrderService/LaboratorySampleService/
     * LaboratoryProcessingService — never trust a status change supplied
     * directly by the frontend. This is an aggregate rollup of the order's
     * own items' own state (see recomputeStatusFromItems() — that recompute
     * deliberately bypasses this table when a rejection reopens collection
     * or processing, since the aggregate can legitimately move backward,
     * which is not a "transition" in the single-direction sense this table
     * otherwise models). RESULT_PENDING is this step's own boundary —
     * "ready for result entry" — COMPLETED is reserved for a future step.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [],
        self::STATUS_ORDERED => [self::STATUS_CANCELLED, self::STATUS_SAMPLE_PENDING],
        self::STATUS_SAMPLE_PENDING => [self::STATUS_SAMPLE_COLLECTED],
        self::STATUS_SAMPLE_COLLECTED => [self::STATUS_RECEIVED],
        self::STATUS_RECEIVED => [self::STATUS_IN_PROGRESS],
        self::STATUS_IN_PROGRESS => [self::STATUS_RESULT_PENDING],
        self::STATUS_RESULT_PENDING => [],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The relative progress of each reachable status, used by
     * recomputeStatusFromItems() below to derive the order's own status as
     * the *least* advanced of its non-cancelled items — an order is never
     * more "done" than its least-progressed item (spec §17/§40 partial-
     * completion requirement, generalized to cover processing as well as
     * collection).
     *
     * @var array<string, int>
     */
    public const PROGRESS = [
        self::STATUS_ORDERED => 0,
        self::STATUS_SAMPLE_PENDING => 1,
        self::STATUS_SAMPLE_COLLECTED => 2,
        self::STATUS_RECEIVED => 3,
        self::STATUS_IN_PROGRESS => 4,
        self::STATUS_RESULT_PENDING => 5,
        self::STATUS_COMPLETED => 6,
    ];

    /**
     * Derive the correct aggregate status from a set of (non-cancelled)
     * item statuses. Shared by LaboratorySampleService (collection phase,
     * Step 31) and LaboratoryProcessingService (processing phase, Step 32)
     * so both compute the exact same rollup rule rather than duplicating
     * drifting logic.
     *
     * The rule (spec §39-40, generalized across both phases): once *every*
     * non-cancelled item has reached at least a given stage, the order is
     * reported at that stage (the highest one every item shares). But if
     * even one item hasn't started at all yet while another already has,
     * the order is only ever reported at the lowest "something has begun"
     * bucket (SAMPLE_PENDING) — never implying every item is further along
     * than the one that hasn't been touched. Returns ORDERED for an empty
     * set (defensive — an order always has at least one item in practice).
     *
     * @param  iterable<string>  $itemStatuses
     */
    public static function statusFromItemProgress(iterable $itemStatuses): string
    {
        $progress = array_map(
            fn (string $status) => self::PROGRESS[$status] ?? 0,
            is_array($itemStatuses) ? $itemStatuses : iterator_to_array($itemStatuses),
        );

        if ($progress === []) {
            return self::STATUS_ORDERED;
        }

        $min = min($progress);

        if ($min >= 1) {
            return array_search($min, self::PROGRESS, true) ?: self::STATUS_ORDERED;
        }

        return in_array(true, array_map(fn (int $p) => $p >= 1, $progress), true)
            ? self::STATUS_SAMPLE_PENDING
            : self::STATUS_ORDERED;
    }

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
     * The tests requested on this order (spec §5 — one order, many tests).
     */
    public function items(): HasMany
    {
        return $this->hasMany(LaboratoryOrderItem::class)->orderBy('id');
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

    /**
     * The specimens collected against this order (Phase 4, Step 31). One
     * order may produce several samples (spec §41).
     */
    public function samples(): HasMany
    {
        return $this->hasMany(LaboratorySample::class);
    }

    /**
     * Every released report version generated for this order (Phase 4,
     * Step 35) — one order, potentially several tests, produces one
     * versioned report document rather than one report per test.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(LaboratoryReport::class);
    }

    /**
     * The currently active (non-superseded) report version for this order,
     * if one has ever been generated.
     */
    public function activeReport(): HasOne
    {
        return $this->hasOne(LaboratoryReport::class)->where('status', LaboratoryReport::STATUS_ACTIVE);
    }
}
