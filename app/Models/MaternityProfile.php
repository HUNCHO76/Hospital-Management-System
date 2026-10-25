<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Maternity care episode and labour profile.
 *
 * Spans outpatient antenatal visits (ANC) and inpatient labour/delivery admissions.
 */
class MaternityProfile extends Model
{
    use GeneratesBusinessNumber;

    public const NUMBER_PREFIX = 'MAT';

    public const CARE_STAGE_AWAITING_ASSESSMENT = 'awaiting_assessment';

    public const CARE_STAGE_ASSESSMENT_IN_PROGRESS = 'assessment_in_progress';

    public const CARE_STAGE_ANTENATAL_CARE = 'antenatal_care';

    public const CARE_STAGE_IN_LABOUR = 'in_labour';

    public const CARE_STAGE_DELIVERY_IN_PROGRESS = 'delivery_in_progress';

    public const CARE_STAGE_POSTPARTUM_CARE = 'postpartum_care';

    public const CARE_STAGE_TRANSFERRED = 'transferred';

    public const CARE_STAGE_DISCHARGED = 'discharged';

    public const CARE_STAGE_COMPLETED = 'completed';

    public const CARE_STAGES = [
        self::CARE_STAGE_AWAITING_ASSESSMENT => 'Awaiting Assessment',
        self::CARE_STAGE_ASSESSMENT_IN_PROGRESS => 'Assessment In Progress',
        self::CARE_STAGE_ANTENATAL_CARE => 'Antenatal Care',
        self::CARE_STAGE_IN_LABOUR => 'In Labour',
        self::CARE_STAGE_DELIVERY_IN_PROGRESS => 'Delivery In Progress',
        self::CARE_STAGE_POSTPARTUM_CARE => 'Postpartum Care',
        self::CARE_STAGE_TRANSFERRED => 'Transferred',
        self::CARE_STAGE_DISCHARGED => 'Discharged',
        self::CARE_STAGE_COMPLETED => 'Completed',
    ];

    public const CARE_PATHWAY_OUTPATIENT = 'outpatient';

    public const CARE_PATHWAY_EMERGENCY = 'emergency';

    public const CARE_PATHWAY_INPATIENT = 'inpatient';

    public const CARE_PATHWAYS = [
        self::CARE_PATHWAY_OUTPATIENT => 'Outpatient Antenatal',
        self::CARE_PATHWAY_EMERGENCY => 'Emergency Maternity',
        self::CARE_PATHWAY_INPATIENT => 'Inpatient Labour & Delivery',
    ];

    /** Valid transitions from each care stage */
    public const STAGE_TRANSITIONS = [
        self::CARE_STAGE_AWAITING_ASSESSMENT => [
            self::CARE_STAGE_ASSESSMENT_IN_PROGRESS,
            self::CARE_STAGE_ANTENATAL_CARE,
            self::CARE_STAGE_IN_LABOUR,
            self::CARE_STAGE_TRANSFERRED,
            self::CARE_STAGE_DISCHARGED,
        ],
        self::CARE_STAGE_ASSESSMENT_IN_PROGRESS => [
            self::CARE_STAGE_ANTENATAL_CARE,
            self::CARE_STAGE_IN_LABOUR,
            self::CARE_STAGE_DELIVERY_IN_PROGRESS,
            self::CARE_STAGE_TRANSFERRED,
            self::CARE_STAGE_DISCHARGED,
        ],
        self::CARE_STAGE_ANTENATAL_CARE => [
            self::CARE_STAGE_IN_LABOUR,
            self::CARE_STAGE_COMPLETED,
            self::CARE_STAGE_TRANSFERRED,
            self::CARE_STAGE_DISCHARGED,
        ],
        self::CARE_STAGE_IN_LABOUR => [
            self::CARE_STAGE_DELIVERY_IN_PROGRESS,
            self::CARE_STAGE_POSTPARTUM_CARE,
            self::CARE_STAGE_TRANSFERRED,
        ],
        self::CARE_STAGE_DELIVERY_IN_PROGRESS => [
            self::CARE_STAGE_POSTPARTUM_CARE,
            self::CARE_STAGE_TRANSFERRED,
            self::CARE_STAGE_COMPLETED,
        ],
        self::CARE_STAGE_POSTPARTUM_CARE => [
            self::CARE_STAGE_COMPLETED,
            self::CARE_STAGE_TRANSFERRED,
            self::CARE_STAGE_DISCHARGED,
        ],
        self::CARE_STAGE_TRANSFERRED => [],
        self::CARE_STAGE_DISCHARGED => [],
        self::CARE_STAGE_COMPLETED => [],
    ];

    public const LABOUR_STAGES = [
        'latent_phase',
        'active_phase',
        'second_stage',
        'third_stage',
        'delivered',
        'complicated',
    ];

    public const PRESENTATIONS = [
        'cephalic',
        'breech',
        'shoulder',
        'face',
        'compound',
    ];

    public const MEMBRANE_STATUSES = [
        'intact',
        'ruptured',
        'artificial_rupture',
    ];

    public const HIV_STATUSES = [
        'negative',
        'positive_on_art',
        'positive_not_on_art',
        'unknown',
    ];

    /** @var list<string> */
    protected $fillable = [
        'episode_number',
        'admission_id',
        'patient_id',
        'clinical_encounter_id',
        'care_stage',
        'care_pathway',
        'assigned_midwife_id',
        'primary_clinician_id',
        'gravida',
        'para',
        'living_children',
        'abortions',
        'last_menstrual_period',
        'estimated_date_of_delivery',
        'gestational_age_weeks',
        'presentation',
        'membrane_status',
        'membrane_ruptured_at',
        'blood_group',
        'rhesus',
        'hiv_status',
        'vdrl_rpr_status',
        'risk_factors',
        'labour_stage',
        'status',
        'recorded_by',
        'notes',
        'clinical_exception_reason',
        'exception_authorized_by',
        'stage_updated_at',
        'stage_updated_by',
    ];

    protected function casts(): array
    {
        return [
            'last_menstrual_period' => 'date',
            'estimated_date_of_delivery' => 'date',
            'gestational_age_weeks' => 'float',
            'membrane_ruptured_at' => 'datetime',
            'stage_updated_at' => 'datetime',
            'risk_factors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            if (empty($profile->episode_number)) {
                $profile->episode_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'episode_number');
            }
            if (empty($profile->care_stage)) {
                $profile->care_stage = self::CARE_STAGE_AWAITING_ASSESSMENT;
            }
        });
    }

    public function canTransitionToStage(string $targetStage): bool
    {
        if ($this->care_stage === $targetStage) {
            return true;
        }

        $allowed = self::STAGE_TRANSITIONS[$this->care_stage] ?? [];

        return in_array($targetStage, $allowed, true);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function assignedMidwife(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_midwife_id');
    }

    public function primaryClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_clinician_id');
    }

    public function exceptionAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_authorized_by');
    }

    public function stageUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stage_updated_by');
    }

    public function partographEntries(): HasMany
    {
        return $this->hasMany(PartographEntry::class)->orderBy('recorded_at');
    }

    public function deliveryRecord(): HasOne
    {
        return $this->hasOne(DeliveryRecord::class);
    }
}
