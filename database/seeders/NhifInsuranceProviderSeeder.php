<?php

namespace Database\Seeders;

use App\Models\InsuranceProvider;
use Illuminate\Database\Seeder;

/**
 * Seed the NHIF Tanzania insurance provider record (Phase 8).
 *
 * This seeder is idempotent — it updates the record when it already exists so
 * it can safely be re-run without duplicating the row.
 */
class NhifInsuranceProviderSeeder extends Seeder
{
    public function run(): void
    {
        InsuranceProvider::updateOrCreate(
            ['code' => 'NHIF'],
            [
                'name' => 'National Health Insurance Fund (NHIF)',
                'registration_number' => 'NHIF-TZ-001',
                'type' => 'government',
                'phone' => '+255 22 213 9000',
                'email' => 'info@nhif.or.tz',
                'address' => 'NHIF House, Ragael House, Ohio Street, Dar es Salaam',
                'api_enabled' => true,
                'status' => InsuranceProvider::STATUS_ACTIVE,
            ],
        );
    }
}
