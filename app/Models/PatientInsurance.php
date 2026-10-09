<?php

namespace App\Models;

use Database\Factories\PatientInsuranceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class PatientInsurance extends Model
{
    /** @use HasFactory<PatientInsuranceFactory> */
    use HasFactory;

    /**
     * NHIF authorization numbers are valid for 24 hours from verification.
     */
    public const AUTH_EXPIRATION_HOURS = 24;

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
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'is_authorization_expired',
        'authorization_expires_at',
        'authorization_hours_remaining',
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

    public function getIsAuthorizationExpiredAttribute(): bool
    {
        return $this->isAuthorizationExpired();
    }

    public function getAuthorizationExpiresAtAttribute(): ?Carbon
    {
        return $this->authorizationExpiresAt();
    }

    public function getAuthorizationHoursRemainingAttribute(): ?float
    {
        return $this->authorizationHoursRemaining();
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
     * All NHIF member/card verification attempts (append-only).
     */
    public function nhifMemberVerifications(): HasMany
    {
        return $this->hasMany(NhifMemberVerification::class)->latest();
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

    public function hasMeaningfulAuthorizationNo(): bool
    {
        $no = strtoupper(trim((string) $this->nhif_authorization_no));

        return filled($no) && ! in_array($no, ['N/A', 'NULL', 'NONE', '']);
    }

    /**
     * Scope query to active records with a verified NHIF authorization within 24 hours.
     */
    public function scopeWithValidAuthorization(Builder $query): Builder
    {
        $cutoff = now()->subHours(self::AUTH_EXPIRATION_HOURS);

        return $query->where('status', self::STATUS_ACTIVE)
            ->where('verification_status', self::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_authorization_no')
            ->whereNotIn('nhif_authorization_no', ['N/A', 'NULL', 'NONE', ''])
            ->where(function (Builder $q) use ($cutoff) {
                $q->where('verified_at', '>=', $cutoff)
                    ->orWhere(function (Builder $sub) use ($cutoff) {
                        $sub->whereNull('verified_at')
                            ->where('created_at', '>=', $cutoff);
                    });
            });
    }

    /**
     * Whether this insurance has a valid, non-expired NHIF authorization (within 24 hours).
     */
    public function hasValidAuthorization(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->verification_status !== self::VERIFICATION_VERIFIED) {
            return false;
        }

        if (! $this->hasMeaningfulAuthorizationNo()) {
            return false;
        }

        $timestamp = $this->verified_at ?? $this->created_at;

        return $timestamp !== null
            && $timestamp->greaterThanOrEqualTo(now()->subHours(self::AUTH_EXPIRATION_HOURS));
    }

    /**
     * Whether the NHIF authorization has expired (issued more than 24 hours ago, or verification failed).
     */
    public function isAuthorizationExpired(): bool
    {
        if (! $this->hasMeaningfulAuthorizationNo()) {
            return false;
        }

        if ($this->verification_status === self::VERIFICATION_EXPIRED || $this->verification_status === self::VERIFICATION_FAILED) {
            return true;
        }

        $timestamp = $this->verified_at ?? $this->created_at;

        if ($timestamp === null) {
            return true;
        }

        return $timestamp->lessThan(now()->subHours(self::AUTH_EXPIRATION_HOURS));
    }

    /**
     * The timestamp when the 24-hour authorization expires.
     */
    public function authorizationExpiresAt(): ?Carbon
    {
        if (! $this->hasValidAuthorization()) {
            return null;
        }

        $timestamp = $this->verified_at ?? $this->created_at;

        return $timestamp?->copy()->addHours(self::AUTH_EXPIRATION_HOURS);
    }

    /**
     * Approximate hours remaining before authorization expires.
     */
    public function authorizationHoursRemaining(): ?float
    {
        if (! $this->hasValidAuthorization()) {
            return null;
        }

        $expiresAt = $this->authorizationExpiresAt();
        if (! $expiresAt || $expiresAt->isPast()) {
            return 0.0;
        }

        return round(now()->diffInMinutes($expiresAt, false) / 60.0, 1);
    }
}

