<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An NHIF preauthorization request tied to a clinical service source
 * (Phase 8, Step 69). InsuranceBillingService uses approved records to
 * apply NHIF-approved amounts; NHIFPreauthorizationService gates service
 * execution until approved.
 */
class NhifPreauthorization extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'hospital_id',
        'patient_insurance_id',
        'source_type',
        'source_id',
        'authorization_no',
        'status',
        'approved_amount',
        'rejection_reason',
        'request_data',
        'response_data',
        'submitted_at',
        'resolved_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'approved_amount' => 'decimal:2',
            'request_data' => 'array',
            'response_data' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(NhifPreauthorizationDocument::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
