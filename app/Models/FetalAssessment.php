<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dedicated Fetal Assessment & Monitoring observation model (Phase 4).
 *
 * Captures gestational age, FHR, fetal movements, fundal height, presentation, lie,
 * multi-fetal identification, estimated fetal weight, ultrasound findings, and clinical notes.
 * Strictly distinct from maternal vital signs.
 */
class FetalAssessment extends Model
{
    use HasFactory;

    public const PRESENTATION_CEPHALIC = 'cephalic';
    public const PRESENTATION_BREECH = 'breech';
    public const PRESENTATION_SHOULDER = 'shoulder';
    public const PRESENTATION_FACE = 'face';
    public const PRESENTATION_COMPOUND = 'compound';
    public const PRESENTATION_UNSTABILISED = 'unstabilised';

    public const PRESENTATIONS = [
        self::PRESENTATION_CEPHALIC => 'Cephalic (Vertex)',
        self::PRESENTATION_BREECH => 'Breech',
        self::PRESENTATION_SHOULDER => 'Shoulder / Transverse',
        self::PRESENTATION_FACE => 'Face',
        self::PRESENTATION_COMPOUND => 'Compound',
        self::PRESENTATION_UNSTABILISED => 'Unstabilised / Variable',
    ];

    public const LIE_LONGITUDINAL = 'longitudinal';
    public const LIE_TRANSVERSE = 'transverse';
    public const LIE_OBLIQUE = 'oblique';
    public const LIE_VARIABLE = 'variable';

    public const LIES = [
        self::LIE_LONGITUDINAL => 'Longitudinal',
        self::LIE_TRANSVERSE => 'Transverse',
        self::LIE_OBLIQUE => 'Oblique',
        self::LIE_VARIABLE => 'Variable',
    ];

    public const MOVEMENT_NORMAL = 'normal';
    public const MOVEMENT_REDUCED = 'reduced';
    public const MOVEMENT_ABSENT = 'absent';
    public const MOVEMENT_INCREASED = 'increased';

    public const MOVEMENTS = [
        self::MOVEMENT_NORMAL => 'Normal / Active',
        self::MOVEMENT_REDUCED => 'Reduced',
        self::MOVEMENT_ABSENT => 'Absent',
        self::MOVEMENT_INCREASED => 'Excessive / Vigorous',
    ];

    protected $fillable = [
        'maternity_profile_id',
        'patient_id',
        'reception_visit_id',
        'clinical_encounter_id',
        'assessed_by',
        'assessed_at',
        'gestational_age_weeks',
        'number_of_fetuses',
        'fetus_identifier',
        'fetal_heart_rate',
        'fetal_movements',
        'fundal_height_cm',
        'presentation',
        'lie',
        'estimated_fetal_weight_grams',
        'ultrasound_findings',
        'clinical_notes',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'gestational_age_weeks' => 'float',
            'fundal_height_cm' => 'float',
            'fetal_heart_rate' => 'integer',
            'number_of_fetuses' => 'integer',
            'estimated_fetal_weight_grams' => 'integer',
        ];
    }

    public function maternityProfile(): BelongsTo
    {
        return $this->belongsTo(MaternityProfile::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function receptionVisit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
