<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StaffAccountActivationService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CreateStaffAccountsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hms:create-staff-accounts
                            {--dry-run : Validate and show what would be created without making database changes}
                            {--activate : Generate activation tokens and URLs for the accounts}
                            {--email-domain= : Optional internal email domain for provisional accounts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create individual staff accounts for St. Joseph DMI HMS with least-privilege roles and audit logging';

    /**
     * The approved directory of 32 staff members.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $staffDirectory = [
        // Dental — 2
        [
            'category' => 'Dental',
            'raw_name' => 'Dr. Bhargavi Maridi',
            'first_name' => 'Bhargavi',
            'middle_name' => null,
            'last_name' => 'Maridi',
            'gender' => 'female',
            'department_code' => 'DENTAL',
            'department_name' => 'Dental',
            'job_title' => 'Dentist',
            'role' => 'doctor',
            'practitioner_type' => 'dentist',
        ],
        [
            'category' => 'Dental',
            'raw_name' => 'Dr. Ruthsal Abisha Bindhu',
            'first_name' => 'Ruthsal',
            'middle_name' => 'Abisha',
            'last_name' => 'Bindhu',
            'gender' => 'female',
            'department_code' => 'DENTAL',
            'department_name' => 'Dental',
            'job_title' => 'Dentist',
            'role' => 'doctor',
            'practitioner_type' => 'dentist',
        ],

        // Pharmacy — 4
        [
            'category' => 'Pharmacy',
            'raw_name' => 'Irene Paul',
            'first_name' => 'Irene',
            'middle_name' => null,
            'last_name' => 'Paul',
            'gender' => 'female',
            'department_code' => 'PHARMACY',
            'department_name' => 'Pharmacy',
            'job_title' => 'Pharmacist',
            'role' => 'pharmacist',
            'practitioner_type' => 'pharmacist',
        ],
        [
            'category' => 'Pharmacy',
            'raw_name' => 'Mary Lema',
            'first_name' => 'Mary',
            'middle_name' => null,
            'last_name' => 'Lema',
            'gender' => 'female',
            'department_code' => 'PHARMACY',
            'department_name' => 'Pharmacy',
            'job_title' => 'Pharmacist',
            'role' => 'pharmacist',
            'practitioner_type' => 'pharmacist',
        ],
        [
            'category' => 'Pharmacy',
            'raw_name' => 'Glory Kibona',
            'first_name' => 'Glory',
            'middle_name' => null,
            'last_name' => 'Kibona',
            'gender' => 'female',
            'department_code' => 'PHARMACY',
            'department_name' => 'Pharmacy',
            'job_title' => 'Pharmacist',
            'role' => 'pharmacist',
            'practitioner_type' => 'pharmacist',
        ],
        [
            'category' => 'Pharmacy',
            'raw_name' => 'Monica Laurency',
            'first_name' => 'Monica',
            'middle_name' => null,
            'last_name' => 'Laurency',
            'gender' => 'female',
            'department_code' => 'PHARMACY',
            'department_name' => 'Pharmacy',
            'job_title' => 'Pharmacist',
            'role' => 'pharmacist',
            'practitioner_type' => 'pharmacist',
        ],

        // Cashier — 5
        [
            'category' => 'Cashier',
            'raw_name' => 'Zaina Mkwenya',
            'first_name' => 'Zaina',
            'middle_name' => null,
            'last_name' => 'Mkwenya',
            'gender' => 'female',
            'department_code' => 'FINANCE',
            'department_name' => 'Finance & Cashier',
            'job_title' => 'Cashier',
            'role' => 'accountant',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Cashier',
            'raw_name' => 'Lightness Raphael',
            'first_name' => 'Lightness',
            'middle_name' => null,
            'last_name' => 'Raphael',
            'gender' => 'female',
            'department_code' => 'FINANCE',
            'department_name' => 'Finance & Cashier',
            'job_title' => 'Cashier',
            'role' => 'accountant',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Cashier',
            'raw_name' => 'Andongolile Gano',
            'first_name' => 'Andongolile',
            'middle_name' => null,
            'last_name' => 'Gano',
            'gender' => 'male',
            'department_code' => 'FINANCE',
            'department_name' => 'Finance & Cashier',
            'job_title' => 'Cashier',
            'role' => 'accountant',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Cashier',
            'raw_name' => 'Groly Lucas',
            'first_name' => 'Groly',
            'middle_name' => null,
            'last_name' => 'Lucas',
            'gender' => 'female',
            'department_code' => 'FINANCE',
            'department_name' => 'Finance & Cashier',
            'job_title' => 'Cashier',
            'role' => 'accountant',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Cashier',
            'raw_name' => 'Christina Kipemba',
            'first_name' => 'Christina',
            'middle_name' => null,
            'last_name' => 'Kipemba',
            'gender' => 'female',
            'department_code' => 'FINANCE',
            'department_name' => 'Finance & Cashier',
            'job_title' => 'Cashier',
            'role' => 'accountant',
            'practitioner_type' => null,
        ],

        // Laboratory — 5
        [
            'category' => 'Laboratory',
            'raw_name' => 'Samwel Boniface',
            'first_name' => 'Samwel',
            'middle_name' => null,
            'last_name' => 'Boniface',
            'gender' => 'male',
            'department_code' => 'LAB',
            'department_name' => 'Laboratory',
            'job_title' => 'Laboratory Technician',
            'role' => 'laboratory-technician',
            'practitioner_type' => 'laboratory-technician',
        ],
        [
            'category' => 'Laboratory',
            'raw_name' => 'Husna Omary',
            'first_name' => 'Husna',
            'middle_name' => null,
            'last_name' => 'Omary',
            'gender' => 'female',
            'department_code' => 'LAB',
            'department_name' => 'Laboratory',
            'job_title' => 'Laboratory Technician',
            'role' => 'laboratory-technician',
            'practitioner_type' => 'laboratory-technician',
        ],
        [
            'category' => 'Laboratory',
            'raw_name' => 'Cosmas Kigoda',
            'first_name' => 'Cosmas',
            'middle_name' => null,
            'last_name' => 'Kigoda',
            'gender' => 'male',
            'department_code' => 'LAB',
            'department_name' => 'Laboratory',
            'job_title' => 'Laboratory Technician',
            'role' => 'laboratory-technician',
            'practitioner_type' => 'laboratory-technician',
        ],
        [
            'category' => 'Laboratory',
            'raw_name' => 'Safi Malimu',
            'first_name' => 'Safi',
            'middle_name' => null,
            'last_name' => 'Malimu',
            'gender' => 'female',
            'department_code' => 'LAB',
            'department_name' => 'Laboratory',
            'job_title' => 'Laboratory Technician',
            'role' => 'laboratory-technician',
            'practitioner_type' => 'laboratory-technician',
        ],
        [
            'category' => 'Laboratory',
            'raw_name' => 'Peter Ndayanse',
            'first_name' => 'Peter',
            'middle_name' => null,
            'last_name' => 'Ndayanse',
            'gender' => 'male',
            'department_code' => 'LAB',
            'department_name' => 'Laboratory',
            'job_title' => 'Laboratory Technician',
            'role' => 'laboratory-technician',
            'practitioner_type' => 'laboratory-technician',
        ],

        // Nursing — 10
        [
            'category' => 'Nursing',
            'raw_name' => 'Mohamed Ngongono',
            'first_name' => 'Mohamed',
            'middle_name' => null,
            'last_name' => 'Ngongono',
            'gender' => 'male',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Simon Ngasa',
            'first_name' => 'Simon',
            'middle_name' => null,
            'last_name' => 'Ngasa',
            'gender' => 'male',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'John Mgaya',
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Mgaya',
            'gender' => 'male',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Tatu Hemedi',
            'first_name' => 'Tatu',
            'middle_name' => null,
            'last_name' => 'Hemedi',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Annastazia David',
            'first_name' => 'Annastazia',
            'middle_name' => null,
            'last_name' => 'David',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Jesca Mkilanya',
            'first_name' => 'Jesca',
            'middle_name' => null,
            'last_name' => 'Mkilanya',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Edwin Magembe',
            'first_name' => 'Edwin',
            'middle_name' => null,
            'last_name' => 'Magembe',
            'gender' => 'male',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Swaumu Bakari',
            'first_name' => 'Swaumu',
            'middle_name' => null,
            'last_name' => 'Bakari',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Juliana Mruthu',
            'first_name' => 'Juliana',
            'middle_name' => null,
            'last_name' => 'Mruthu',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],
        [
            'category' => 'Nursing',
            'raw_name' => 'Eliania Boniface',
            'first_name' => 'Eliania',
            'middle_name' => null,
            'last_name' => 'Boniface',
            'gender' => 'female',
            'department_code' => 'INPATIENT',
            'department_name' => 'Inpatient',
            'job_title' => 'Nurse',
            'role' => 'nurse',
            'practitioner_type' => 'nurse',
        ],

        // Radiology — 1
        [
            'category' => 'Radiology',
            'raw_name' => 'Edward Antony',
            'first_name' => 'Edward',
            'middle_name' => null,
            'last_name' => 'Antony',
            'gender' => 'male',
            'department_code' => 'RADIOLOGY',
            'department_name' => 'Radiology',
            'job_title' => 'Radiology Technician',
            'role' => 'radiology-technician',
            'practitioner_type' => 'radiology-technician',
        ],

        // Reception — 4
        [
            'category' => 'Reception',
            'raw_name' => 'Adelina Julius',
            'first_name' => 'Adelina',
            'middle_name' => null,
            'last_name' => 'Julius',
            'gender' => 'female',
            'department_code' => 'OPD',
            'department_name' => 'Outpatient Department',
            'job_title' => 'Receptionist',
            'role' => 'receptionist',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Reception',
            'raw_name' => 'Anatori Mlewa',
            'first_name' => 'Anatori',
            'middle_name' => null,
            'last_name' => 'Mlewa',
            'gender' => 'male',
            'department_code' => 'OPD',
            'department_name' => 'Outpatient Department',
            'job_title' => 'Receptionist',
            'role' => 'receptionist',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Reception',
            'raw_name' => 'Daima Ibrahim',
            'first_name' => 'Daima',
            'middle_name' => null,
            'last_name' => 'Ibrahim',
            'gender' => 'female',
            'department_code' => 'OPD',
            'department_name' => 'Outpatient Department',
            'job_title' => 'Receptionist',
            'role' => 'receptionist',
            'practitioner_type' => null,
        ],
        [
            'category' => 'Reception',
            'raw_name' => 'Rachel Antipacy',
            'first_name' => 'Rachel',
            'middle_name' => null,
            'last_name' => 'Antipacy',
            'gender' => 'female',
            'department_code' => 'OPD',
            'department_name' => 'Outpatient Department',
            'job_title' => 'Receptionist',
            'role' => 'receptionist',
            'practitioner_type' => null,
        ],

        // Medical Store — 1
        [
            'category' => 'Medical Store',
            'raw_name' => 'Vanathuchinnapparajan R.',
            'first_name' => 'Vanathuchinnapparajan',
            'middle_name' => null,
            'last_name' => 'R.',
            'gender' => 'male',
            'department_code' => 'STORES',
            'department_name' => 'Central Stores & Inventory',
            'job_title' => 'Store Officer',
            'role' => 'store-officer',
            'practitioner_type' => null,
        ],
    ];

    public function handle(
        AuditLogService $auditLog,
        StaffAccountActivationService $activationService,
    ): int {
        $isDryRun = $this->option('dry-run');
        $shouldActivate = $this->option('activate');
        $emailDomain = $this->option('email-domain');

        $this->info($isDryRun
            ? '=== DRY RUN: Validating Staff Accounts Creation ==='
            : '=== Executing Staff Accounts Creation ==='
        );

        // 1. Resolve Hospital
        $hospital = Hospital::query()->where('code', 'MAIN')->first() ?? Hospital::first();
        if (! $hospital) {
            $this->error('Main hospital not found. Please seed hospitals first.');
            return self::FAILURE;
        }

        // 2. Validate Roles exist
        $requiredRoles = array_unique(array_column($this->staffDirectory, 'role'));
        foreach ($requiredRoles as $roleName) {
            if (! Role::where('name', $roleName)->where('guard_name', 'web')->exists()) {
                $this->error("Required role \"{$roleName}\" does not exist in database.");
                return self::FAILURE;
            }
        }

        // 3. Scan & Prepare
        $summaryTable = [];
        $createdCount = 0;
        $skippedCount = 0;
        $activations = [];

        DB::beginTransaction();

        try {
            // Ensure departments exist
            $departmentsMap = [];
            foreach ($this->staffDirectory as $item) {
                $code = $item['department_code'];
                if (! isset($departmentsMap[$code])) {
                    $dept = Department::where('code', $code)->where('hospital_id', $hospital->id)->first();
                    if (! $dept && ! $isDryRun) {
                        $dept = Department::create([
                            'hospital_id' => $hospital->id,
                            'code' => $code,
                            'name' => $item['department_name'],
                            'status' => 'active',
                        ]);
                    }
                    $departmentsMap[$code] = $dept;
                }
            }

            foreach ($this->staffDirectory as $index => $item) {
                $fullName = trim($item['first_name'] . ' ' . ($item['middle_name'] ? $item['middle_name'] . ' ' : '') . $item['last_name']);
                $userName = $item['raw_name']; // Preserves Dr. title or exact raw input

                // Duplicate detection
                $existingStaff = Staff::query()
                    ->where('first_name', $item['first_name'])
                    ->where('last_name', $item['last_name'])
                    ->first();

                $existingUser = User::query()
                    ->where('UserName', $userName)
                    ->orWhere('UserName', $fullName)
                    ->first();

                if ($existingStaff || $existingUser) {
                    $skippedCount++;
                    $summaryTable[] = [
                        $index + 1,
                        $userName,
                        $item['department_name'],
                        $item['job_title'],
                        $item['role'],
                        $item['practitioner_type'] ?? '—',
                        'Skipped (Already exists: ' . ($existingStaff ? $existingStaff->staff_number : 'User #' . $existingUser->id) . ')',
                        'Existing',
                    ];
                    continue;
                }

                if ($isDryRun) {
                    $createdCount++;
                    $summaryTable[] = [
                        $index + 1,
                        $userName,
                        $item['department_name'],
                        $item['job_title'],
                        $item['role'],
                        $item['practitioner_type'] ?? '—',
                        'Ready to Create',
                        'Pending Activation',
                    ];
                    continue;
                }

                // 1. Create Staff Member
                $dept = $departmentsMap[$item['department_code']];
                $staff = Staff::create([
                    'hospital_id' => $hospital->id,
                    'department_id' => $dept?->id,
                    'first_name' => $item['first_name'],
                    'middle_name' => $item['middle_name'],
                    'last_name' => $item['last_name'],
                    'gender' => $item['gender'],
                    'job_title' => $item['job_title'],
                    'employment_type' => 'full_time',
                    'status' => Staff::STATUS_ACTIVE,
                    'hired_at' => now()->toDateString(),
                ]);

                // 2. Create User Account
                // Generate a cryptographically secure random password hash. Plaintext is never stored, displayed, or shared.
                $deptSlug = strtolower(str_replace(' ', '', $item['category']));
                $firstSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $item['first_name']));
                $lastSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $item['last_name']));
                $userEmail = $emailDomain
                    ? "{$firstSlug}.{$lastSlug}@{$emailDomain}"
                    : "{$firstSlug}.{$lastSlug}@{$deptSlug}.hms";

                $staff->update(['email' => $userEmail]);

                $user = User::create([
                    'UserName' => $userName,
                    'email' => $userEmail,
                    'password' => Hash::make(Str::random(40)),
                    'status' => $shouldActivate ? User::STATUS_ACTIVE : User::STATUS_INACTIVE,
                    'email_verified_at' => $shouldActivate ? now() : null,
                ]);

                // 3. Assign Spatie Role
                $user->assignRole($item['role']);
                $staff->update(['user_id' => $user->id]);

                // 4. Create Practitioner profile for clinical staff
                $practitionerRecord = null;
                if (! empty($item['practitioner_type'])) {
                    $practitionerRecord = Practitioner::create([
                        'staff_id' => $staff->id,
                        'practitioner_type' => $item['practitioner_type'],
                        'status' => Practitioner::STATUS_ACTIVE,
                    ]);
                }

                // 5. Audit Logging
                $fakeRequest = Request::create('/staff', 'POST');
                $auditLog->record(
                    'staff_account_bulk_created',
                    $staff,
                    null,
                    [
                        'staff_number' => $staff->staff_number,
                        'user_id' => $user->id,
                        'user_name' => $user->UserName,
                        'role' => $item['role'],
                        'department' => $item['department_name'],
                        'practitioner_type' => $item['practitioner_type'],
                    ],
                    $fakeRequest,
                );

                // 6. Generate activation link if requested
                $activationUrl = null;
                if ($shouldActivate) {
                    $invitation = $activationService->createActivationInvitation(
                        $user,
                        $userEmail,
                        null,
                        $fakeRequest,
                    );
                    $activations[] = [
                        'name' => $userName,
                        'staff_number' => $staff->staff_number,
                        'url' => $invitation['activationUrl'],
                    ];
                }

                $createdCount++;
                $summaryTable[] = [
                    $index + 1,
                    $userName,
                    $item['department_name'],
                    $item['job_title'],
                    $item['role'],
                    $item['practitioner_type'] ?? '—',
                    "Created ({$staff->staff_number})",
                    $shouldActivate ? 'Activated' : 'Pending Activation',
                ];
            }

            if ($isDryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to create staff accounts: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['#', 'Staff Name', 'Department', 'Job Title', 'Role Assigned', 'Practitioner', 'Action', 'Account Status'],
            $summaryTable
        );

        $this->newLine();
        $this->info("Summary: {$createdCount} accounts processed, {$skippedCount} skipped.");

        if ($isDryRun) {
            $this->warn('DRY RUN COMPLETE: No database records were created or modified.');
        } else {
            $this->info('SUCCESS: Staff accounts have been safely persisted in the database.');
        }

        return self::SUCCESS;
    }
}
