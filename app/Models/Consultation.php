<?php

namespace App\Models;

use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The clinician's examination and clinical assessment for an encounter —
 * distinct from TriageAssessment (the initial urgency classification before
 * the doctor is involved) and from any future Diagnosis/Treatment record.
 * One consultation exists per encounter (spec §4); a future re-consultation
 * workflow would model additional rounds as its own concept rather than a
 * second row here.
 */
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use HasFactory;

    /**
     * Status values recognized for a consultation.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The single source of truth for valid status transitions. Enforced by
     * ConsultationService — never trust a status change supplied directly by
     * the frontend.
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
     * Practitioner types eligible to hold a consultation (spec §6 — "doctor
     * or authorized clinician").
     *
     * @var list<string>
     */
    public const ELIGIBLE_PRACTITIONER_TYPES = ['doctor', 'clinical-officer', 'dentist', 'nurse'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_encounter_id',
        'practitioner_id',
        'created_by',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'cancelled_at',
        'cancellation_reason',
        'corrected_at',
        'corrected_by',
        'correction_reason',
        'chief_complaint',
        'history_presenting_illness',
        'relevant_medical_history',
        'relevant_surgical_history',
        'relevant_social_history',
        'review_of_systems',
        'physical_examination',
        'assessment_summary',
        'clinical_notes',
        'plan_summary',
    ];

    /**
     * The narrative clinical fields a doctor documents on the consultation —
     * the single source of truth used by the update/correct requests and
     * services so the field list is never duplicated.
     *
     * @var list<string>
     */
    public const NARRATIVE_FIELDS = [
        'chief_complaint',
        'history_presenting_illness',
        'relevant_medical_history',
        'relevant_surgical_history',
        'relevant_social_history',
        'review_of_systems',
        'physical_examination',
        'assessment_summary',
        'clinical_notes',
        'plan_summary',
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
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The clinical encounter this consultation belongs to.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The practitioner conducting this consultation.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * The user who created (started) this consultation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who completed this consultation, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The user who last corrected this consultation, if any.
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
