<?php

namespace App\Services\NHIF;

use App\Models\NhifExcludedService;
use App\Models\NhifServiceMapping;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * Resolves NHIF service context for a given HMS service and hospital (Phase 8, Step 68).
 *
 * Called by InsuranceBillingService before adding a charge to determine:
 * 1. Whether the service is excluded from NHIF coverage at this facility.
 * 2. What the NHIF-allowable capped amount is (null = no cap, bill HMS price).
 */
class NHIFServiceMappingService
{
    /**
     * Resolve NHIF service context for a given service and facility on a date.
     *
     * Priority:
     *  1. Explicitly excluded via nhif_excluded_services → excluded=true.
     *  2. Active NhifServiceMapping with coverage_status=excluded → excluded=true.
     *  3. Active NhifServiceMapping with coverage → excluded=false, apply allowable_amount.
     *  4. No mapping → excluded=false, allowable_amount=null (bill HMS price, flag as unmapped).
     *
     * @return array{excluded: bool, allowable_amount: float|null, mapping: NhifServiceMapping|null}
     */
    public function resolveForService(?Service $service, ?int $hospitalId, Carbon $date): array
    {
        if (! $service || ! $hospitalId) {
            return ['excluded' => false, 'allowable_amount' => null, 'mapping' => null];
        }

        // Check explicit facility-level exclusion list first.
        $excluded = NhifExcludedService::where('hospital_id', $hospitalId)
            ->where('is_active', true)
            ->where(function ($q) use ($service) {
                $q->whereNull('nhif_item_code')
                    ->orWhere('nhif_item_code', '');
            })
            ->exists();

        // Try to resolve via service mapping.
        $mapping = NhifServiceMapping::where('service_id', $service->id)
            ->where('hospital_id', $hospitalId)
            ->where('is_active', true)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();

        if (! $mapping) {
            return ['excluded' => $excluded, 'allowable_amount' => null, 'mapping' => null];
        }

        if ($mapping->isExcluded()) {
            return ['excluded' => true, 'allowable_amount' => null, 'mapping' => $mapping];
        }

        // Also check the excluded services list by nhif_item_code.
        $excludedByCode = NhifExcludedService::where('hospital_id', $hospitalId)
            ->where('nhif_item_code', $mapping->nhif_item_code)
            ->where('is_active', true)
            ->exists();

        if ($excludedByCode) {
            return ['excluded' => true, 'allowable_amount' => null, 'mapping' => $mapping];
        }

        $allowable = $mapping->currentAllowableAmount();

        return ['excluded' => false, 'allowable_amount' => $allowable, 'mapping' => $mapping];
    }
}
