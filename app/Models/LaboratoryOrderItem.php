<?php

namespace App\Models;

use Database\Factories\LaboratoryOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single requested test within a LaboratoryOrder (Phase 4, Step 30 —
 * "one order, many tests"). `test_code`/`test_name` are snapshotted at
 * order time (spec §44's historical-integrity requirement) so a later
 * rename/deactivation of the catalog LaboratoryTest never makes an existing
 * order ambiguous. Carries its own status, independently of the parent
 * order's, because individual tests within one order may progress through
 * sample/processing states at different rates once Steps 31-34 build that
 * workflow (spec §8) — only ORDERED/CANCELLED are reachable in this step.
 */
class LaboratoryOrderItem extends Model
{
    /** @use HasFactory<LaboratoryOrderItemFactory> */
    use HasFactory;

    /**
     * Status values recognized for a laboratory order item. Forward-declares
     * the same future workflow states as LaboratoryOrder's own STATUSES;
     * only ORDERED/CANCELLED are reachable until Steps 31-34.
     */
    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SAMPLE_PENDING = 'sample_pending';

    public const STATUS_SAMPLE_COLLECTED = 'sample_collected';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESULT_PENDING = 'result_pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Enforced by LaboratorySampleService's/LaboratoryProcessingService's
     * rollup recompute (Phase 4, Steps 31-32), never directly by user
     * action — an item's status always mirrors whichever of its samples or
     * processing records is currently active. SAMPLE_PENDING is reachable
     * while an active sample sits in PENDING_COLLECTION; SAMPLE_COLLECTED
     * covers both COLLECTED and LABELLED; RECEIVED covers a sample that has
     * arrived at the lab but whose processing hasn't started; IN_PROGRESS
     * mirrors an active LaboratoryProcessing record's own IN_PROGRESS
     * state; RESULT_PENDING is this step's own boundary — "ready for
     * result entry." The backward edges model an item reverting to an
     * earlier stage once its active sample is rejected (spec §33).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_ORDERED => [self::STATUS_CANCELLED, self::STATUS_SAMPLE_PENDING],
        self::STATUS_SAMPLE_PENDING => [self::STATUS_SAMPLE_COLLECTED, self::STATUS_ORDERED],
        self::STATUS_SAMPLE_COLLECTED => [self::STATUS_RECEIVED, self::STATUS_SAMPLE_PENDING, self::STATUS_ORDERED],
        self::STATUS_RECEIVED => [self::STATUS_IN_PROGRESS, self::STATUS_ORDERED],
        self::STATUS_IN_PROGRESS => [self::STATUS_RESULT_PENDING, self::STATUS_ORDERED],
        self::STATUS_RESULT_PENDING => [],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_order_id',
        'laboratory_test_id',
        'test_code',
        'test_name',
        'clinical_note',
        'specimen_source',
        'status',
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
            'cancelled_at' => 'datetime',
        ];
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
     * The order this item belongs to.
     */
    public function laboratoryOrder(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class);
    }

    /**
     * The catalog entry this item was ordered from.
     */
    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }

    /**
     * The user who cancelled this item, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The specimens ever registered against this item (Phase 4, Step 31) —
     * may include more than one over time (a rejected sample plus its
     * recollection), oldest first. The item's own `status` always reflects
     * only the most recent non-cancelled one; see
     * LaboratorySampleService::recomputeItemStatus().
     */
    public function samples(): BelongsToMany
    {
        return $this->belongsToMany(LaboratorySample::class, 'laboratory_sample_items')->withTimestamps()->orderBy('laboratory_samples.id');
    }

    /**
     * This item's own laboratory-processing records (Phase 4, Step 32) —
     * may include more than one over time, mirroring samples() above.
     */
    public function processingRecords(): HasMany
    {
        return $this->hasMany(LaboratoryProcessing::class);
    }

    /**
     * This item's own result (Phase 4, Step 33) — exactly one per item, the
     * catalog carrying no panel/analyte structure for a single test to
     * decompose into (confirmed by audit before Step 33 was built).
     */
    public function result(): HasOne
    {
        return $this->hasOne(LaboratoryResult::class);
    }
}
