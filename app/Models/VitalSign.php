<?php

namespace App\Models;

use Database\Factories\VitalSignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single, immutable observation of objective physiological measurements
 * taken during a TriageAssessment. Every field is nullable at the database
 * level — a real recording only fills in whichever measurements were
 * actually taken — but VitalSignsService requires at least one before
 * accepting a submission.
 *
 * Never edited or deleted once recorded (spec §19): an incorrect
 * measurement is corrected by creating a new row that points back at the
 * one it supersedes via `corrects_vital_sign_id`, preserving the original
 * exactly as taken.
 */
class VitalSign extends Model
{
    /** @use HasFactory<VitalSignFactory> */
    use HasFactory;

    /**
     * The measurement columns this model stores — the single source of
     * truth for which fields VitalSignsService::evaluate() walks to compute
     * abnormal-value flags, and for which fields a correction may carry.
     *
     * @var list<string>
     */
    public const MEASUREMENTS = [
        'temperature_c',
        'systolic_mmhg',
        'diastolic_mmhg',
        'pulse_bpm',
        'respiratory_rate_bpm',
        'oxygen_saturation_pct',
        'weight_kg',
        'height_cm',
    ];

    /**
     * The attributes that are mass assignable. `bmi` is deliberately absent
     * — it is always calculated server-side from weight_kg/height_cm, never
     * accepted as direct input (spec §7).
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_encounter_id',
        'triage_assessment_id',
        'admission_id',
        'recorded_by',
        'recorded_at',
        'temperature_c',
        'systolic_mmhg',
        'diastolic_mmhg',
        'pulse_bpm',
        'respiratory_rate_bpm',
        'oxygen_saturation_pct',
        'weight_kg',
        'height_cm',
        'bmi',
        'corrects_vital_sign_id',
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
            'recorded_at' => 'datetime',
            'temperature_c' => 'decimal:1',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:1',
            'bmi' => 'decimal:2',
        ];
    }

    /**
     * The triage assessment this observation was recorded under.
     * Nullable for inpatient vitals (no triage assessment exists).
     */
    public function triageAssessment(): BelongsTo
    {
        return $this->belongsTo(TriageAssessment::class);
    }

    /**
     * The admission this observation was recorded during, if it is an
     * inpatient vital sign (triage_assessment_id = NULL).
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * The clinical encounter this observation belongs to (denormalized from
     * the triage assessment — see the migration).
     */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    /**
     * The user who recorded this observation.
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The observation this one corrects, if this row is a correction.
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_vital_sign_id');
    }

    /**
     * The correction that superseded this observation, if any.
     */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_vital_sign_id');
    }
}
