<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time-point record plotted on a WHO-aligned Partograph during active labour.
 */
class PartographEntry extends Model
{
    public const AMNIOTIC_FLUID_STATES = [
        'I' => 'Intact',
        'C' => 'Clear',
        'M' => 'Meconium-stained',
        'B' => 'Blood-stained',
        'A' => 'Absent',
    ];

    public const MOULDING_GRADES = [
        '0' => 'Bones separated',
        '+' => 'Bones touching',
        '++' => 'Bones overlapping (reducible)',
        '+++' => 'Bones severely overlapping (non-reducible)',
    ];

    public const CONTRACTION_DURATIONS = [
        'mild' => '< 20 seconds',
        'moderate' => '20 - 40 seconds',
        'strong' => '> 40 seconds',
    ];

    /** @var list<string> */
    protected $fillable = [
        'maternity_profile_id',
        'admission_id',
        'clinical_encounter_id',
        'patient_id',
        'recorded_at',
        'fetal_heart_rate',
        'amniotic_fluid',
        'moulding',
        'cervical_dilation_cm',
        'descent_of_head',
        'alert_line_dilation_cm',
        'action_line_dilation_cm',
        'action_line_crossed',
        'contractions_per_10min',
        'contraction_duration',
        'oxytocin_drops_per_min',
        'oxytocin_dose_units',
        'drugs_iv_fluids',
        'systolic_bp',
        'diastolic_bp',
        'pulse_bpm',
        'temperature_c',
        'urine_protein',
        'urine_acetone',
        'urine_volume_ml',
        'notes',
        'is_emergency',
        'emergency_reason',
        'emergency_authorized_by',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cervical_dilation_cm' => 'float',
            'alert_line_dilation_cm' => 'float',
            'action_line_dilation_cm' => 'float',
            'action_line_crossed' => 'boolean',
            'temperature_c' => 'float',
            'is_emergency' => 'boolean',
        ];
    }

    public function maternityProfile(): BelongsTo
    {
        return $this->belongsTo(MaternityProfile::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function emergencyAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emergency_authorized_by');
    }
}
