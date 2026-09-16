<?php

namespace App\Services;

use App\Models\InsuranceCoverageRule;
use App\Models\InsuranceScheme;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative "which coverage rule applies to this service,
 * under this scheme, on this date" lookup (Phase 7, Step 56) — mirrors
 * ServicePriceResolver's own shape (Step 53) exactly, including its
 * whereDate() driver-portability lesson (a `date`-cast column is still
 * persisted as a full "Y-m-d H:i:s" string, so a bare string comparison
 * silently misses an exact-same-day row on SQLite).
 *
 * Unlike ServicePriceResolver, finding **no** rule is not an error — it is
 * the legitimate "this scheme has no configured coverage for this
 * service/category, so it is not covered" outcome (spec §12/§14), returned
 * as null rather than thrown. `InsuranceCoverageRuleService::create()`'s own
 * overlap prevention (mirroring ServicePricingService's) is what keeps
 * "more than one match at the same specificity level" structurally
 * impossible; if it ever happens anyway, this resolver flags it loudly
 * rather than silently picking one, the same "flag for investigation"
 * principle ServicePriceResolver/StockService::reconcile() already apply.
 */
class CoverageRuleResolver
{
    /**
     * Resolve the most specific active rule for a scheme/service/category on
     * a given date. Priority: an exact `service_id` match, then a
     * `category` match, then the scheme's own default (both null).
     *
     * @throws ValidationException
     */
    public function resolveRule(InsuranceScheme $scheme, ?Service $service, ?string $category, ?Carbon $date = null): ?InsuranceCoverageRule
    {
        $date ??= now();

        if ($service !== null) {
            $rule = $this->matchOne($scheme, ['service_id' => $service->id], $date);

            if ($rule !== null) {
                return $rule;
            }
        }

        if ($category !== null) {
            $rule = $this->matchOne($scheme, ['service_id' => null, 'category' => $category], $date);

            if ($rule !== null) {
                return $rule;
            }
        }

        return $this->matchOne($scheme, ['service_id' => null, 'category' => null], $date);
    }

    /**
     * @param  array<string, mixed>  $scopeConditions
     *
     * @throws ValidationException
     */
    protected function matchOne(InsuranceScheme $scheme, array $scopeConditions, Carbon $date): ?InsuranceCoverageRule
    {
        $dateString = $date->toDateString();

        $rules = InsuranceCoverageRule::query()
            ->where('insurance_scheme_id', $scheme->id)
            ->where($scopeConditions)
            ->where('status', InsuranceCoverageRule::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $dateString)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateString))
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        if ($rules->count() > 1) {
            // Should be structurally impossible given
            // InsuranceCoverageRuleService::create()'s own overlap
            // prevention — surfaced loudly rather than silently picking
            // one, mirroring ServicePriceResolver's identical "ambiguous
            // pricing" guard exactly.
            throw ValidationException::withMessages([
                'coverage' => "Ambiguous coverage configuration for scheme \"{$scheme->name}\" on {$dateString} — multiple overlapping rules exist and require investigation.",
            ]);
        }

        return $rules->first();
    }
}
