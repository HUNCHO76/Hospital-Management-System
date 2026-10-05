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
        {--diseases : Sync disease reference from OCS GetDiseases}
        {--services : Sync NHIF service/item list}
        {--packages : Sync NHIF packages/schemes}
        {--scheme-services= : Sync allowed items for a specific scheme ID}
        {--package-prices : Sync NHIF package price catalog from GetPricePackage}
        {--scheme-id= : Filter package-prices sync to a specific scheme ID}
        {--package-id= : Filter package-prices sync to a specific package ID}
        {--all : Run all sync types (diseases, services, packages, package-prices)}
        {--hospital= : Hospital code to use for facility-scoped syncs}';

    /** @var string */
    protected $description = 'Sync NHIF catalog data from the NHIF API into local tables';

    public function handle(NhifCatalogSyncService $service): int
    {
        $all = $this->option('all');
        $ran = false;

        if ($all || $this->option('diseases')) {
            $this->info('Syncing NHIF diseases...');
            $log = $service->syncDiseases();
            $this->reportLog($log, 'Diseases');
            $ran = true;
        }

        if ($all || $this->option('services')) {
            $hospital = $this->resolveHospital();
            $this->info('Syncing NHIF services...');
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
            $this->warn('No sync type specified. Use --diseases, --services, --packages, --scheme-services=ID, or --all.');

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
