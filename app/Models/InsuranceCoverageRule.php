<?php

namespace App\Models;

use Database\Factories\InsuranceCoverageRuleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A coverage rule an insurance scheme applies to a service (or a whole
 * service category, or nothing — its own default) — see the
 * create_insurance_coverage_rules_table migration's own docblock for the
 * full reasoning behind its shape (Phase 7, Step 56).
 */
class InsuranceCoverageRule extends Model
{
    /** @use HasFactory<InsuranceCoverageRuleFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
    ];

    /**
     * PERCENTAGE: insurer pays coverage_percentage of the eligible amount,
     * patient pays the rest. FIXED_COPAY: patient pays a flat
     * fixed_copay_amount (capped at the eligible amount), insurer pays the
     * rest. FULL: insurer pays 100%, patient pays nothing. NOT_COVERED:
     * patient pays 100% — a deliberate, configured "this plan does not
     * cover this" rule, distinct from no rule existing at all (spec §14's
     * own "Covered/Partially Covered/Not Covered" distinction).
     */
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED_COPAY = 'fixed_copay';

    public const TYPE_FULL = 'full';

    public const TYPE_NOT_COVERED = 'not_covered';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED_COPAY,
        self::TYPE_FULL,
        self::TYPE_NOT_COVERED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'insurance_scheme_id',
        'service_id',
        'category',
        'coverage_type',
        'coverage_percentage',
        'fixed_copay_amount',
        'requires_preauthorization',
        'status',
        'effective_from',
        'effective_to',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coverage_percentage' => 'decimal:2',
            'fixed_copay_amount' => 'decimal:2',
            'requires_preauthorization' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Whether this rule is currently in effect for the given date — active
     * status, and the date falls within [effective_from, effective_to]
     * inclusive, an open effective_to meaning "current, ongoing." Mirrors
     * ServicePrice::coversDate() exactly, including its own whereDate()
     * driver-portability lesson applied wherever this is queried instead of
     * compared in PHP.
     */
    public function coversDate(DateTimeInterface $date): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $date = Carbon::parse($date)->startOfDay();

        if ($this->effective_from->startOfDay()->gt($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->startOfDay()->gte($date);
    }

    /**
     * The insurance scheme this rule belongs to.
     */
    public function insuranceScheme(): BelongsTo
    {
        return $this->belongsTo(InsuranceScheme::class);
    }

    /**
     * The specific billing service this rule overrides coverage for, if any
     * (null means either a category-level or scheme-default rule).
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The user who created this rule.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who cancelled this rule, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
