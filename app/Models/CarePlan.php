<?php

namespace App\Models;

use Database\Factories\CarePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The treatment/care plan for a clinical encounter — what should be done for
 * the patient, distinct from Consultation (the clinician's narrative
 * assessment) and EncounterDiagnosis (what the clinician believes/
 * identifies). One care plan exists per encounter (spec §5), holding
 * multiple CarePlanItem entries.
 */
class CarePlan extends Model
{
    /** @use HasFactory<CarePlanFactory> */
    use HasFactory;

    /**
     * Status values recognized for a care plan.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The single source of truth for valid status transitions. Enforced by
     * CarePlanService — never trust a status change supplied directly by the
     * frontend. Cancellation is handled by its own dedicated action
     * (CarePlanService::cancel()), not this generic transition map.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
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
        'clinical_encounter_id',
        'consultation_id',
        'status',
        'goals',
        'clinical_instructions',
        'monitoring_instructions',
        'follow_up_instructions',
        'created_by',
        'started_at',
        'completed_at',
        'completed_by',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed via the generic status-change path (excludes
     * cancellation — see CarePlanService::cancel()).
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The clinical encounter this care plan belongs to.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The consultation this care plan was documented under, if any.
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * The user who created (started drafting) this care plan.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who completed this care plan, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The user who last corrected this care plan, if any.
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * This plan's treatment items, oldest first.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CarePlanItem::class)->orderBy('created_at');
    }
}
