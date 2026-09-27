<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use App\Services\NHIF\SchemesServicesDumpParser;
use Illuminate\Database\Seeder;

/**
 * Seeds the central billing `Service` catalog (Phase 7, Step 53) directly
 * from NHIF's own national item catalog dump
 * (`database/schemes_services.sql`), using each item's own `code`/`name`
 * verbatim — mirrors `ServiceCatalogSeeder`'s exact idiom (same actor
 * resolution, same `updateOrCreate` shape, same is_billable/status
 * defaults), keyed on `code` so re-running this seeder is fully
 * idempotent.
 *
 * These 1,826 items have no corresponding `LaboratoryTest`/
 * `RadiologyProcedure` row to link to, so `serviceable_type`/
 * `serviceable_id` are left null — the same "standalone service" pattern
 * `ServiceCatalogSeeder`'s own docblock already establishes for
 * CONSULT-GP/CONSULT-SPECIALIST.
 *
 * Category mapping collapses NHIF's 15 item types onto this app's fixed
 * `Service::CATEGORIES` vocabulary. "Diagnostic Examinations" (326 items —
 * a genuine mix of laboratory and radiology exams) is deliberately mapped
 * to `procedure` rather than guessed apart by keyword, since item names
 * don't reliably indicate which; every other surgical/specialized/
 * procedural/implant/clinical-service type also collapses to `procedure`.
 */
class NhifSchemeServiceSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    protected array $categoryMap = [
        'Registration/Consultation Charges' => 'consultation',
        'Medicine and Consumables' => 'pharmacy',
        'Inpatient Charges' => 'inpatient',
        'Other Charges' => 'other',
    ];

    protected string $defaultCategory = 'procedure';

    /**
     * Overridable so tests can point this at a small fixture instead of
     * the real 7MB dump.
     */
    public string $path;

    public function __construct()
    {
        $this->path = base_path('database/schemes_services.sql');
    }

    public function run(SchemesServicesDumpParser $parser): void
    {
        $actor = User::query()->where('email', 'test@example.com')->first() ?? User::query()->firstOrFail();

        if (! is_file($this->path)) {
            $this->command?->warn("Skipping NhifSchemeServiceSeeder: file not found at {$this->path}");

            return;
        }

        $items = $parser->parse($this->path)['items'];

        foreach ($items as $itemCode => $item) {
            Service::query()->updateOrCreate(
                ['code' => $itemCode],
                [
                    'name' => $item['item_name'],
                    'description' => $this->buildDescription($item),
                    'category' => $this->categoryMap[$item['type_name']] ?? $this->defaultCategory,
                    'is_billable' => true,
                    'status' => Service::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                ],
            );
        }
    }

    /**
     * @param  array{item_name: string, item_type_id: string, type_name: string, scheme_ids: array<int, string>, dosage: ?string, strength: ?string}  $item
     */
    protected function buildDescription(array $item): string
    {
        $parts = array_filter([
            $item['dosage'] ? "Dosage: {$item['dosage']}" : null,
            $item['strength'] ? "Strength: {$item['strength']}" : null,
        ]);

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        return "NHIF item type: {$item['type_name']}";
    }
}
