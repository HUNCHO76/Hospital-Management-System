<?php

namespace App\Services\NHIF;

use App\Models\NhifPackagePrice;
use App\Models\NhifSchemeService;
use App\Models\PatientInsurance;

/**
 * Resolves NHIF unit prices for items via the OCS GetPricePackageByItemCode
 * API, falling back to the locally-synced NhifPackagePrice catalog, and
 * finally to a caller-provided default.
 *
 * Every NHIF RequestServices payload must use the authoritative NHIF price
 * rather than the HMS charge price — NHIF rejects or flags submissions
 * where the unit price doesn't match its own catalog.
 */
class NhifPriceResolutionService
{
    public function __construct(protected NhifHttpClient $client) {}

    /**
     * Resolve the NHIF-authoritative unit price for an item.
     *
     * Resolution order:
     *   1. OCS GetPricePackageByItemCode (live API — most authoritative)
     *   2. NhifPackagePrice local table (synced catalog — offline fallback)
     *   3. $fallbackPrice (HMS charge price — last resort)
     *
     * Also returns MaximumQuantity when available from the API response.
     *
     * @return array{unit_price: float, maximum_quantity: int|null, source: string}
     */
    public function resolve(
        PatientInsurance $insurance,
        string $nhifItemCode,
        float $fallbackPrice,
    ): array {
        $facilityCode = $insurance->nhif_facility_code;

        if (filled($facilityCode)) {
            $result = $this->client->getPricePackageByItemCode($facilityCode, $nhifItemCode);

            if ($result['success'] && filled($result['data'])) {
                $data = $result['data'];
                if (isset($data[0]) && is_array($data[0])) {
                    $data = $data[0];
                }

                if (isset($data['UnitPrice'])) {
                    return [
                        'unit_price' => (float) $data['UnitPrice'],
                        'maximum_quantity' => isset($data['MaximumQuantity']) ? (int) $data['MaximumQuantity'] : null,
                        'source' => 'api',
                    ];
                }
            }
        }

        $schemeId = $insurance->nhif_scheme_id;
        if ($schemeId) {
            $localPrice = NhifPackagePrice::resolveUnitPrice($nhifItemCode, (int) $schemeId);
            if ($localPrice !== null) {
                $maxQty = NhifPackagePrice::query()
                    ->where('item_code', $nhifItemCode)
                    ->where('scheme_id', $schemeId)
                    ->orderByDesc('id')
                    ->value('maximum_quantity');

                return [
                    'unit_price' => $localPrice,
                    'maximum_quantity' => $maxQty !== null ? (int) $maxQty : null,
                    'source' => 'local_catalog',
                ];
            }
        }

        // Tier 3: NhifSchemeService catalog (unit_price from API-synced table)
        $schemeServicePrice = NhifSchemeService::query()
            ->where('item_code', $nhifItemCode)
            ->when($schemeId, fn ($q) => $q->where('scheme_id', $schemeId))
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->orderByDesc('id')
            ->value('unit_price');

        if ($schemeServicePrice !== null) {
            return [
                'unit_price' => (float) $schemeServicePrice,
                'maximum_quantity' => null,
                'source' => 'scheme_catalog',
            ];
        }

        return [
            'unit_price' => $fallbackPrice,
            'maximum_quantity' => null,
            'source' => 'charge_fallback',
        ];
    }
}
