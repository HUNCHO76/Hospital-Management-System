<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery summary documenting maternal delivery and newborn outcome.
 */
class DeliveryRecord extends Model
{
    public const MODES = [
        'spontaneous_vaginal' => 'Spontaneous Vaginal Delivery (SVD)',
        'assisted_vacuum' => 'Vacuum-Assisted Delivery',
        'assisted_forceps' => 'Forceps-Assisted Delivery',
        'elective_c_section' => 'Elective Caesarean Section',
        'emergency_c_section' => 'Emergency Caesarean Section',
        'breech_extraction' => 'Assisted Breech Delivery',
    ];

    public const PERINEAL_STATUSES = [
        'intact' => 'Intact Perineum',
        'first_degree' => '1st Degree Tear',
        'second_degree' => '2nd Degree Tear',
        'third_degree' => '3rd Degree Tear',
        'fourth_degree' => '4th Degree Tear',
        'episiotomy' => 'Episiotomy',
    ];

    public const BABY_OUTCOMES = [
        'live_birth' => 'Live Birth',
        'fresh_stillbirth' => 'Fresh Stillbirth (FSB)',
        'macerated_stillbirth' => 'Macerated Stillbirth (MSB)',
    ];

    /** @var list<string> */
    protected $fillable = [
        'maternity_profile_id',
        'admission_id',
        'clinical_encounter_id',
        'patient_id',
        'delivered_at',
        'delivery_mode',
        'indication',
        'conducted_by',
        'assisted_by',
        'placenta_delivered_at',
        'placenta_mode',
        'placenta_complete',
        'blood_loss_ml',
        'postpartum_hemorrhage',
        'perineal_status',
        'suture_materials_used',
        'oxytocic_given',
        'baby_outcome',
        'baby_gender',
        'birth_weight_grams',
        'birth_length_cm',
        'head_circumference_cm',
        'apgar_1min',
        'apgar_5min',
        'apgar_10min',
        'resuscitation_required',
        'resuscitation_notes',
        'vitamin_k_given',
        'eye_ointment_given',
        'bcg_given',
        'polio_0_given',
        'baby_patient_id',
        'notes',
        'is_emergency',
        'emergency_reason',
        'emergency_authorized_by',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'placenta_delivered_at' => 'datetime',
            'placenta_complete' => 'boolean',
            'postpartum_hemorrhage' => 'boolean',
            'resuscitation_required' => 'boolean',
            'vitamin_k_given' => 'boolean',
            'eye_ointment_given' => 'boolean',
            'bcg_given' => 'boolean',
            'polio_0_given' => 'boolean',
            'birth_length_cm' => 'float',
            'head_circumference_cm' => 'float',
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

    public function babyPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'baby_patient_id');
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function assistedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assisted_by');
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
