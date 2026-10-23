<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operating theatre surgical episode with WHO Surgical Safety Checklist,
 * intra-operative record, and PACU / post-anesthesia recovery monitoring.
 */
class SurgicalCase extends Model
{
    use GeneratesBusinessNumber;

    public const NUMBER_PREFIX = 'SURG';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_IN_THEATRE = 'in_theatre';

    public const STATUS_RECOVERY = 'recovery';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_IN_THEATRE,
        self::STATUS_RECOVERY,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const ANESTHESIA_TYPES = [
        'general' => 'General Anesthesia (GA)',
        'spinal' => 'Spinal Anesthesia (SAB)',
        'epidural' => 'Epidural Anesthesia',
        'regional_block' => 'Regional Nerve Block',
        'local' => 'Local Infiltration Anesthesia',
        'sedation' => 'Procedural / Conscious Sedation',
    ];

    /** @var list<string> */
    protected $fillable = [
        'case_number',
        'procedure_order_id',
        'admission_id',
        'patient_id',
        'theatre_room',
        'lead_surgeon_id',
        'assistant_surgeon_id',
        'anesthetist_id',
        'scrub_nurse_id',
        'circulating_nurse_id',
        'anesthesia_type',

        // WHO Surgical Safety Checklist
        'identity_confirmed',
        'site_marked',
        'consent_confirmed',
        'anesthesia_safety_check_completed',
        'pulse_oximeter_functioning',
        'allergy_screened',
        'difficult_airway_risk_assessed',
        'blood_loss_risk_assessed',
        'fasting_npo_hours_confirmed',
        'checklist_completed_by',

        // Intra-Operative Record
        'started_at',
        'ended_at',
        'pre_operative_diagnosis',
        'post_operative_diagnosis',
        'surgical_technique',
        'intraoperative_findings',
        'implants_used',
        'drains_placed',
        'swab_instrument_count_correct',
        'specimens_sent_to_lab',
        'estimated_blood_loss_ml',
        'complications',

        // PACU / Post-Anesthesia Recovery Monitoring
        'recovery_started_at',
        'recovery_ended_at',
        'aldrete_score',
        'post_operative_orders',
        'transferred_to_ward_at',

        'status',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'identity_confirmed' => 'boolean',
            'site_marked' => 'boolean',
            'consent_confirmed' => 'boolean',
            'anesthesia_safety_check_completed' => 'boolean',
            'pulse_oximeter_functioning' => 'boolean',
            'allergy_screened' => 'boolean',
            'difficult_airway_risk_assessed' => 'boolean',
            'blood_loss_risk_assessed' => 'boolean',
            'fasting_npo_hours_confirmed' => 'boolean',
            'swab_instrument_count_correct' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'recovery_started_at' => 'datetime',
            'recovery_ended_at' => 'datetime',
            'transferred_to_ward_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $case) {
            if (empty($case->case_number)) {
                $case->case_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'case_number');
            }
        });
    }

    public function procedureOrder(): BelongsTo
    {
        return $this->belongsTo(ProcedureOrder::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function leadSurgeon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_surgeon_id');
    }

    public function assistantSurgeon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_surgeon_id');
    }

    public function anesthetist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anesthetist_id');
    }

    public function scrubNurse(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scrub_nurse_id');
    }

    public function circulatingNurse(): BelongsTo
    {
        return $this->belongsTo(User::class, 'circulating_nurse_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
