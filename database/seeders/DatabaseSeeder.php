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
            PharmacyStockSeeder::class,
            LaboratoryTestSeeder::class,
            LaboratoryTestParameterSeeder::class,
            LaboratoryBenchSeeder::class,
            RadiologyProcedureSeeder::class,
            ServiceCatalogSeeder::class,
            ServicePriceSeeder::class,
            PharmacyDispensingSeeder::class,
            NhifInsuranceProviderSeeder::class,
            // Runs after ServicePriceSeeder deliberately — these 1,826
            // standalone NHIF catalog items must NOT receive the flat
            // placeholder price ServicePriceSeeder seeds for every active
            // Service; only code/name/category/description are seeded here.
            NhifSchemeServiceSeeder::class,
            ProcurementSeeder::class,
        ]);

        // The only distinct laboratory-supervisor account in dev/test data —
        // needed so a released-for-verification result always has someone
        // other than the entering technician able to review/verify/
        // authorize/release it (self-review is blocked by policy).
        $testUser->syncRoles(['laboratory-supervisor']);

        $demoUsers = [
            ['email' => 'superadmin@hms.test', 'name' => 'Super Administrator', 'role' => 'super-admin'],
            ['email' => 'hospitaladmin@hms.test', 'name' => 'Hospital Administrator', 'role' => 'hospital-admin'],
            ['email' => 'purchaseofficer@hms.test', 'name' => 'Peter Kimaro (Purchase Officer)', 'role' => 'purchase-officer'],
            ['email' => 'purchasemanager@hms.test', 'name' => 'Patricia Lyimo (Purchase Manager)', 'role' => 'purchase-manager'],
            ['email' => 'storeofficer@hms.test', 'name' => 'Salim Bakari (Store Officer)', 'role' => 'store-officer'],
            ['email' => 'storemanager@hms.test', 'name' => 'Stella Mrema (Store Manager)', 'role' => 'store-manager'],
            ['email' => 'deptuser@hms.test', 'name' => 'Dennis Shirima (Department Requisitioner)', 'role' => 'department-user'],
            ['email' => 'doctor@hms.test', 'name' => 'Dr. Amina Juma', 'role' => 'doctor'],
            ['email' => 'nurse@hms.test', 'name' => 'Nurse Baraka Mushi', 'role' => 'nurse'],
            ['email' => 'pharmacist@hms.test', 'name' => 'Catherine Mwakalinga', 'role' => 'pharmacist'],
            ['email' => 'accountant@hms.test', 'name' => 'Frank Ngowi', 'role' => 'accountant'],
            ['email' => 'receptionist@hms.test', 'name' => 'Edith Massawe', 'role' => 'receptionist'],
            ['email' => 'radiologist@hms.test', 'name' => 'Dr. Rachel Tarimo', 'role' => 'radiologist'],
            ['email' => 'radiologytech@hms.test', 'name' => 'Richard Swai', 'role' => 'radiology-technician'],
            ['email' => 'labtech@hms.test', 'name' => 'David Kessy', 'role' => 'laboratory-technician'],
        ];

        foreach ($demoUsers as $demo) {
            $user = User::query()->firstOrCreate(
                ['email' => $demo['email']],
                User::factory()->raw(['UserName' => $demo['name'], 'email' => $demo['email'], 'password' => 'password']),
            );
            $user->syncRoles([$demo['role']]);
        }
    }
}
