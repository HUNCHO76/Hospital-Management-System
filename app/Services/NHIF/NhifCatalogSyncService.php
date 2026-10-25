<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifDisease;
use App\Models\NhifItemType;
use App\Models\NhifPackage;
use App\Models\NhifPackagePrice;
use App\Models\NhifPriceSyncLog;
use App\Models\NhifSchemeService;
use App\Models\NhifService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
            'performed_by' => $this->resolvePerformedBy(),
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
            'performed_by' => $this->resolvePerformedBy(),
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
            'performed_by' => $this->resolvePerformedBy(),
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

        DB::transaction(function () use ($items, $schemeId, $schemeName, $now, &$counts): void {
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
        });

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
     * Sync NHIF disease reference from OCS GetDiseases.
     * Scheme-less: all diseases apply to all schemes.
     */
    public function syncDiseases(): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'diseases',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => $this->resolvePerformedBy(),
        ]);

        $result = $this->client->getOcsDiseases();

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $diseases = $result['data']['Diseases'] ?? $result['data']['diseases'] ?? $result['data'] ?? [];
        if (! is_array($diseases)) {
            $diseases = [];
        }

        $counts = ['total' => count($diseases), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        foreach ($diseases as $item) {
            $code = $item['DiseaseCode'] ?? $item['diseaseCode'] ?? null;
            $icdVersion = $item['ICDVersionCode'] ?? $item['icdVersionCode'] ?? $item['IcdVersionCode'] ?? null;

            if (! $code) {
                $counts['skipped']++;

                continue;
            }

            $exists = NhifDisease::where('disease_code', $code)
                ->where('icd_version_code', $icdVersion ?? '')
                ->exists();

            NhifDisease::updateOrCreate(
                ['disease_code' => $code, 'icd_version_code' => $icdVersion ?? ''],
                [
                    'disease_name' => $item['DiseaseName'] ?? $item['diseaseName'] ?? 'Unknown',
                    'synced_at' => $now,
                    'raw_data' => $item,
                ],
            );

            $exists ? $counts['updated']++ : $counts['new']++;
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
     * Sync NHIF package price catalog from GetPricePackage.
     * Keyed by (item_code, scheme_id, package_id) — idempotent via updateOrCreate.
     * Never deletes existing rows when the API call fails.
     */
    public function syncPackagePrices(?int $schemeId = null, ?int $packageId = null): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'package_prices',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => $this->resolvePerformedBy(),
        ]);

        $result = $this->client->getPricePackage($schemeId, $packageId);

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $items = $result['data']['PricePackages'] ?? $result['data']['Items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $counts = ['total' => count($items), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        foreach ($items as $item) {
            $code = $item['ItemCode'] ?? $item['item_code'] ?? null;

            if (! $code) {
                $counts['skipped']++;

                continue;
            }

            $resolvedSchemeId = $item['SchemeID'] ?? $item['scheme_id'] ?? $schemeId;
            $resolvedPackageId = $item['PackageID'] ?? $item['package_id'] ?? $packageId;

            $exists = NhifPackagePrice::query()
                ->where('item_code', $code)
                ->where('scheme_id', $resolvedSchemeId)
                ->where('package_id', $resolvedPackageId)
                ->exists();

            NhifPackagePrice::updateOrCreate(
                [
                    'item_code' => $code,
                    'scheme_id' => $resolvedSchemeId,
                    'package_id' => $resolvedPackageId,
                ],
                [
                    'price_code' => $item['PriceCode'] ?? $item['price_code'] ?? null,
                    'item_type_id' => $item['ItemTypeID'] ?? $item['item_type_id'] ?? null,
                    'item_name' => $item['ItemName'] ?? $item['item_name'] ?? null,
                    'strength' => $item['Strength'] ?? $item['strength'] ?? null,
                    'dosage' => $item['Dosage'] ?? $item['dosage'] ?? null,
                    'unit_price' => (float) ($item['UnitPrice'] ?? $item['unit_price'] ?? 0),
                    'is_restricted' => (bool) ($item['IsRestricted'] ?? false),
                    'is_over_the_counter' => (bool) ($item['IsOverTheCounter'] ?? false),
                    'maximum_quantity' => $item['MaximumQuantity'] ?? null,
                    'maximum_quantity_out_patient' => $item['MaximumQuantityOutPatient'] ?? null,
                    'maximum_quantity_in_patient' => $item['MaximumQuantityInPatient'] ?? null,
                    'has_co_payment' => (bool) ($item['HasCoPayment'] ?? false),
                    'synced_at' => $now,
                    'raw_data' => $item,
                ],
            );

            $exists ? $counts['updated']++ : $counts['new']++;
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

    /**
     * Sync NHIF item types (categories) from OCS GetItemTypes.
     * Idempotent: existing records are updated in-place; records never deleted.
     */
    public function syncItemTypes(): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'item_types',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => $this->resolvePerformedBy(),
        ]);

        $result = $this->client->getOcsItemTypes();

        if (! $result['success']) {
            $log->update([
                'status' => 'failed',
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            return $log;
        }

        $items = $result['data']['ItemTypes'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $counts = ['total' => count($items), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        DB::transaction(function () use ($items, $now, &$counts): void {
            foreach ($items as $item) {
                $id = $item['ItemTypeID'] ?? $item['itemTypeId'] ?? null;

                if ($id === null || $id === '') {
                    $counts['skipped']++;

                    continue;
                }

                $id = (int) $id;
                $exists = NhifItemType::where('item_type_id', $id)->exists();

                $dateCreated = ! empty($item['DateCreated']) ? rescue(fn () => Carbon::parse($item['DateCreated']), null, false) : null;
                $lastModified = ! empty($item['LastModified']) ? rescue(fn () => Carbon::parse($item['LastModified']), null, false) : null;

                NhifItemType::updateOrCreate(
                    ['item_type_id' => $id],
                    [
                        'type_name' => $item['TypeName'] ?? $item['typeName'] ?? 'Unknown',
                        'alias' => $item['Alias'] ?? $item['alias'] ?? null,
                        'item_group' => $item['ItemGroup'] ?? $item['itemGroup'] ?? null,
                        'display_item' => (bool) ($item['DisplayItem'] ?? $item['displayItem'] ?? true),
                        'nhif_date_created' => $dateCreated,
                        'nhif_last_modified' => $lastModified,
                        'synced_at' => $now,
                        'raw_data' => $item,
                    ]
                );

                $exists ? $counts['updated']++ : $counts['new']++;
            }
        });

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
     * Sync NHIF national item catalog from OCS GetItems.
     * Idempotent: existing records are updated in-place; inactive records are marked inactive but never deleted.
     */
    public function syncItems(): NhifPriceSyncLog
    {
        $log = NhifPriceSyncLog::create([
            'sync_type' => 'items',
            'status' => 'started',
            'started_at' => now(),
            'performed_by' => $this->resolvePerformedBy(),
        ]);

        $result = $this->client->getOcsItems();

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

        $itemTypes = NhifItemType::pluck('type_name', 'item_type_id')->all();
        $existingMap = NhifService::pluck('id', 'nhif_item_code')->all();
        $counts = ['total' => count($items), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        DB::transaction(function () use ($items, $itemTypes, $existingMap, $now, &$counts): void {
            foreach ($items as $item) {
                $code = $item['ItemCode'] ?? $item['itemCode'] ?? $item['NhifItemCode'] ?? null;

                if (! $code) {
                    $counts['skipped']++;

                    continue;
                }

                $code = trim((string) $code);
                $exists = isset($existingMap[$code]);

                $itemTypeId = isset($item['ItemTypeID']) ? (int) $item['ItemTypeID'] : (isset($item['ItemTypeId']) ? (int) $item['ItemTypeId'] : null);
                $serviceTypeId = isset($item['ServiceTypeID']) ? (int) $item['ServiceTypeID'] : (isset($item['ServiceTypeId']) ? (int) $item['ServiceTypeId'] : null);
                $typeName = $item['ItemTypeName'] ?? ($itemTypeId ? ($itemTypes[$itemTypeId] ?? null) : null);

                $dateCreated = ! empty($item['DateCreated']) ? rescue(fn () => Carbon::parse($item['DateCreated']), null, false) : null;
                $lastModified = ! empty($item['LastModified']) ? rescue(fn () => Carbon::parse($item['LastModified']), null, false) : null;

                NhifService::updateOrCreate(
                    ['nhif_item_code' => $code],
                    [
                        'nhif_item_name' => $item['ItemName'] ?? $item['itemName'] ?? 'Unknown',
                        'nhif_item_type_id' => $itemTypeId,
                        'nhif_item_type_name' => $typeName,
                        'service_type_id' => $serviceTypeId,
                        'sub_group' => $item['SubGroup'] ?? $item['subGroup'] ?? null,
                        'strength' => $item['Strength'] ?? $item['strength'] ?? null,
                        'dosage' => $item['Dosage'] ?? $item['dosage'] ?? null,
                        'calculated_per_day' => (bool) ($item['CalculatedPerDay'] ?? $item['calculatedPerDay'] ?? false),
                        'is_restricted' => (bool) ($item['IsRestricted'] ?? $item['isRestricted'] ?? false),
                        'service_interval' => (int) ($item['ServiceInterval'] ?? $item['serviceInterval'] ?? 0),
                        'type_of_interval' => $item['TypeOfInterval'] ?? $item['typeOfInterval'] ?? null,
                        'waiting_period' => (int) ($item['WaitingPeriod'] ?? $item['waitingPeriod'] ?? 0),
                        'type_of_period' => $item['TypeOfPeriod'] ?? $item['typeOfPeriod'] ?? null,
                        'eligibility' => $item['Eligibility'] ?? $item['eligibility'] ?? null,
                        'common_price' => isset($item['CommonPrice']) ? (float) $item['CommonPrice'] : null,
                        'percent_covered' => isset($item['PercentCovered']) ? (float) $item['PercentCovered'] : null,
                        'available_in_levels' => $item['AvailableInLevels'] ?? $item['availableInLevels'] ?? null,
                        'practitioner_qualifications' => $item['PractitionerQualifications'] ?? $item['practitionerQualifications'] ?? null,
                        'is_active' => (bool) ($item['IsActive'] ?? $item['isActive'] ?? true),
                        'nhif_created_by' => $item['CreatedBy'] ?? $item['createdBy'] ?? null,
                        'nhif_date_created' => $dateCreated,
                        'nhif_last_modified_by' => $item['LastModifiedBy'] ?? $item['lastModifiedBy'] ?? null,
                        'nhif_last_modified' => $lastModified,
                        'synced_at' => $now,
                        'raw_data' => $item,
                    ]
                );

                $exists ? $counts['updated']++ : $counts['new']++;
            }
        });

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
     * Preview item types sync without modifying the database.
     *
     * @return array{success: bool, error?: string, stats: array<string, int>, sample: list<mixed>}
     */
    public function previewItemTypesSync(): array
    {
        $result = $this->client->getOcsItemTypes();
        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to connect to NHIF OCS',
                'stats' => ['total' => 0, 'new' => 0, 'existing' => 0],
                'sample' => [],
            ];
        }

        $items = $result['data']['ItemTypes'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $existingIds = NhifItemType::pluck('item_type_id')->all();
        $existingLookup = array_flip($existingIds);

        $newCount = 0;
        $existingCount = 0;
        foreach ($items as $item) {
            $id = $item['ItemTypeID'] ?? $item['itemTypeId'] ?? null;
            if ($id !== null && isset($existingLookup[(int) $id])) {
                $existingCount++;
            } else {
                $newCount++;
            }
        }

        return [
            'success' => true,
            'stats' => [
                'total' => count($items),
                'new' => $newCount,
                'existing' => $existingCount,
            ],
            'sample' => array_slice($items, 0, 5),
        ];
    }

    /**
     * Preview items sync without modifying the database.
     *
     * @return array{success: bool, error?: string, stats: array<string, int>, sample: list<mixed>}
     */
    public function previewItemsSync(): array
    {
        $result = $this->client->getOcsItems();
        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to connect to NHIF OCS',
                'stats' => ['total' => 0, 'new' => 0, 'existing' => 0, 'active' => 0, 'inactive' => 0, 'restricted' => 0],
                'sample' => [],
            ];
        }

        $items = $result['data']['Items'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $existingCodes = NhifService::pluck('nhif_item_code')->all();
        $existingLookup = array_flip($existingCodes);

        $newCount = 0;
        $existingCount = 0;
        $activeCount = 0;
        $inactiveCount = 0;
        $restrictedCount = 0;

        foreach ($items as $item) {
            $code = (string) ($item['ItemCode'] ?? $item['itemCode'] ?? '');
            if ($code !== '' && isset($existingLookup[$code])) {
                $existingCount++;
            } else {
                $newCount++;
            }

            if (! empty($item['IsActive'])) {
                $activeCount++;
            } else {
                $inactiveCount++;
            }

            if (! empty($item['IsRestricted'])) {
                $restrictedCount++;
            }
        }

        return [
            'success' => true,
            'stats' => [
                'total' => count($items),
                'new' => $newCount,
                'existing' => $existingCount,
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'restricted' => $restrictedCount,
            ],
            'sample' => array_slice($items, 0, 5),
        ];
    }

    /**
     * Get report of unmatched items and status breakdown.
     *
     * @return array<string, mixed>
     */
    public function getUnmatchedCatalogueReport(): array
    {
        $schemesDistinctCodes = DB::table('schemes_services')
            ->distinct()
            ->pluck('item_code')
            ->map(fn ($c) => (string) $c)
            ->all();

        $nhifServiceCodes = NhifService::pluck('nhif_item_code')
            ->map(fn ($c) => (string) $c)
            ->all();
        $nhifServiceLookup = array_flip($nhifServiceCodes);

        $unmatchedInSchemes = [];
        foreach ($schemesDistinctCodes as $code) {
            if (! isset($nhifServiceLookup[$code])) {
                $unmatchedInSchemes[] = $code;
            }
        }

        return [
            'total_schemes_services_codes' => count($schemesDistinctCodes),
            'total_nhif_services_synced' => count($nhifServiceCodes),
            'schemes_codes_missing_in_nhif_services' => count($unmatchedInSchemes),
            'unmatched_sample' => array_slice($unmatchedInSchemes, 0, 10),
            'nhif_services_active' => NhifService::where('is_active', true)->count(),
            'nhif_services_inactive' => NhifService::where('is_active', false)->count(),
            'nhif_services_restricted' => NhifService::where('is_restricted', true)->count(),
            'item_types_count' => NhifItemType::count(),
        ];
    }

    /**
     * Resolve valid user ID for performed_by, creating a system fallback if table is empty.
     */
    protected function resolvePerformedBy(): int
    {
        return auth()->id()
            ?? User::query()->value('id')
            ?? (class_exists(User::class) && method_exists(User::class, 'factory') ? User::factory()->create()->id : 1);
    }
}
