<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Medication Administration Record (MAR) — the clinical execution layer
 * that sits after pharmacy dispensing (Phase 6, Step 48). Stock is already
 * deducted at dispensing; this row records the bedside moment when nursing
 * staff actually administered (or held/refused/missed) the medication.
 *
 * Amendment chain: when a record needs correcting, a new row is written with
 * corrects_id pointing to the superseded row. The original row is never
 * deleted or updated — immutable audit trail, identical to ClinicalNote.
 */
class MedicationAdministration extends Model
{
    public const STATUS_ADMINISTERED = 'administered';

    public const STATUS_HELD = 'held';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_MISSED = 'missed';

    /** Dose given later than scheduled; counts as a given dose and needs a reason. */
    public const STATUS_DELAYED = 'delayed';

    /** Dose cancelled / not applicable under the order; needs a reason. */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ADMINISTERED,
        self::STATUS_HELD,
        self::STATUS_REFUSED,
        self::STATUS_MISSED,
        self::STATUS_DELAYED,
        self::STATUS_NOT_APPLICABLE,
    ];

    /**
     * Statuses meaning the medicine actually reached the patient.
     *
     * @var list<string>
     */
    public const GIVEN_STATUSES = [self::STATUS_ADMINISTERED, self::STATUS_DELAYED];

    /**
     * Statuses that must carry a documented reason.
     *
     * @var list<string>
     */
    public const REASON_REQUIRED_STATUSES = [
        self::STATUS_HELD,
        self::STATUS_REFUSED,
        self::STATUS_MISSED,
        self::STATUS_DELAYED,
        self::STATUS_NOT_APPLICABLE,
    ];

    /**
     * The permission that authorizes recording each status. Administering,
     * holding, refusal and missed/cancelled doses are separately grantable.
     *
     * @var array<string, string>
     */
    public const STATUS_PERMISSIONS = [
        self::STATUS_ADMINISTERED => 'inpatient.medications.administer',
        self::STATUS_DELAYED => 'inpatient.medications.administer',
        self::STATUS_HELD => 'inpatient.medications.hold',
        self::STATUS_REFUSED => 'inpatient.medications.record-refusal',
        self::STATUS_MISSED => 'inpatient.medications.record-missed',
        self::STATUS_NOT_APPLICABLE => 'inpatient.medications.record-missed',
    ];

    /**
     * Allowed reasons when a dose is held rather than administered.
     *
     * @var list<string>
     */
    public const HOLD_REASONS = [
        'patient_refused',
        'patient_npo',
        'patient_unavailable',
        'clinical_hold',
        'medication_unavailable',
        'adverse_reaction_risk',
        'prescriber_instruction',
        'other',
    ];

    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'clinical_encounter_id',
        'prescription_order_id',
        'prescription_order_item_id',
        'dispensing_item_id',
        'status',
        'scheduled_at',
        'administered_at',
        'dose',
        'dose_unit',
        'route',
        'is_prn',
        'administered_by',
        'reason',
        'notes',
        'corrects_id',
        'correction_reason',
        'slot_key',
        'allergy_override_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'administered_at' => 'datetime',
            'is_prn' => 'boolean',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function prescriptionOrder(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrder::class);
    }

    public function prescriptionOrderItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrderItem::class);
    }

    public function dispensingItem(): BelongsTo
    {
        return $this->belongsTo(DispensingItem::class);
    }

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    /** The record this row supersedes (amendment chain). */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(MedicationAdministration::class, 'corrects_id');
    }
}
