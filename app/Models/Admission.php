<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\AdmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HMS hospital admission record (Phase 8, Step 70 schema; Phase 9, Step 71
 * workflow). Deliberately reuses the existing Service Catalog/ServicePrice/
 * ChargeCaptureService stack for billing (`service_id`) rather than a
 * hand-typed rate — see AdmissionService/ChargeCaptureService::
 * captureFromAdmission() for how `charges_per_day` gets its value.
 */
class Admission extends Model
{
    /** @use HasFactory<AdmissionFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'ADM';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCHARGED = 'discharged';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TRANSFERRED = 'transferred';

    /**
     * Allowed forward transitions per status — the single source of truth
     * for what can follow what. Mirrors ClinicalEncounter::TRANSITIONS.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_UNDER_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PENDING],
        self::STATUS_APPROVED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_DISCHARGED, self::STATUS_TRANSFERRED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_DISCHARGED => [],
        self::STATUS_TRANSFERRED => [],
    ];

    /**
     * The manner/reason an admission was initiated — a small, fixed
     * administrative vocabulary, not something a hospital reconfigures.
     * Mirrors Appointment::TYPES/ReceptionVisit::TYPES/
     * RadiologyProcedure::MODALITIES's own precedent: a plain validated
     * list, never a database table, for exactly this kind of vocabulary.
     *
     * @var list<string>
     */
    public const ADMISSION_TYPES = ['emergency', 'elective', 'referral', 'transfer', 'other'];

    /**
     * @var list<string>
     */
    public const DISCHARGE_TYPES = ['routine', 'against_medical_advice', 'transferred_out', 'deceased', 'other'];

    /**
     * Map internal discharge types to NHIF discharge type IDs.
     * 1 = Routine, 2 = AMA, 3 = Transfer, 4 = Abscond, 5 = Referral, 6 = Death
     *
     * @var array<string, int>
     */
    public const NHIF_DISCHARGE_TYPE_MAP = [
        'routine' => 1,
        'against_medical_advice' => 2,
        'transferred_out' => 3,
        'deceased' => 6,
        'other' => 1,
    ];

    /**
     * Possible clinical status values recorded by nursing/clinical staff
     * during an active admission — distinct from the administrative status
     * column (pending/active/discharged).
     *
     * @var list<string>
     */
    public const CLINICAL_STATUSES = [
        'stable',
        'under_observation',
        'improving',
        'deteriorating',
        'critical',
    ];

    /** @var list<string> */
    protected $fillable = [
        'admission_number',
        'hospital_id',
        'patient_id',
        'clinical_encounter_id',
        'department_id',
        'patient_insurance_id',
        'admission_type',
        'ward_type',
        'room_type',
        'diagnosis_at_admission',
        'practitioner_id',
        'service_id',
        'charges_per_day',
        'notes',
        'status',
        'clinical_status',
        'clinical_status_updated_by',
        'clinical_status_updated_at',
        'admitted_at',
        'discharged_at',
        'discharge_type',
        'discharge_reason',
        'diagnosis_at_discharge',
        'referred_to_facility_code',
        'cancellation_reason',
        'admitted_by',
        'discharged_by',
        'created_by',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'charges_per_day' => 'decimal:2',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'clinical_status_updated_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by');
    }

    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function clinicalStatusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinical_status_updated_by');
    }

    /**
     * Inpatient clinical encounters opened against this admission — multiple
     * may exist over the course of the stay (daily rounds each create a
     * new progress context on the same encounter, but a new encounter may be
     * opened by a specialist consultation or a transfer to another ward).
     */
    public function inpatientEncounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class, 'admission_id')
            ->orderBy('created_at');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class, 'admission_id')
            ->orderBy('created_at');
    }

    /**
     * MAR records for this admission — includes all statuses (administered,
     * held, refused, missed). Never used to derive stock levels; stock was
     * already deducted at dispensing.
     */
    public function medicationAdministrations(): HasMany
    {
        return $this->hasMany(MedicationAdministration::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AdmissionTransfer::class);
    }

    /**
     * All discharge document versions generated for this admission, newest first.
     */
    public function dischargeDocuments(): HasMany
    {
        return $this->hasMany(DischargeDocument::class)->latest('version');
    }

    /**
     * The current (active) discharge document, if one has been generated.
     */
    public function currentDischargeDocument(): HasOne
    {
        return $this->hasOne(DischargeDocument::class)
            ->where('status', DischargeDocument::STATUS_ACTIVE)
            ->latestOfMany('version');
    }

    public function nhifAdmission(): HasOne
    {
        return $this->hasOne(NhifAdmission::class);
    }

    public function nhifClaims(): HasMany
    {
        return $this->hasMany(NhifClaim::class);
    }

    public function maternityProfiles(): HasMany
    {
        return $this->hasMany(MaternityProfile::class);
    }

    public function maternityProfile(): HasOne
    {
        return $this->hasOne(MaternityProfile::class)->latestOfMany();
    }

    public function partographEntries(): HasMany
    {
        return $this->hasMany(PartographEntry::class)->orderBy('recorded_at');
    }

    public function deliveryRecords(): HasMany
    {
        return $this->hasMany(DeliveryRecord::class);
    }

    public function procedureOrders(): HasMany
    {
        return $this->hasMany(ProcedureOrder::class)->orderByDesc('created_at');
    }

    public function surgicalCases(): HasMany
    {
        return $this->hasMany(SurgicalCase::class)->orderByDesc('created_at');
    }

    public function bedsideProcedureEvents(): HasMany
    {
        return $this->hasMany(BedsideProcedureEvent::class)->orderByDesc('performed_at');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isUnderReview(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDischarged(): bool
    {
        return $this->status === self::STATUS_DISCHARGED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTransferred(): bool
    {
        return $this->status === self::STATUS_TRANSFERRED;
    }

    /** Returns true when the admission is still in a pre-admission (cancellable) state. */
    public function isPreAdmission(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_UNDER_REVIEW,
            self::STATUS_APPROVED,
        ], true);
    }

    protected static function booted(): void
    {
        static::creating(function (self $admission): void {
            if (empty($admission->admission_number)) {
                $admission->admission_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'admission_number');
            }
        });
    }
}
