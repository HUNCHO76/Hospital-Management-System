<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NHIF claim (folio) header — aggregates utilization into a claimable unit (Phase 8, Steps 72–73).
 */
class NhifClaim extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_READY = 'ready';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_OUTPATIENT = 'outpatient';

    public const TYPE_INPATIENT = 'inpatient';

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_CONFIRMED = 'confirmed';

    public const RECONCILIATION_DISPUTED = 'disputed';

    /** @var list<string> */
    protected $fillable = [
        'claim_number',
        'hospital_id',
        'patient_id',
        'patient_insurance_id',
        'clinical_encounter_id',
        'admission_id',
        'claim_type',
        'status',
        'scope_key',
        'folio_no',
        'folio_year',
        'folio_month',
        'nhif_claim_reference',
        'nhif_status',
        'reconciliation_status',
        'last_reconciled_at',
        'card_no',
        'facility_code',
        'attendance_date',
        'admitted_at',
        'discharged_at',
        'primary_diagnosis_code',
        'primary_diagnosis_description',
        'practitioner_id',
        'nhif_practitioner_no',
        'authorization_no',
        'currency',
        'hms_total',
        'nhif_allowable_total',
        'nhif_responsibility_total',
        'nhif_approved_total',
        'nhif_rejected_total',
        'nhif_adjusted_total',
        'patient_responsibility_total',
        'patient_paid_total',
        'patient_outstanding_total',
        'validation_summary',
        'validated_at',
        'validated_by',
        'generated_at',
        'generation_count',
        'ready_at',
        'ready_by',
        'snapshot',
        'snapshot_hash',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'hms_total' => 'decimal:2',
            'nhif_allowable_total' => 'decimal:2',
            'nhif_responsibility_total' => 'decimal:2',
            'nhif_approved_total' => 'decimal:2',
            'nhif_rejected_total' => 'decimal:2',
            'nhif_adjusted_total' => 'decimal:2',
            'patient_responsibility_total' => 'decimal:2',
            'patient_paid_total' => 'decimal:2',
            'patient_outstanding_total' => 'decimal:2',
            'attendance_date' => 'date',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'validated_at' => 'datetime',
            'generated_at' => 'datetime',
            'ready_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'validation_summary' => 'array',
            'snapshot' => 'array',
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

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NhifClaimItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(NhifClaimItem::class)->whereNull('removed_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(NhifClaimDocument::class);
    }

    public function submissionAttempts(): HasMany
    {
        return $this->hasMany(NhifClaimSubmissionAttempt::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(NhifClaimReconciliation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(NhifClaimDiscrepancy::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSubmittable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
