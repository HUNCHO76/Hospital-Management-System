<?php

namespace App\Models;

use Database\Factories\TriageAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The initial clinical assessment used to determine a patient's condition
 * and priority before further clinical care — distinct from ClinicalEncounter
 * (the overall episode of care) and from VitalSign (the objective
 * measurements captured as part of this assessment). One primary triage
 * assessment exists per encounter (spec §17); a future reassessment workflow
 * would model additional rounds as its own concept rather than a second row
 * here.
 */
class TriageAssessment extends Model
{
    /** @use HasFactory<TriageAssessmentFactory> */
    use HasFactory;

    /**
     * Status values recognized for a triage assessment.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The controlled triage classification recognized by the system —
     * standard four-level acuity categories (spec §6), most urgent first.
     */
    public const CATEGORY_EMERGENCY = 'emergency';

    public const CATEGORY_VERY_URGENT = 'very_urgent';

    public const CATEGORY_URGENT = 'urgent';

    public const CATEGORY_NON_URGENT = 'non_urgent';

    public const CATEGORIES = [
        self::CATEGORY_EMERGENCY,
        self::CATEGORY_VERY_URGENT,
        self::CATEGORY_URGENT,
        self::CATEGORY_NON_URGENT,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * TriageService — never trust a status change supplied directly by the
     * frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
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
        'assessed_by',
        'assessment_time',
        'triage_category',
        'chief_complaint',
        'pain_score',
        'status',
        'notes',
        'completed_at',
        'completed_by',
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
            'assessment_time' => 'datetime',
            'completed_at' => 'datetime',
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
     * The clinical encounter this triage assessment belongs to.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The user who performed/owns this assessment.
     */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /**
     * The user who completed this assessment, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The vital-sign observations recorded as part of this assessment, in
     * the order they were taken.
     */
    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSign::class)->orderBy('recorded_at');
    }
}
