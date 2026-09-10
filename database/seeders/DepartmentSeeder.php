<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * The departments to seed for the main hospital.
     *
     * @var array<string, string>
     */
    protected array $departments = [
        'OPD' => 'Outpatient Department',
        'EMERGENCY' => 'Emergency',
        'LAB' => 'Laboratory',
        'PHARMACY' => 'Pharmacy',
        'RADIOLOGY' => 'Radiology',
        'INPATIENT' => 'Inpatient',
        'MATERNITY' => 'Maternity',
    ];

    /**
     * Seed the main hospital's departments.
     */
    public function run(): void
    {
        $hospital = Hospital::query()->where('code', 'MAIN')->firstOrFail();

        foreach ($this->departments as $code => $name) {
            $hospital->departments()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'status' => 'active']
            );
        }
    }
}
