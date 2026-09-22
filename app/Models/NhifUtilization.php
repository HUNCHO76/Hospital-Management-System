<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * NHIF clinical service utilization record — one per performed service (Phase 8, Step 71).
 * Idempotency key prevents duplicate submissions.
 */
class NhifUtilization extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXCLUDED = 'excluded';

    public const MODULE_LAB = 'lab';

    public const MODULE_RADIOLOGY = 'radiology';

    public const MODULE_PHARMACY = 'pharmacy';

    public const MODULE_CONSULTATION = 'consultation';

    public const MODULE_PROCEDURE = 'procedure';

    /** @var list<string> */
    protected $fillable = [
        'utilization_number',
        'idempotency_key',
        'hospital_id',
        'patient_id',
        'patient_insurance_id',
        'clinical_encounter_id',
        'admission_id',
        'charge_id',
        'nhif_service_mapping_id',
        'nhif_preauthorization_id',
        'module',
        'source_type',
        'source_id',
        'service_instance_type',
        'service_instance_id',
        'nhif_item_code',
        'nhif_item_name',
        'quantity',
        'hms_unit_price',
        'hms_amount',
        'nhif_allowable_unit_price',
        'nhif_approved_amount',
        'currency',
        'service_date',
        'nhif_authorization_no',
        'status',
        'pending_reason',
        'outcome_uncertain',
        'attempt_count',
        'nhif_reference',
        'nhif_status_code',
        'rejection_reason',
        'error_message',
        'http_status',
        'raw_request',
        'raw_response',
        'last_attempt_at',
        'submitted_at',
        'resolved_at',
        'created_by',
        'submitted_by',
        'reconciled_by',
        'reconciled_at',
        'reconciliation_notes',
    ];

    protected function casts(): array
    {
        return [
            'hms_unit_price' => 'decimal:2',
            'hms_amount' => 'decimal:2',
            'nhif_allowable_unit_price' => 'decimal:2',
            'nhif_approved_amount' => 'decimal:2',
            'outcome_uncertain' => 'boolean',
            'service_date' => 'datetime',
            'last_attempt_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reconciled_at' => 'datetime',
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

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function nhifServiceMapping(): BelongsTo
    {
        return $this->belongsTo(NhifServiceMapping::class);
    }

    public function nhifPreauthorization(): BelongsTo
    {
        return $this->belongsTo(NhifPreauthorization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claimItem(): HasOne
    {
        return $this->hasOne(NhifClaimItem::class);
    }

    public function isSubmittable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
    }
}
