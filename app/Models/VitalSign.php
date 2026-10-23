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
     * AVPU consciousness levels accepted for bedside neurological observation.
     *
     * @var list<string>
     */
    public const CONSCIOUSNESS_LEVELS = ['alert', 'voice', 'pain', 'unresponsive'];

    /**
     * Oxygen support modes recordable alongside SpO2.
     *
     * @var list<string>
     */
    public const OXYGEN_SUPPORT = ['room_air', 'nasal_cannula', 'face_mask', 'non_rebreather', 'other'];

    /**
     * Additional inpatient nursing observations stored on the same row.
     *
     * @var list<string>
     */
    public const NURSING_FIELDS = [
        'pain_score',
        'consciousness_level',
        'oxygen_support',
        'oxygen_flow_lpm',
        'intake_ml',
        'output_ml',
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
        'measured_at',
        'pain_score',
        'consciousness_level',
        'oxygen_support',
        'oxygen_flow_lpm',
        'intake_ml',
        'output_ml',
        'observation_notes',
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
            'measured_at' => 'datetime',
            'oxygen_flow_lpm' => 'decimal:1',
            'temperature_c' => 'decimal:1',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:1',
            'bmi' => 'decimal:2',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'pulse_rate',
        'blood_pressure_systolic',
        'blood_pressure_diastolic',
        'respiratory_rate',
        'oxygen_saturation',
    ];

    public function getPulseRateAttribute(): ?int
    {
        return $this->pulse_bpm;
    }

    public function getBloodPressureSystolicAttribute(): ?int
    {
        return $this->systolic_mmhg;
    }

    public function getBloodPressureDiastolicAttribute(): ?int
    {
        return $this->diastolic_mmhg;
    }

    public function getRespiratoryRateAttribute(): ?int
    {
        return $this->respiratory_rate_bpm;
    }

    public function getOxygenSaturationAttribute(): ?int
    {
        return $this->oxygen_saturation_pct;
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
