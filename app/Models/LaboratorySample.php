<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\LaboratorySampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The authoritative specimen entity (Phase 4, Step 31) — what was actually
 * drawn from the patient, distinct from the LaboratoryOrder (the clinical
 * request) and LaboratoryOrderItem (a single requested test). One sample
 * may satisfy several order items that share the same specimen/container
 * requirement (spec §11-12, see items()); an incompatible test requires a
 * separate sample (spec §13) — enforced by LaboratorySampleService, never
 * by this model alone.
 */
class LaboratorySample extends Model
{
    /** @use HasFactory<LaboratorySampleFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating sample numbers.
     */
    public const NUMBER_PREFIX = 'SMP';

    /**
     * The specimen lifecycle (spec §19). PENDING_COLLECTION -> COLLECTED ->
     * LABELLED -> RECEIVED, with COLLECTED -> REJECTED and
     * PENDING_COLLECTION -> CANCELLED as Step 31's own branches. Step 32
     * extends rejection to also be reachable from RECEIVED — a specimen
     * found unsuitable during laboratory processing itself (e.g. hemolysed,
     * noticed only once benched) must still be rejectable, reusing this
     * exact reject()/recollect() machinery rather than inventing a second
     * rejection concept at the processing layer (spec §18 explicit
     * instruction to reuse Step 31's architecture).
     */
    public const STATUS_PENDING_COLLECTION = 'pending_collection';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_LABELLED = 'labelled';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING_COLLECTION,
        self::STATUS_COLLECTED,
        self::STATUS_LABELLED,
        self::STATUS_RECEIVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * LaboratorySampleService — never trust a status change supplied
     * directly by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_COLLECTION => [self::STATUS_COLLECTED, self::STATUS_CANCELLED],
        self::STATUS_COLLECTED => [self::STATUS_LABELLED, self::STATUS_REJECTED],
        self::STATUS_LABELLED => [self::STATUS_RECEIVED],
        self::STATUS_RECEIVED => [self::STATUS_REJECTED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Rejection reasons recognized by the system (spec §31) — a plain
     * validated list, not a separate configuration table, matching the
     * catalog's own specimen_type/specimen_container precedent (Step 29).
     *
     * @var list<string>
     */
    public const REJECTION_REASONS = [
        'insufficient_sample',
        'wrong_container',
        'wrong_specimen',
        'hemolysed',
        'clotted',
        'leaking_container',
        'unlabelled',
        'mislabeled',
        'contaminated',
        'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sample_number',
        'laboratory_order_id',
        'clinical_encounter_id',
        'patient_id',
        'specimen_type',
        'container_type',
        'status',
        'collection_site',
        'collection_notes',
        'collected_by',
        'collected_at',
        'labelled_at',
        'received_by',
        'received_at',
        'rejection_reason',
        'rejection_notes',
        'rejected_by',
        'rejected_at',
        'recollection_of_sample_id',
        'cancelled_by',
        'cancelled_at',
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
            'collected_at' => 'datetime',
            'labelled_at' => 'datetime',
            'received_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sample): void {
            if (empty($sample->sample_number)) {
                $sample->sample_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'sample_number');
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
     * The laboratory order this specimen was collected against.
     */
    public function laboratoryOrder(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class);
    }

    /**
     * The clinical encounter this specimen was collected against
     * (denormalized from the order — mirrors ClinicalNote's own
     * `patient_id` precedent, avoiding a multi-hop join for every sample
     * list/IDOR check).
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The patient this specimen was drawn from (denormalized, see above).
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The order items this specimen satisfies (spec §11-12 — one sample
     * may cover several compatible tests).
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LaboratoryOrderItem::class, 'laboratory_sample_items')->withTimestamps();
    }

    /**
     * The user who collected this specimen, if collected.
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * The laboratory user who received this specimen, if received.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The user who rejected this specimen, if rejected.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * The user who cancelled this specimen, if cancelled.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The rejected sample this specimen was collected to replace, if this
     * is a recollection (spec §33-34).
     */
    public function recollectionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recollection_of_sample_id');
    }

    /**
     * The recollection(s) created because this specimen was rejected, if
     * any.
     */
    public function recollections(): HasMany
    {
        return $this->hasMany(self::class, 'recollection_of_sample_id');
    }

    /**
     * This specimen's own laboratory-processing records (Phase 4, Step 32)
     * — one per attached order item, created the moment the specimen is
     * received (see LaboratoryProcessingService::initializeForSample()).
     */
    public function processingRecords(): HasMany
    {
        return $this->hasMany(LaboratoryProcessing::class);
    }

    /**
     * This specimen's own results (Phase 4, Step 33) — one per order item
     * it satisfies, created the moment that item's processing reaches
     * RESULT_PENDING.
     */
    public function results(): HasMany
    {
        return $this->hasMany(LaboratoryResult::class);
    }
}
