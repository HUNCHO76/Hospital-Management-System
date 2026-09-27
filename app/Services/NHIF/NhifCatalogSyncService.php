<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifPackage;
use App\Models\NhifPriceSyncLog;
use App\Models\NhifSchemeService;
use App\Models\NhifService;

/**
 * Synchronizes the NHIF service catalog and price lists to local tables (Phase 8, Step 68).
 *
 * Idempotent: existing records are updated in-place; records never deleted.
 */
class NhifCatalogSyncService
{
    public function __construct(
        protected NhifHttpClient $client,
    ) {}

    /**
     * Sync NHIF service (item) list for the given hospital.
     * Returns the sync log record.
     */
    public function syncServices(?Hospital $hospital = null): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'hospital_id' => $hospital?->id,
            'facility_code' => $hospital?->code,
            'sync_type' => 'items',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => auth()->id() ?? 1,
        ]);

        $result = $this->client->getServiceList($hospital?->code);

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $items = $result['data']['Items'] ?? $result['data'] ?? [];
        $counts = ['total' => count($items), 'new' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            $code = $item['ItemCode'] ?? $item['NhifItemCode'] ?? null;

            if (! $code) {
                $counts['skipped']++;

                continue;
            }

            $existing = NhifService::where('nhif_item_code', $code)->first();

            if ($existing) {
                $existing->update([
                    'nhif_item_name' => $item['ItemName'] ?? $item['NhifItemName'] ?? $existing->nhif_item_name,
                    'nhif_item_type_id' => $item['ItemTypeId'] ?? $existing->nhif_item_type_id,
                    'nhif_item_type_name' => $item['ItemTypeName'] ?? $existing->nhif_item_type_name,
                    'is_active' => true,
                    'synced_at' => now(),
                    'raw_data' => json_encode($item),
                ]);
                $counts['updated']++;
            } else {
                NhifService::create([
                    'nhif_item_code' => $code,
                    'nhif_item_name' => $item['ItemName'] ?? $item['NhifItemName'] ?? 'Unknown',
                    'nhif_item_type_id' => $item['ItemTypeId'] ?? null,
                    'nhif_item_type_name' => $item['ItemTypeName'] ?? null,
                    'is_active' => true,
                    'synced_at' => now(),
                    'raw_data' => json_encode($item),
                ]);
                $counts['new']++;
            }
        }

        $log->update([
            'status' => 'completed',
            'total_records' => $counts['total'],
            'new_records' => $counts['new'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'completed_at' => now(),
        ]);

        return $log;
    }

    /**
     * Sync NHIF packages (benefit packages/schemes).
     */
    public function syncPackages(): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'packages',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => auth()->id() ?? 1,
        ]);

        $result = $this->client->getPackageList();

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $packages = $result['data']['Packages'] ?? $result['data'] ?? [];
        $counts = ['total' => count($packages), 'new' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($packages as $pkg) {
            $code = $pkg['PackageCode'] ?? $pkg['NhifPackageCode'] ?? null;

            if (! $code) {
                $counts['skipped']++;

                continue;
            }

            $existing = NhifPackage::where('nhif_package_code', $code)->first();

            if ($existing) {
                $existing->update([
                    'nhif_package_name' => $pkg['PackageName'] ?? $existing->nhif_package_name,
                    'scheme_id' => $pkg['SchemeId'] ?? $existing->scheme_id,
                    'scheme_name' => $pkg['SchemeName'] ?? $existing->scheme_name,
                    'is_active' => true,
                    'synced_at' => now(),
                    'raw_data' => json_encode($pkg),
                ]);
                $counts['updated']++;
            } else {
                NhifPackage::create([
                    'nhif_package_code' => $code,
                    'nhif_package_name' => $pkg['PackageName'] ?? 'Unknown',
                    'scheme_id' => $pkg['SchemeId'] ?? null,
                    'scheme_name' => $pkg['SchemeName'] ?? null,
                    'is_active' => true,
                    'synced_at' => now(),
                    'raw_data' => json_encode($pkg),
                ]);
                $counts['new']++;
            }
        }

        $log->update([
            'status' => 'completed',
            'total_records' => $counts['total'],
            'new_records' => $counts['new'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'completed_at' => now(),
        ]);

        return $log;
    }

    /**
     * Sync NHIF-covered items for a specific scheme via GetAllowedItems.
     * Uses serviceTypeId=0 and empty productCode to fetch all items.
     */
    public function syncSchemeServices(int $schemeId): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'scheme_services',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => auth()->id() ?? 1,
        ]);

        $result = $this->client->getAllowedItems(0, $schemeId, '');

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $items = $result['data']['Items'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $schemeName = $result['data']['SchemeName'] ?? $result['data']['schemeName'] ?? null;
        $counts = ['total' => count($items), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        foreach ($items as $item) {
            $code = $item['ItemCode'] ?? $item['itemCode'] ?? $item['NhifItemCode'] ?? null;

            if (! $code) {
                $counts['skipped']++;

                continue;
            }

            $exists = NhifSchemeService::where('scheme_id', $schemeId)->where('item_code', $code)->exists();

            NhifSchemeService::updateOrCreate(
                ['scheme_id' => $schemeId, 'item_code' => $code],
                [
                    'scheme_name' => $schemeName,
                    'item_name' => $item['ItemName'] ?? $item['itemName'] ?? 'Unknown',
                    'item_type_id' => $item['ItemTypeId'] ?? $item['itemTypeId'] ?? null,
                    'type_name' => $item['ItemTypeName'] ?? $item['itemTypeName'] ?? null,
                    'unit_price' => $item['UnitPrice'] ?? $item['unitPrice'] ?? null,
                    'dosage' => $item['Dosage'] ?? $item['dosage'] ?? null,
                    'strength' => $item['Strength'] ?? $item['strength'] ?? null,
                    'start_date' => $item['StartDate'] ?? $item['startDate'] ?? null,
                    'end_date' => $item['EndDate'] ?? $item['endDate'] ?? null,
                    'is_active' => true,
                    'synced_at' => $now,
                    'raw_data' => $item,
                ],
            );

            if ($exists) {
                $counts['updated']++;
            } else {
                $counts['new']++;
            }
        }

        $log->update([
            'status' => 'completed',
            'total_records' => $counts['total'],
            'new_records' => $counts['new'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'completed_at' => now(),
        ]);

        return $log;
    }

    /**
     * Sync scheme services for all known NHIF schemes (from nhif_packages).
     *
     * @return array<int, NhifPriceSyncLog>
     */
    public function syncAllSchemeServices(): array
    {
        $schemeIds = NhifPackage::whereNotNull('scheme_id')
            ->distinct()
            ->pluck('scheme_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $logs = [];
        foreach ($schemeIds as $schemeId) {
            $logs[$schemeId] = $this->syncSchemeServices($schemeId);
        }

        return $logs;
    }
}
