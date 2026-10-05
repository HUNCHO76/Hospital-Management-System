<?php

namespace App\Models;

use Database\Factories\PatientInsuranceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientInsurance extends Model
{
    /** @use HasFactory<PatientInsuranceFactory> */
    use HasFactory;

    /**
     * Status values recognized for a patient insurance record.
     *
     * STATUS_EXPIRED is an explicit, administrator-set state — distinct from
     * a record whose effective_to date has simply passed while status still
     * reads "active". The latter is never rewritten automatically; it is
     * only reflected in how the record is displayed (see the frontend
     * insurance-status helper).
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Internal verification status values. These are set manually or by a
     * future integration layer — no live external verification happens here.
     */
    public const VERIFICATION_PENDING = 'pending';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_FAILED = 'failed';

    public const VERIFICATION_EXPIRED = 'expired';

    public const VERIFICATION_STATUSES = [
        self::VERIFICATION_PENDING,
        self::VERIFICATION_VERIFIED,
        self::VERIFICATION_FAILED,
        self::VERIFICATION_EXPIRED,
    ];

    /**
     * The principal member's relationship to the patient, when the patient
     * is a dependant on someone else's membership.
     */
    public const RELATIONSHIPS = ['self', 'spouse', 'child', 'parent', 'other'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'insurance_provider_id',
        'insurance_scheme_id',
        'membership_number',
        'policy_number',
        'card_number',
        'nhif_authorization_no',
        'nhif_scheme_id',
        'nhif_facility_code',
        'nhif_pf_number',
        'principal_member_name',
        'relationship',
        'effective_from',
        'effective_to',
        'is_primary',
        'status',
        'verification_status',
        'verified_at',
        'nhif_eligibility_status',
        'nhif_coverage_percent',
        'nhif_eligibility_checked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'nhif_coverage_percent' => 'decimal:2',
            'nhif_eligibility_checked_at' => 'datetime',
        ];
    }

    /**
     * The patient this insurance record belongs to.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The insurance provider for this record.
     */
    public function insuranceProvider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class);
    }

    /**
     * The insurance scheme for this record, if applicable.
     */
    public function insuranceScheme(): BelongsTo
    {
        return $this->belongsTo(InsuranceScheme::class);
    }

    /**
     * The most-recent NHIF member/card verification attempt.
     */
    public function latestNhifVerification(): HasOne
    {
        return $this->hasOne(NhifMemberVerification::class)->latestOfMany();
    }

    /**
     * The most-recent NHIF eligibility check for this insurance record.
     */
    public function latestNhifEligibility(): HasOne
    {
        return $this->hasOne(NhifEligibilityRecord::class)->latestOfMany();
    }

    /**
     * All NHIF eligibility check history (append-only).
     */
    public function nhifEligibilityRecords(): HasMany
    {
        return $this->hasMany(NhifEligibilityRecord::class)->latest();
    }

    /** Whether the most-recent NHIF eligibility check says the member is eligible. */
    public function isNhifEligible(): bool
    {
        return $this->nhif_eligibility_status === NhifEligibilityRecord::STATUS_ELIGIBLE;
    }

    /** Whether NHIF covers 100% of charges (no patient copayment). */
    public function isFullyCoveredByNhif(): bool
    {
        return $this->isNhifEligible()
            && $this->nhif_coverage_percent !== null
            && (float) $this->nhif_coverage_percent >= 100.0;
    }
}
