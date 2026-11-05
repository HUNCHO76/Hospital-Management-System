<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Serial growth and developmental progress records for newborns and infants.
 * Uses WHO Child Growth Standards 2006 as the reference standard.
 */
class NewbornGrowthRecord extends Model
{
    use HasFactory;

    public const FEEDING_METHODS = [
        'exclusive_breastfeeding' => 'Exclusive Breastfeeding (EBF)',
        'formula_feeding' => 'Infant Formula Feeding',
        'mixed_feeding' => 'Mixed (Breast + Formula)',
        'complementary_feeding' => 'Complementary Foods + Breastfeeding',
    ];

    public const REFERENCE_STANDARD = 'WHO Child Growth Standards 2006';

    /** @var list<string> */
    protected $fillable = [
        'patient_id',
        'mother_patient_id',
        'maternity_profile_id',
        'delivery_record_id',
        'reception_visit_id',
        'clinical_encounter_id',
        'recorded_at',
        'age_days',
        'weight_kg',
        'length_height_cm',
        'head_circumference_cm',
        'temperature_c',
        'feeding_method',
        'immunizations_given',
        'developmental_milestones',
        'growth_reference_standard',
        'clinical_notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'age_days' => 'integer',
            'weight_kg' => 'float',
            'length_height_cm' => 'float',
            'head_circumference_cm' => 'float',
            'temperature_c' => 'float',
            'immunizations_given' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'mother_patient_id');
    }

    public function maternityProfile(): BelongsTo
    {
        return $this->belongsTo(MaternityProfile::class);
    }

    public function deliveryRecord(): BelongsTo
    {
        return $this->belongsTo(DeliveryRecord::class);
    }

    public function receptionVisit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
