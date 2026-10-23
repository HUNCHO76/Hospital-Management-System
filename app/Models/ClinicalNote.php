<?php

namespace App\Models;

use Database\Factories\ClinicalNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single piece of clinical documentation authored against an encounter —
 * distinct from Consultation::clinical_notes (one free-text field within
 * that clinician's own SOAP record) and from Diagnosis/CarePlan (structured
 * clinical decisions). Many notes may exist per encounter, from potentially
 * different authors (a nurse's nursing note, a doctor's progress note), each
 * independently progressing through its own DRAFT -> SIGNED -> AMENDED
 * lifecycle.
 */
class ClinicalNote extends Model
{
    /** @use HasFactory<ClinicalNoteFactory> */
    use HasFactory;

    /**
     * The categories of clinical note this system supports — only those
     * justified by the existing clinical workflow (spec §6).
     */
    public const TYPE_PROGRESS = 'progress';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_NURSING = 'nursing';

    public const TYPE_CLINICAL_OBSERVATION = 'clinical_observation';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_PROGRESS,
        self::TYPE_FOLLOW_UP,
        self::TYPE_NURSING,
        self::TYPE_CLINICAL_OBSERVATION,
        self::TYPE_PROCEDURE,
        self::TYPE_OTHER,
    ];

    /**
     * Status values recognized for a clinical note.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_AMENDED = 'amended';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The single source of truth for valid status transitions. Enforced by
     * ClinicalNoteService — never trust a status change supplied directly by
     * the frontend. Cancellation is only reachable from draft: a signed
     * clinical record is amended, never cancelled, so its history is never
     * lost.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SIGNED, self::STATUS_CANCELLED],
        self::STATUS_SIGNED => [self::STATUS_AMENDED],
        self::STATUS_AMENDED => [self::STATUS_AMENDED],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_encounter_id',
        'patient_id',
        'author_id',
        'note_type',
        'note_content',
        'status',
        'signed_at',
        'signed_by',
        'corrected_at',
        'corrected_by',
        'correction_reason',
        'cancelled_at',
        'cancellation_reason',
        'note_category',
    ];

    /**
     * Structured categories for nursing notes (optional on other note types).
     *
     * @var list<string>
     */
    public const NURSING_CATEGORIES = [
        'initial_assessment',
        'progress',
        'response_to_treatment',
        'intake_output',
        'safety_observation',
        'doctor_communication',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'corrected_at' => 'datetime',
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
     * The clinical encounter this note documents.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The patient this note is about (denormalized from the encounter).
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The user who authored this note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The user who signed this note, if any.
     */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /**
     * The user who last amended this note, if any.
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * The append-only version history — one row snapshotted at signing, and
     * one more per subsequent amendment. See ClinicalNoteService.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ClinicalNoteVersion::class)->orderBy('version_number');
    }
}
