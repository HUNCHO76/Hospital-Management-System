<?php

namespace App\Services;

use App\Models\InsuranceCoverageRule;
use App\Models\InsuranceScheme;
use App\Models\Service;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns effective-dated insurance coverage rule configuration (Phase 7, Step
 * 56) — mirrors ServicePricingService's own versioning/overlap-prevention
 * algorithm exactly (Step 53), scoped by (insurance_scheme_id, service_id,
 * category) — "the same coverage target" — instead of (service_id,
 * hospital_id). A rule is never edited or overwritten: a change is always a
 * new row; `create()` automatically closes out an existing open-ended rule
 * for the identical target the day before a new one begins, and rejects any
 * other overlap outright.
 */
class InsuranceCoverageRuleService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * The coverage rule admin list's own query builder (spec §31) — global,
     * no hospital scoping needed (see InsuranceCoverageRulePolicy's own
     * docblock for why).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<InsuranceCoverageRule>
     */
    public function query(array $filters): Builder
    {
        $query = InsuranceCoverageRule::query()->with(['insuranceScheme:id,name,code', 'service:id,code,name', 'createdBy:id,UserName']);

        if (filled($filters['insurance_scheme_id'] ?? null)) {
            $query->where('insurance_scheme_id', $filters['insurance_scheme_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('effective_from');
    }

    /**
     * @param  array{insurance_scheme_id: int, service_id?: int|null, category?: string|null, coverage_type: string, coverage_percentage?: float|string|null, fixed_copay_amount?: float|string|null, requires_preauthorization?: bool, effective_from: string, effective_to?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): InsuranceCoverageRule
    {
        $serviceId = $data['service_id'] ?? null;
        $category = $data['category'] ?? null;

        if ($serviceId !== null && $category !== null) {
            throw ValidationException::withMessages([
                'category' => 'A rule may target a specific service or a whole category, never both at once.',
            ]);
        }

        if ($category !== null && ! in_array($category, Service::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Invalid service category.']);
        }

        [$coverageType, $coveragePercentage, $fixedCopayAmount] = $this->validateCoverageShape($data);

        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? Carbon::parse($data['effective_to'])->startOfDay() : null;

        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }

        return DB::transaction(function () use ($data, $serviceId, $category, $coverageType, $coveragePercentage, $fixedCopayAmount, $from, $to, $request) {
            $scheme = InsuranceScheme::query()->whereKey($data['insurance_scheme_id'])->lockForUpdate()->firstOrFail();

            if ($serviceId !== null) {
                Service::query()->whereKey($serviceId)->firstOrFail();
            }

            // Lock every existing active rule for this exact target so two
            // administrators can never both decide "no overlap" against the
            // same stale snapshot (mirrors ServicePricingService::create()).
            $existing = InsuranceCoverageRule::query()
                ->where('insurance_scheme_id', $scheme->id)
                ->where('service_id', $serviceId)
                ->where('category', $category)
                ->where('status', InsuranceCoverageRule::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            $closeOldRuleTo = null;
            $openEnded = $existing->firstWhere('effective_to', null);

            if ($openEnded) {
                if (! $openEnded->effective_from->startOfDay()->lt($from)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'This overlaps the current open-ended rule for this coverage target.',
                    ]);
                }

                $closeOldRuleTo = $from->copy()->subDay();
            }

            foreach ($existing as $candidate) {
                if ($openEnded && $candidate->is($openEnded)) {
                    continue;
                }

                if ($this->rangesOverlap($candidate->effective_from, $candidate->effective_to, $from, $to)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'This overlaps an existing rule period for this coverage target.',
                    ]);
                }
            }

            if ($closeOldRuleTo !== null) {
                $openEnded->update(['effective_to' => $closeOldRuleTo]);
            }

            $rule = InsuranceCoverageRule::create([
                'insurance_scheme_id' => $scheme->id,
                'service_id' => $serviceId,
                'category' => $category,
                'coverage_type' => $coverageType,
                'coverage_percentage' => $coveragePercentage,
                'fixed_copay_amount' => $fixedCopayAmount,
                'requires_preauthorization' => (bool) ($data['requires_preauthorization'] ?? false),
                'status' => InsuranceCoverageRule::STATUS_ACTIVE,
                'effective_from' => $from,
                'effective_to' => $to,
                'created_by' => $request->user()->id,
            ]);

            $this->auditLog->record(
                'insurance_coverage_rule_created',
                $rule,
                null,
                $rule->only(['insurance_scheme_id', 'service_id', 'category', 'coverage_type', 'coverage_percentage', 'fixed_copay_amount', 'effective_from', 'effective_to']),
                $request,
            );

            if ($closeOldRuleTo !== null) {
                $this->auditLog->record(
                    'insurance_coverage_rule_superseded',
                    $openEnded,
                    ['effective_to' => null],
                    ['effective_to' => $closeOldRuleTo->toDateString()],
                    $request,
                );
            }

            return $rule;
        });
    }

    /**
     * Pull a mistakenly-entered rule before (or after) it takes effect —
     * never a delete; the row stays visible with its reason.
     *
     * @throws ValidationException
     */
    public function cancel(InsuranceCoverageRule $rule, string $reason, Request $request): InsuranceCoverageRule
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $rule = DB::transaction(function () use ($rule, $reason, $request) {
            $locked = InsuranceCoverageRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InsuranceCoverageRule::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'This rule has already been cancelled.']);
            }

            $locked->update([
                'status' => InsuranceCoverageRule::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            return $locked;
        });

        $this->auditLog->record(
            'insurance_coverage_rule_deactivated',
            $rule,
            ['status' => InsuranceCoverageRule::STATUS_ACTIVE],
            $rule->only(['status', 'cancellation_reason']),
            $request,
        );

        return $rule->fresh();
    }

    /**
     * Validate the coverage shape matches its declared type (spec §25):
     * PERCENTAGE requires 0-100, FIXED_COPAY requires a non-negative
     * amount, FULL/NOT_COVERED require neither value at all (never a
     * meaningless "80% on a rule that also says not covered").
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: float|null, 2: float|null}
     *
     * @throws ValidationException
     */
    protected function validateCoverageShape(array $data): array
    {
        $coverageType = $data['coverage_type'] ?? null;

        if (! in_array($coverageType, InsuranceCoverageRule::TYPES, true)) {
            throw ValidationException::withMessages(['coverage_type' => 'Invalid coverage type.']);
        }

        $percentage = null;
        $fixedAmount = null;

        if ($coverageType === InsuranceCoverageRule::TYPE_PERCENTAGE) {
            $percentage = $data['coverage_percentage'] ?? null;

            if (! is_numeric($percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
                throw ValidationException::withMessages(['coverage_percentage' => 'The coverage percentage must be between 0 and 100.']);
            }

            $percentage = round((float) $percentage, 2);
        }

        if ($coverageType === InsuranceCoverageRule::TYPE_FIXED_COPAY) {
            $fixedAmount = $this->validateMoney($data['fixed_copay_amount'] ?? null, 'fixed_copay_amount', allowZero: true);
        }

        return [$coverageType, $percentage, $fixedAmount];
    }

    /**
     * Whether two [from, to] ranges (either `to` possibly null/open-ended)
     * overlap at all. Mirrors ServicePricingService::rangesOverlap()
     * exactly.
     */
    protected function rangesOverlap(Carbon $aFrom, ?Carbon $aTo, Carbon $bFrom, ?Carbon $bTo): bool
    {
        $aEndsBeforeBStarts = $aTo !== null && $aTo->startOfDay()->lt($bFrom);
        $bEndsBeforeAStarts = $bTo !== null && $bTo->startOfDay()->lt($aFrom);

        return ! $aEndsBeforeBStarts && ! $bEndsBeforeAStarts;
    }
}
