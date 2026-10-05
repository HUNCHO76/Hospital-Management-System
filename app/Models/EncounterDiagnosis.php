<?php

namespace App\Models;

use Database\Factories\EncounterDiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clinician-confirmed diagnosis recorded against a clinical encounter —
 * distinct from Consultation (the clinician's narrative assessment) and from
 * DiagnosisCode (the reusable catalog entry it references). Multiple
 * diagnoses may exist per encounter (spec §13), with at most one active
 * "primary" among them (spec §12, enforced by DiagnosisService rather than a
 * database constraint — see the encounter_diagnoses migration).
 */
class EncounterDiagnosis extends Model
{
    /** @use HasFactory<EncounterDiagnosisFactory> */
    use HasFactory;

    /**
     * Diagnosis classification values.
     */
    public const TYPE_PRIMARY = 'primary';

    public const TYPE_SECONDARY = 'secondary';

    /**
     * Status values recognized for a diagnosis.
     */
    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that count as "active" — the set considered when enforcing
     * the one-active-primary-per-encounter rule.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PROVISIONAL,
        self::STATUS_CONFIRMED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * DiagnosisService — never trust a status change supplied directly by
     * the frontend. Cancellation is handled by its own dedicated action
     * (DiagnosisService::cancel()), not this generic transition map, mirroring
     * how every other clinical module in this app treats cancellation as its
     * own act rather than a generic status write.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PROVISIONAL => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_RESOLVED, self::STATUS_CANCELLED],
        self::STATUS_RESOLVED => [],
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
        'diagnosis_code_id',
        'diagnosis_type',
        'status',
        'clinical_note',
        'diagnosed_by',
        'diagnosed_at',
        'cancelled_at',
        'cancellation_reason',
        'corrected_at',
        'corrected_by',
        'correction_reason',
        'nhif_disease_code',
        'nhif_disease_name',
        'nhif_icd_version_code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diagnosed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed via the generic status-change path (excludes
     * cancellation — see DiagnosisService::cancel()).
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The clinical encounter this diagnosis belongs to.
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The consultation this diagnosis was documented under, if any.
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * The catalog entry (e.g. ICD-10 code) selected for this diagnosis.
     */
    public function diagnosisCode(): BelongsTo
    {
        return $this->belongsTo(DiagnosisCode::class);
    }

    /**
     * The user who recorded this diagnosis.
     */
    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }

    /**
     * The user who last corrected this diagnosis, if any.
     */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
