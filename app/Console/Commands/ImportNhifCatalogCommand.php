<?php

namespace App\Console\Commands;

use App\Models\NhifService;
use App\Services\NHIF\SchemesServicesDumpParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-imports NHIF's own national item catalog dump (a phpMyAdmin export
 * of NHIF's `schemes_services` table) into the local `nhif_services`
 * table — the same destination/shape `NhifCatalogSyncService::syncServices()`
 * writes to when syncing live from the NHIF API, so both paths stay
 * consistent. Intended as a one-off/occasional manual import
 * (`php artisan nhif:import-catalog`), not part of the standard db:seed
 * chain, since it depends on a bundled external reference file rather than
 * generated fixture data. Parsing/dedup is shared with
 * `NhifSchemeServiceSeeder` via `SchemesServicesDumpParser`.
 */
class ImportNhifCatalogCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nhif:import-catalog {path=database/schemes_services.sql : Path to the schemes_services SQL dump}';

    /**
     * @var string
     */
    protected $description = 'Import the NHIF national item catalog dump into the nhif_services table';

    public function handle(SchemesServicesDumpParser $parser): int
    {
        $path = $this->argument('path');
        $path = is_file($path) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $parsed = $parser->parse($path);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $items = $parsed['items'];
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($items, &$created, &$updated): void {
            foreach ($items as $itemCode => $item) {
                $existed = NhifService::query()->where('nhif_item_code', $itemCode)->exists();

                NhifService::updateOrCreate(
                    ['nhif_item_code' => $itemCode],
                    [
                        'nhif_item_name' => $item['item_name'],
                        'nhif_item_type_id' => $item['item_type_id'],
                        'nhif_item_type_name' => $item['type_name'],
                        'is_active' => true,
                        'synced_at' => now(),
                        'raw_data' => [
                            'scheme_ids' => $item['scheme_ids'],
                            'dosage' => $item['dosage'],
                            'strength' => $item['strength'],
                        ],
                    ],
                );

                $existed ? $updated++ : $created++;
            }
        });

        $this->info(sprintf(
            'Parsed %d rows, %d distinct item codes. Created %d, updated %d.',
            $parsed['raw_row_count'],
            count($items),
            $created,
            $updated,
        ));

        return self::SUCCESS;
    }
}
