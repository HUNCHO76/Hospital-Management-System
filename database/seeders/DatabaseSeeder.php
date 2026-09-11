<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $testUser = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['UserName' => 'Test User', 'email' => 'test@example.com']),
        );

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            HospitalSeeder::class,
            DepartmentSeeder::class,
            StaffSeeder::class,
            PractitionerSeeder::class,
            SystemSettingSeeder::class,
            DiagnosisCodeSeeder::class,
            MedicationSeeder::class,
            LaboratoryTestSeeder::class,
            LaboratoryBenchSeeder::class,
            RadiologyProcedureSeeder::class,
        ]);

        // The only distinct laboratory-supervisor account in dev/test data —
        // needed so a released-for-verification result always has someone
        // other than the entering technician able to review/verify/
        // authorize/release it (self-review is blocked by policy).
        $testUser->syncRoles(['laboratory-supervisor']);

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'superadmin@hms.test'],
            User::factory()->raw(['UserName' => 'Super Administrator', 'email' => 'superadmin@hms.test']),
        );
        $superAdmin->syncRoles(['super-admin']);

        $hospitalAdmin = User::query()->firstOrCreate(
            ['email' => 'hospitaladmin@hms.test'],
            User::factory()->raw(['UserName' => 'Hospital Administrator', 'email' => 'hospitaladmin@hms.test']),
        );
        $hospitalAdmin->syncRoles(['hospital-admin']);
    }
}
