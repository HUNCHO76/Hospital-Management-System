<?php

namespace Database\Seeders;

use App\Models\LaboratoryBench;
use Illuminate\Database\Seeder;

/**
 * Seeds standard laboratory work-area benches (Phase 4, Step 32, spec §11).
 * Uses firstOrCreate so it is safe to run multiple times without duplicating
 * records — idempotent by (code) unique key.
 */
class LaboratoryBenchSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, code: string, description: string}>
     */
    private array $benches = [
        ['name' => 'Hematology',          'code' => 'HEMA',   'description' => 'Complete blood count and coagulation studies.'],
        ['name' => 'Clinical Chemistry',  'code' => 'CHEM',   'description' => 'Biochemistry panels, metabolic profiles, enzymes.'],
        ['name' => 'Microbiology',        'code' => 'MICRO',  'description' => 'Culture, sensitivity, and gram-stain work.'],
        ['name' => 'Immunology/Serology', 'code' => 'IMMUNO', 'description' => 'Antibody and antigen assays.'],
        ['name' => 'Urinalysis',          'code' => 'URINE',  'description' => 'Routine and microscopic urine examination.'],
        ['name' => 'Parasitology',        'code' => 'PARA',   'description' => 'Stool and blood parasite examination.'],
        ['name' => 'Histopathology',      'code' => 'HISTO',  'description' => 'Tissue biopsy processing and examination.'],
        ['name' => 'Blood Bank',          'code' => 'BB',     'description' => 'Cross-matching, blood grouping and transfusion prep.'],
    ];

    public function run(): void
    {
        foreach ($this->benches as $bench) {
            LaboratoryBench::query()->firstOrCreate(
                ['code' => $bench['code']],
                [
                    'name'        => $bench['name'],
                    'description' => $bench['description'],
                    'status'      => LaboratoryBench::STATUS_ACTIVE,
                ],
            );
        }
    }
}
