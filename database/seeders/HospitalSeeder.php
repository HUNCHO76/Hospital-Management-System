<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

class HospitalSeeder extends Seeder
{
    /**
     * Seed the main hospital.
     */
    public function run(): void
    {
        Hospital::query()->firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Hospital',
                'registration_number' => 'REG-000001',
                'phone' => '+255700000000',
                'email' => 'info@mainhospital.test',
                'address' => '1 Hospital Road',
                'region' => 'Dar es Salaam',
                'district' => 'Ilala',
                'ward' => 'Kariakoo',
                'status' => 'active',
            ]
        );
    }
}
