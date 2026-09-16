<?php

namespace Database\Seeders;

use App\Models\LaboratoryTest;
use App\Models\RadiologyProcedure;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Maps the existing, already-seeded LaboratoryTest/RadiologyProcedure
 * catalogs into the central billing Service catalog (Phase 7, Step 53, spec
 * §23/§24/§57) — never invents its own test/procedure codes; each mapped
 * Service reuses the source row's own real `code`/`name` verbatim, keyed on
 * `updateOrCreate(['serviceable_type', 'serviceable_id'], ...)` so this
 * seeder is fully idempotent (re-running it after the source catalog grows
 * only adds the new rows, never duplicates or touches existing ones).
 *
 * `CONSULT-GP`/`CONSULT-SPECIALIST` are the only genuinely hand-authored
 * services here — spec §57's own suggested starter pair — since no existing
 * "consultation type" catalog exists anywhere in this app to map from (a
 * doctor consultation has never been a priced/catalogued concept before
 * this step). Deliberately does NOT map Medication (spec §26: pharmacy
 * pricing is stock/batch-driven, not a generic-service concern — see
 * ServiceCatalogSeeder's own class docblock in docs/billing.md for the full
 * reasoning) — no pharmacy service rows are seeded here.
 */
class ServiceCatalogSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, category: string, description: string}>
     */
    protected array $clinicalServices = [
        ['code' => 'CONSULT-GP', 'name' => 'General Consultation', 'category' => 'consultation', 'description' => 'A general outpatient consultation with a doctor.'],
        ['code' => 'CONSULT-SPECIALIST', 'name' => 'Specialist Consultation', 'category' => 'consultation', 'description' => 'An outpatient consultation with a specialist.'],
    ];

    public function run(): void
    {
        // The very first user DatabaseSeeder creates, already committed by
        // the time this seeder runs from within its own $this->call() list.
        $actor = User::query()->where('email', 'test@example.com')->first() ?? User::query()->firstOrFail();

        foreach ($this->clinicalServices as $service) {
            Service::query()->updateOrCreate(
                ['code' => $service['code']],
                [
                    'name' => $service['name'],
                    'description' => $service['description'],
                    'category' => $service['category'],
                    'is_billable' => true,
                    'status' => Service::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                ],
            );
        }

        LaboratoryTest::query()
            ->where('status', LaboratoryTest::STATUS_ACTIVE)
            ->whereNotNull('code')
            ->each(function (LaboratoryTest $test) use ($actor) {
                Service::query()->updateOrCreate(
                    ['serviceable_type' => LaboratoryTest::class, 'serviceable_id' => $test->id],
                    [
                        'code' => $test->code,
                        'name' => $test->name,
                        'description' => $test->description,
                        'category' => 'laboratory',
                        'is_billable' => true,
                        'status' => Service::STATUS_ACTIVE,
                        'created_by' => $actor->id,
                    ],
                );
            });

        RadiologyProcedure::query()
            ->where('status', RadiologyProcedure::STATUS_ACTIVE)
            ->whereNotNull('code')
            ->each(function (RadiologyProcedure $procedure) use ($actor) {
                Service::query()->updateOrCreate(
                    ['serviceable_type' => RadiologyProcedure::class, 'serviceable_id' => $procedure->id],
                    [
                        'code' => $procedure->code,
                        'name' => $procedure->name,
                        'description' => $procedure->description,
                        'category' => 'radiology',
                        'is_billable' => true,
                        'status' => Service::STATUS_ACTIVE,
                        'created_by' => $actor->id,
                    ],
                );
            });
    }
}
