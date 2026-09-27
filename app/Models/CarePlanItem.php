<?php

namespace App\Models;

use Database\Factories\CarePlanItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single treatment action within a CarePlan — a medication, procedure,
 * clinical intervention, referral, monitoring instruction, or follow-up
 * (spec §7). Medication-specific structured data lives on a separate
 * Prescription row (spec §8), never forced onto every item.
 */
class CarePlanItem extends Model
{
    /** @use HasFactory<CarePlanItemFactory> */
    use HasFactory;

    /**
     * Item type values recognized for a care plan item.
     */
    public const TYPE_MEDICATION = 'medication';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_CLINICAL_INTERVENTION = 'clinical_intervention';

    public const TYPE_REFERRAL = 'referral';

    public const TYPE_MONITORING = 'monitoring';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_OTHER = 'other';

    /**
     * The controlled set of item types recognized by the system.
     *
     * No TYPE_RADIOLOGY exists here: it was a clinical handoff boundary
     * only (Step 27, spec §4-6/20) for a future Radiology module, but
     * Radiology is now its own first-class `RadiologyOrder` belonging
     * directly to the ClinicalEncounter (Phase 5, Step 36) — the exact
     * same restructuring Laboratory went through in Step 30 ("do NOT make
     * Prescription -> Laboratory/Radiology"). See RadiologyOrder/
     * RadiologyOrderItem.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_MEDICATION,
        self::TYPE_PROCEDURE,
        self::TYPE_CLINICAL_INTERVENTION,
        self::TYPE_REFERRAL,
        self::TYPE_MONITORING,
        self::TYPE_FOLLOW_UP,
        self::TYPE_OTHER,
    ];

    /**
     * The controlled set of priority levels recognized by the system.
     *
     * @var list<string>
     */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Status values recognized for a care plan item.
     */
    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The single source of truth for valid status transitions. Enforced by
     * CarePlanService — never trust a status change supplied directly by the
     * frontend. Cancellation is handled by its own dedicated action
     * (CarePlanService::cancelItem()), not this generic transition map.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PLANNED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'care_plan_id',
        'encounter_diagnosis_id',
        'type',
        'description',
        'priority',
        'status',
        'start_date',
        'end_date',
        'instructions',
        'referred_to_department_id',
        'referred_to_practitioner_id',
        'created_by',
        'activated_by',
        'activated_at',
        'completed_by',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'corrected_at',
        'corrected_by',
        'correction_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed via the generic status-change path (excludes
     * cancellation — see CarePlanService::cancelItem()).
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The care plan this item belongs to.
     */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    /**
     * The diagnosis this item addresses, if any (spec §15).
     */
    public function encounterDiagnosis(): BelongsTo
    {
        return $this->belongsTo(EncounterDiagnosis::class);
    }

    /**
     * The department this item refers the patient to, if type = referral.
     */
    public function referredToDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'referred_to_department_id');
    }

    /**
     * The practitioner this item refers the patient to, if type = referral.
     */
    public function referredToPractitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'referred_to_practitioner_id');
    }

    /**
     * The user who created this item.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who activated (started) this item, if any.
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * The user who completed this item, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The user who last corrected this item, if any.
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * The structured prescription details, if type = medication.
     */
    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }
}
