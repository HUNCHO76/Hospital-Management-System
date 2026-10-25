<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Models\NhifPriceSyncLog;
use App\Services\NHIF\NhifCatalogSyncService;
use Illuminate\Console\Command;

/**
 * Syncs live NHIF catalog data from the NHIF API into local tables.
 * Distinct from ImportNhifCatalogCommand which imports a SQL dump file.
 */
class SyncNhifCatalogCommand extends Command
{
    /** @var string */
    protected $signature = 'nhif:catalog:sync
        {--item-types : Sync NHIF item types (categories) from OCS GetItemTypes}
        {--items : Sync NHIF national item catalog from OCS GetItems}
        {--diseases : Sync disease reference from OCS GetDiseases}
        {--services : Sync NHIF facility-level service list}
        {--packages : Sync NHIF packages/schemes}
        {--scheme-services= : Sync allowed items for a specific scheme ID}
        {--package-prices : Sync NHIF package price catalog from GetPricePackage}
        {--scheme-id= : Filter package-prices sync to a specific scheme ID}
        {--package-id= : Filter package-prices sync to a specific package ID}
        {--preview : Preview changes from OCS without applying to database}
        {--unmatched : Display unmatched catalogue analysis report}
        {--all : Run all master sync types (item-types, items, diseases, packages, package-prices)}
        {--hospital= : Hospital code to use for facility-scoped syncs}';

    /** @var string */
    protected $description = 'Sync NHIF catalog data from the NHIF API into local tables';

    public function handle(NhifCatalogSyncService $service): int
    {
        if ($this->option('unmatched')) {
            $this->info('Generating NHIF Catalogue Analysis Report...');
            $report = $service->getUnmatchedCatalogueReport();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Unique Codes in schemes_services', $report['total_schemes_services_codes']],
                    ['Total Synced Items in nhif_services', $report['total_nhif_services_synced']],
                    ['Schemes Codes Missing in nhif_services', $report['schemes_codes_missing_in_nhif_services']],
                    ['Active Items in nhif_services', $report['nhif_services_active']],
                    ['Inactive Items in nhif_services (Preserved)', $report['nhif_services_inactive']],
                    ['Restricted Items in nhif_services', $report['nhif_services_restricted']],
                    ['Item Types Count', $report['item_types_count']],
                ]
            );

            return self::SUCCESS;
        }

        if ($this->option('preview')) {
            $this->info('--- PREVIEW MODE: No database changes will be saved ---');
            $showTypes = $this->option('all') || $this->option('item-types');
            $showItems = $this->option('all') || $this->option('items');

            if ($showTypes) {
                $this->info('Fetching Item Types preview from OCS...');
                $res = $service->previewItemTypesSync();
                if ($res['success']) {
                    $this->table(
                        ['Item Types Total', 'New', 'Existing (To Update)'],
                        [[$res['stats']['total'], $res['stats']['new'], $res['stats']['existing']]]
                    );
                } else {
                    $this->error("Failed to preview item types: {$res['error']}");
                }
            }

            if ($showItems) {
                $this->info('Fetching Items preview from OCS...');
                $res = $service->previewItemsSync();
                if ($res['success']) {
                    $this->table(
                        ['Items Total', 'New', 'Existing', 'Active', 'Inactive', 'Restricted'],
                        [[
                            $res['stats']['total'],
                            $res['stats']['new'],
                            $res['stats']['existing'],
                            $res['stats']['active'],
                            $res['stats']['inactive'],
                            $res['stats']['restricted'],
                        ]]
                    );
                } else {
                    $this->error("Failed to preview items: {$res['error']}");
                }
            }

            return self::SUCCESS;
        }

        $all = $this->option('all');
        $ran = false;

        if ($all || $this->option('item-types')) {
            $this->info('Syncing NHIF item types (OCS GetItemTypes)...');
            $log = $service->syncItemTypes();
            $this->reportLog($log, 'Item Types');
            $ran = true;
        }

        if ($all || $this->option('items')) {
            $this->info('Syncing NHIF national items (OCS GetItems)...');
            $log = $service->syncItems();
            $this->reportLog($log, 'Items');
            $ran = true;
        }

        if ($all || $this->option('diseases')) {
            $this->info('Syncing NHIF diseases (OCS GetDiseases)...');
            $log = $service->syncDiseases();
            $this->reportLog($log, 'Diseases');
            $ran = true;
        }

        if ($this->option('services')) {
            $hospital = $this->resolveHospital();
            $this->info('Syncing NHIF facility services...');
            $log = $service->syncServices($hospital);
            $this->reportLog($log, 'Services');
            $ran = true;
        }

        if ($all || $this->option('packages')) {
            $this->info('Syncing NHIF packages...');
            $log = $service->syncPackages();
            $this->reportLog($log, 'Packages');
            $ran = true;
        }

        if ($schemeId = $this->option('scheme-services')) {
            $this->info("Syncing scheme services for scheme ID {$schemeId}...");
            $log = $service->syncSchemeServices((int) $schemeId);
            $this->reportLog($log, "Scheme {$schemeId}");
            $ran = true;
        }

        if ($all || $this->option('package-prices')) {
            $filterSchemeId = $this->option('scheme-id') ? (int) $this->option('scheme-id') : null;
            $filterPackageId = $this->option('package-id') ? (int) $this->option('package-id') : null;
            $label = 'Package Prices';
            if ($filterSchemeId) {
                $label .= " (scheme {$filterSchemeId})";
            }
            $this->info("Syncing NHIF {$label}...");
            $log = $service->syncPackagePrices($filterSchemeId, $filterPackageId);
            $this->reportLog($log, $label);
            $ran = true;
        }

        if (! $ran) {
            $this->warn('No sync type specified. Use --item-types, --items, --diseases, --packages, --preview, --unmatched, or --all.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveHospital(): ?Hospital
    {
        $code = $this->option('hospital');
        if (! $code) {
            return null;
        }

        $hospital = Hospital::where('code', $code)->first();
        if (! $hospital) {
            $this->warn("Hospital with code '{$code}' not found; syncing without facility scope.");
        }

        return $hospital;
    }

    private function reportLog(NhifPriceSyncLog $log, string $label): void
    {
        if ($log->status === 'failed') {
            $this->error("{$label}: FAILED — {$log->error_message}");
        } else {
            $this->info(sprintf(
                '%s: done — total=%d new=%d updated=%d skipped=%d',
                $label,
                $log->total_records ?? 0,
                $log->new_records ?? 0,
                $log->updated_records ?? 0,
                $log->skipped_records ?? 0,
            ));
        }
    }
}
