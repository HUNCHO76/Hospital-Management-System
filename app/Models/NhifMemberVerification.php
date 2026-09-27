<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One NHIF member/card verification attempt for a PatientInsurance record
 * (Phase 8, Step 65). Append-only — records are never edited after creation.
 */
class NhifMemberVerification extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ERROR = 'error';

    public const STATUS_LOOKUP_FOUND = 'lookup_found';

    public const STATUS_VERIFICATION_FAILED = 'verification_failed';

    public const TYPE_NIN = 'nin';

    public const TYPE_CARD_NUMBER = 'CardNo';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'patient_insurance_id',
        'identifier_type',
        'identifier_value',
        'nhif_operation',
        'verification_status',
        'nhif_member_name',
        'nhif_card_no',
        'nhif_expiry_date',
        'nhif_membership_no',
        'nhif_plan_name',
        'nhif_authorization_no',
        'nhif_scheme_id',
        'nhif_facility_code',
        'verify_card_request',
        'verify_card_response',
        'http_status',
        'error_message',
        'correlation_id',
        'request_data',
        'response_data',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'response_data' => 'array',
            'verify_card_request' => 'array',
            'verify_card_response' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isSuccessful(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED;
    }
}
