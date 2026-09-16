<?php

namespace App\Models;

use Database\Factories\ChargeInsuranceAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The financial responsibility split computed for one Charge — see the
 * create_charge_insurance_allocations_table migration's own docblock for
 * the full reasoning behind its shape (Phase 7, Step 56).
 */
class ChargeInsuranceAllocation extends Model
{
    /** @use HasFactory<ChargeInsuranceAllocationFactory> */
    use HasFactory;

    /**
     * FULLY_COVERED: patient_responsibility is zero. PARTIALLY_COVERED: both
     * covered_amount and patient_responsibility are positive.
     * NOT_COVERED: a matched rule explicitly assigns nothing to the insurer
     * (or no rule matched at all — see InsuranceBillingService's own
     * docblock), patient pays the full eligible amount. REJECTED: the
     * patient had no eligible coverage at calculation time at all (no
     * active/valid PatientInsurance) — distinct from NOT_COVERED so
     * reporting can tell "this plan doesn't cover this service" apart from
     * "this patient had no usable coverage to begin with," even though the
     * patient owes the full amount either way (spec §26).
     */
    public const STATUS_FULLY_COVERED = 'fully_covered';

    public const STATUS_PARTIALLY_COVERED = 'partially_covered';

    public const STATUS_NOT_COVERED = 'not_covered';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_FULLY_COVERED,
        self::STATUS_PARTIALLY_COVERED,
        self::STATUS_NOT_COVERED,
        self::STATUS_REJECTED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'hospital_id',
        'patient_id',
        'invoice_id',
        'charge_id',
        'patient_insurance_id',
        'insurance_coverage_rule_id',
        'coverage_type_applied',
        'coverage_percentage_applied',
        'copay_amount_applied',
        'eligible_amount',
        'covered_amount',
        'patient_responsibility',
        'currency',
        'status',
        'rejection_reason',
        'calculated_at',
        'calculated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coverage_percentage_applied' => 'decimal:2',
            'copay_amount_applied' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'covered_amount' => 'decimal:2',
            'patient_responsibility' => 'decimal:2',
            'calculated_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /**
     * The patient's coverage record this allocation was calculated against.
     */
    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    /**
     * The coverage rule actually applied, if any (null = no rule matched,
     * treated as not covered — see the migration's own docblock).
     */
    public function insuranceCoverageRule(): BelongsTo
    {
        return $this->belongsTo(InsuranceCoverageRule::class);
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
