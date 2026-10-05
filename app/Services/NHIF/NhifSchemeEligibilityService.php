<?php

namespace App\Services\NHIF;

use App\Models\NhifSchemeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checks whether a specific NHIF service item is covered under a patient's scheme.
 * Uses the locally-synced nhif_scheme_services table (synced via NhifCatalogSyncService).
 */
class NhifSchemeEligibilityService
{
    public function isEligible(int $schemeId, string $itemCode): bool
    {
        try {
            return $this->schemeQuery()
                ->where('scheme_id', $schemeId)
                ->where('item_code', $itemCode)
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @throws ValidationException when the item is not covered under the scheme
     */
    public function assertEligible(int $schemeId, string $itemCode): void
    {
        if (! $this->isEligible($schemeId, $itemCode)) {
            throw ValidationException::withMessages([
                'nhif_item_code' => "Item {$itemCode} is not covered under the patient's NHIF scheme (scheme ID {$schemeId}).",
            ]);
        }
    }

    public function getEligibleItems(int $schemeId): Collection
    {
        return $this->schemeQuery()
            ->where('scheme_id', $schemeId)
            ->orderBy('item_name')
            ->get();
    }

    /**
     * Query from `nhif_scheme_services` (API-synced) if populated,
     * otherwise fall back to `schemes_services` (SQL dump import).
     */
    private function schemeQuery(): Builder
    {
        if (NhifSchemeService::query()->exists()) {
            return NhifSchemeService::active();
        }

        return NhifSchemeService::catalog();
    }
}
