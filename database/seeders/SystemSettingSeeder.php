<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    /**
     * The default system settings to seed.
     *
     * @var list<array{key: string, value: string, type: string, group: string, description: string, is_public: bool}>
     */
    protected array $settings = [
        ['key' => 'hospital.name', 'value' => 'Main Hospital', 'type' => 'string', 'group' => 'hospital', 'description' => 'Primary hospital name', 'is_public' => true],
        ['key' => 'hospital.phone', 'value' => '+255700000000', 'type' => 'string', 'group' => 'hospital', 'description' => 'Primary hospital phone number', 'is_public' => true],
        ['key' => 'hospital.email', 'value' => 'info@mainhospital.test', 'type' => 'string', 'group' => 'hospital', 'description' => 'Primary hospital email address', 'is_public' => true],
        ['key' => 'patient.number_prefix', 'value' => 'PT', 'type' => 'string', 'group' => 'identifiers', 'description' => 'Prefix used for generated patient numbers', 'is_public' => false],
        ['key' => 'staff.number_prefix', 'value' => 'STF', 'type' => 'string', 'group' => 'identifiers', 'description' => 'Prefix used for generated staff numbers', 'is_public' => false],
        ['key' => 'currency', 'value' => 'TZS', 'type' => 'string', 'group' => 'general', 'description' => 'Default currency code', 'is_public' => true],
        ['key' => 'timezone', 'value' => 'Africa/Dar_es_Salaam', 'type' => 'string', 'group' => 'general', 'description' => 'Default application timezone', 'is_public' => true],
    ];

    /**
     * Seed the default system settings.
     */
    public function run(): void
    {
        foreach ($this->settings as $setting) {
            SystemSetting::query()->firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
