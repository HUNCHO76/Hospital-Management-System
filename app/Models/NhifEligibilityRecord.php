<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One NHIF eligibility check for a PatientInsurance record
 * (Phase 8, Step 66). Append-only — records are never edited after creation.
 */
class NhifEligibilityRecord extends Model
{
    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_INELIGIBLE = 'ineligible';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'patient_insurance_id',
        'card_no',
        'nhif_operation',
        'eligibility_status',
        'nhif_coverage_percent',
        'nhif_patient_responsibility_percent',
        'nhif_valid_from',
        'nhif_valid_to',
        'nhif_remarks',
        'http_status',
        'error_message',
        'correlation_id',
        'request_data',
        'response_data',
        'eligibility_checked_at',
        'checked_by',
    ];

    protected function casts(): array
    {
        return [
            'nhif_coverage_percent' => 'decimal:2',
            'nhif_patient_responsibility_percent' => 'decimal:2',
            'nhif_valid_from' => 'date',
            'nhif_valid_to' => 'date',
            'request_data' => 'array',
            'response_data' => 'array',
            'eligibility_checked_at' => 'datetime',
        ];
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function isEligible(): bool
    {
        return $this->eligibility_status === self::STATUS_ELIGIBLE;
    }
}
