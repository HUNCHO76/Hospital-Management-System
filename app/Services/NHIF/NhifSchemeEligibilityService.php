<?php

namespace App\Services\NHIF;

use App\Models\NhifSchemeService;
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
        return NhifSchemeService::active()
            ->where('scheme_id', $schemeId)
            ->where('item_code', $itemCode)
            ->exists();
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
        return NhifSchemeService::active()
            ->where('scheme_id', $schemeId)
            ->orderBy('item_name')
            ->get();
    }
}
