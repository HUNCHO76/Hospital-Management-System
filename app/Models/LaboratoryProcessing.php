<?php

namespace App\Models;

use Database\Factories\LaboratoryProcessingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bench-side processing lifecycle of a single test on a single
 * received specimen (Phase 4, Step 32). Created automatically the moment
 * its specimen is received (see LaboratoryProcessingService::
 * initializeForSample(), called from LaboratorySampleService::receive()) —
 * one row per (sample, item) pair, never one row per whole sample or whole
 * order, so tests sharing a specimen can be accepted/assigned/started/
 * completed independently (spec §15-16 partial processing).
 */
class LaboratoryProcessing extends Model
{
    /** @use HasFactory<LaboratoryProcessingFactory> */
    use HasFactory;

    /**
     * "Processing" is an uncountable noun — Eloquent's default pluralizer
     * would otherwise guess `laboratory_processings`, which doesn't match
     * the migration's own table name.
     */
    protected $table = 'laboratory_processing';

    /**
     * The processing lifecycle (spec §6): RECEIVED -> ACCEPTED -> ASSIGNED
     * -> IN_PROGRESS -> RESULT_PENDING, with REJECTED reachable from any
     * non-terminal status (a specimen can be found unsuitable at any point
     * before its processing is actually complete — spec §18's "during
     * laboratory processing", broader than the single literal branch spec
     * §6 sketches). Assignment is optional (spec §12): IN_PROGRESS is
     * reachable directly from ACCEPTED, not only via ASSIGNED.
     *
     * PROCESSING_COMPLETE is declared (spec §6/§14/§31/§34 all name it
     * explicitly) but is never a resting value for longer than the instant
     * inside completeProcessing()'s own transaction — that same action
     * immediately advances it to RESULT_PENDING, this step's own boundary.
     * completed_at/completed_by remain a lasting historical fact
     * regardless (the worklist's own "Completed" count reads those
     * columns, not the current `status`).
     */
    public const STATUS_RECEIVED = 'received';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PROCESSING_COMPLETE = 'processing_complete';

    public const STATUS_RESULT_PENDING = 'result_pending';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_ACCEPTED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PROCESSING_COMPLETE,
        self::STATUS_RESULT_PENDING,
        self::STATUS_REJECTED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * LaboratoryProcessingService — never trust a status change supplied
     * directly by the frontend. Transfer (spec §19) is deliberately not a
     * status value here — it only ever changes `bench_id` while status
     * stays ASSIGNED/IN_PROGRESS, audited as its own event.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_RECEIVED => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_ACCEPTED => [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_REJECTED],
        self::STATUS_ASSIGNED => [self::STATUS_IN_PROGRESS, self::STATUS_REJECTED],
        self::STATUS_IN_PROGRESS => [self::STATUS_PROCESSING_COMPLETE, self::STATUS_REJECTED],
        self::STATUS_PROCESSING_COMPLETE => [self::STATUS_RESULT_PENDING],
        self::STATUS_RESULT_PENDING => [],
        self::STATUS_REJECTED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_sample_id',
        'laboratory_order_item_id',
        'status',
        'bench_id',
        'assigned_to',
        'assigned_by',
        'assigned_at',
        'accepted_by',
        'accepted_at',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'processing_notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * The specimen this processing record belongs to.
     */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(LaboratorySample::class, 'laboratory_sample_id');
    }

    /**
     * The specific requested test this record processes.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderItem::class, 'laboratory_order_item_id');
    }

    /**
     * The work area currently (or most recently) assigned, if any.
     */
    public function bench(): BelongsTo
    {
        return $this->belongsTo(LaboratoryBench::class, 'bench_id');
    }

    /**
     * The technician this processing is assigned to, if any.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The user who made the assignment, if any.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The user who accepted the specimen for processing, if any.
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * The user who started processing, if any.
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * The user who completed processing, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
