<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AssignStaffEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staff:assign-emails
                            {--dry-run : Preview changes without persisting to the database}
                            {--activate : Also activate user accounts and mark email verified}
                            {--password= : Set a default password for the staff user accounts}
                            {--overwrite : Overwrite existing email if already populated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign departmental email addresses (firstname.lastname@department.hms) to staff accounts';

    /**
     * The 32 staff directory email definitions.
     */
    public const STAFF_EMAILS = [
        // Dental — 2
        'STF-2026-000017' => [
            'name' => 'Dr. Bhargavi Maridi',
            'department' => 'Dental',
            'email' => 'bhargavi.maridi@dental.hms',
        ],
        'STF-2026-000018' => [
            'name' => 'Dr. Ruthsal Abisha Bindhu',
            'department' => 'Dental',
            'email' => 'ruthsal.bindhu@dental.hms',
        ],

        // Pharmacy — 4
        'STF-2026-000019' => [
            'name' => 'Irene Paul',
            'department' => 'Pharmacy',
            'email' => 'irene.paul@pharmacy.hms',
        ],
        'STF-2026-000020' => [
            'name' => 'Mary Lema',
            'department' => 'Pharmacy',
            'email' => 'mary.lema@pharmacy.hms',
        ],
        'STF-2026-000021' => [
            'name' => 'Glory Kibona',
            'department' => 'Pharmacy',
            'email' => 'glory.kibona@pharmacy.hms',
        ],
        'STF-2026-000022' => [
            'name' => 'Monica Laurency',
            'department' => 'Pharmacy',
            'email' => 'monica.laurency@pharmacy.hms',
        ],

        // Cashier — 5
        'STF-2026-000023' => [
            'name' => 'Zaina Mkwenya',
            'department' => 'Finance & Cashier',
            'email' => 'zaina.mkwenya@cashier.hms',
        ],
        'STF-2026-000024' => [
            'name' => 'Lightness Raphael',
            'department' => 'Finance & Cashier',
            'email' => 'lightness.raphael@cashier.hms',
        ],
        'STF-2026-000025' => [
            'name' => 'Andongolile Gano',
            'department' => 'Finance & Cashier',
            'email' => 'andongolile.gano@cashier.hms',
        ],
        'STF-2026-000026' => [
            'name' => 'Groly Lucas',
            'department' => 'Finance & Cashier',
            'email' => 'groly.lucas@cashier.hms',
        ],
        'STF-2026-000027' => [
            'name' => 'Christina Kipemba',
            'department' => 'Finance & Cashier',
            'email' => 'christina.kipemba@cashier.hms',
        ],

        // Laboratory — 5
        'STF-2026-000028' => [
            'name' => 'Samwel Boniface',
            'department' => 'Laboratory',
            'email' => 'samwel.boniface@laboratory.hms',
        ],
        'STF-2026-000029' => [
            'name' => 'Husna Omary',
            'department' => 'Laboratory',
            'email' => 'husna.omary@laboratory.hms',
        ],
        'STF-2026-000030' => [
            'name' => 'Cosmas Kigoda',
            'department' => 'Laboratory',
            'email' => 'cosmas.kigoda@laboratory.hms',
        ],
        'STF-2026-000031' => [
            'name' => 'Safi Malimu',
            'department' => 'Laboratory',
            'email' => 'safi.malimu@laboratory.hms',
        ],
        'STF-2026-000032' => [
            'name' => 'Peter Ndayanse',
            'department' => 'Laboratory',
            'email' => 'peter.ndayanse@laboratory.hms',
        ],

        // Nursing — 10
        'STF-2026-000033' => [
            'name' => 'Mohamed Ngongono',
            'department' => 'Inpatient',
            'email' => 'mohamed.ngongono@nursing.hms',
        ],
        'STF-2026-000034' => [
            'name' => 'Simon Ngasa',
            'department' => 'Inpatient',
            'email' => 'simon.ngasa@nursing.hms',
        ],
        'STF-2026-000035' => [
            'name' => 'John Mgaya',
            'department' => 'Inpatient',
            'email' => 'john.mgaya@nursing.hms',
        ],
        'STF-2026-000036' => [
            'name' => 'Tatu Hemedi',
            'department' => 'Inpatient',
            'email' => 'tatu.hemedi@nursing.hms',
        ],
        'STF-2026-000037' => [
            'name' => 'Annastazia David',
            'department' => 'Inpatient',
            'email' => 'annastazia.david@nursing.hms',
        ],
        'STF-2026-000038' => [
            'name' => 'Jesca Mkilanya',
            'department' => 'Inpatient',
            'email' => 'jesca.mkilanya@nursing.hms',
        ],
        'STF-2026-000039' => [
            'name' => 'Edwin Magembe',
            'department' => 'Inpatient',
            'email' => 'edwin.magembe@nursing.hms',
        ],
        'STF-2026-000040' => [
            'name' => 'Swaumu Bakari',
            'department' => 'Inpatient',
            'email' => 'swaumu.bakari@nursing.hms',
        ],
        'STF-2026-000041' => [
            'name' => 'Juliana Mruthu',
            'department' => 'Inpatient',
            'email' => 'juliana.mruthu@nursing.hms',
        ],
        'STF-2026-000042' => [
            'name' => 'Eliania Boniface',
            'department' => 'Inpatient',
            'email' => 'eliania.boniface@nursing.hms',
        ],

        // Radiology — 1
        'STF-2026-000043' => [
            'name' => 'Edward Antony',
            'department' => 'Radiology',
            'email' => 'edward.antony@radiology.hms',
        ],

        // Reception — 4
        'STF-2026-000044' => [
            'name' => 'Adelina Julius',
            'department' => 'Outpatient Department',
            'email' => 'adelina.julius@reception.hms',
        ],
        'STF-2026-000045' => [
            'name' => 'Anatori Mlewa',
            'department' => 'Outpatient Department',
            'email' => 'anatori.mlewa@reception.hms',
        ],
        'STF-2026-000046' => [
            'name' => 'Daima Ibrahim',
            'department' => 'Outpatient Department',
            'email' => 'daima.ibrahim@reception.hms',
        ],
        'STF-2026-000047' => [
            'name' => 'Rachel Antipacy',
            'department' => 'Outpatient Department',
            'email' => 'rachel.antipacy@reception.hms',
        ],

        // Medical Store — 1
        'STF-2026-000048' => [
            'name' => 'Vanathuchinnapparajan R.',
            'department' => 'Central Stores & Inventory',
            'email' => 'vanathuchinnapparajan.r@medicalstore.hms',
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(AuditLogService $auditLogService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $shouldActivate = (bool) $this->option('activate');
        $shouldOverwrite = (bool) $this->option('overwrite');
        $passwordOption = $this->option('password');

        $this->info($isDryRun ? '--- DRY RUN: PREVIEWING EMAIL & PASSWORD ASSIGNMENTS ---' : 'Assigning departmental staff emails & credentials...');

        $updatedCount = 0;
        $skippedCount = 0;
        $tableRows = [];

        DB::beginTransaction();

        try {
            $fakeRequest = Request::create('/staff', 'POST');
            foreach (self::STAFF_EMAILS as $staffNumber => $info) {
                // Find staff by name first (so it matches the correct person in both production and test environments)
                $cleanName = str_replace(['Dr. ', 'Dr '], '', $info['name']);
                $nameParts = explode(' ', trim($cleanName));
                $first = $nameParts[0];
                $last = end($nameParts);
                $staff = Staff::with('user')
                    ->where('first_name', $first)
                    ->where('last_name', $last)
                    ->first();

                if (! $staff) {
                    $staff = Staff::with('user')->where('staff_number', $staffNumber)->first();
                }

                $displayStaffNumber = $staff ? $staff->staff_number : $staffNumber;

                if (! $staff) {
                    $this->warn("Staff record not found for: {$staffNumber} ({$info['name']})");
                    $tableRows[] = [$displayStaffNumber, $info['name'], $info['email'], 'MISSING STAFF', 'Skipped'];
                    $skippedCount++;
                    continue;
                }

                $user = $staff->user;

                if (! $user) {
                    $this->warn("User account not found for staff: {$displayStaffNumber}");
                    $tableRows[] = [$displayStaffNumber, $info['name'], $info['email'], 'MISSING USER', 'Skipped'];
                    $skippedCount++;
                    continue;
                }

                $hasExistingEmail = ! empty($user->email) || ! empty($staff->email);

                if ($hasExistingEmail && ! $shouldOverwrite && ! $passwordOption && ($user->email === $info['email'])) {
                    $tableRows[] = [$displayStaffNumber, $info['name'], $user->email, 'Already Assigned', 'Skipped'];
                    $skippedCount++;
                    continue;
                }

                // Check for duplicate email in users table for a different user
                $duplicateUser = User::where('email', $info['email'])->where('id', '!=', $user->id)->first();
                if ($duplicateUser) {
                    $this->error("Duplicate email detected: {$info['email']} is already assigned to user ID {$duplicateUser->id}");
                    $tableRows[] = [$displayStaffNumber, $info['name'], $info['email'], 'DUPLICATE EMAIL', 'Failed'];
                    $skippedCount++;
                    continue;
                }

                if (! $isDryRun) {
                    $oldEmail = $user->email;

                    // Update staff record
                    $staff->update([
                        'email' => $info['email'],
                    ]);

                    // Update user record
                    $userUpdates = [
                        'email' => $info['email'],
                    ];

                    if ($passwordOption) {
                        $userUpdates['password'] = Hash::make($passwordOption);
                        $userUpdates['status'] = User::STATUS_ACTIVE;
                        $userUpdates['email_verified_at'] = now();
                    } elseif ($shouldActivate) {
                        $userUpdates['status'] = User::STATUS_ACTIVE;
                        $userUpdates['email_verified_at'] = now();
                    }

                    $user->forceFill($userUpdates)->save();

                    // Log audit event
                    $auditLogService->record(
                        'staff_department_email_assigned',
                        $user,
                        ['email' => $oldEmail],
                        ['email' => $info['email'], 'password_reset' => ! empty($passwordOption), 'activated' => ($shouldActivate || ! empty($passwordOption))],
                        $fakeRequest,
                    );
                }

                $statusLabel = $isDryRun
                    ? 'Dry Run OK'
                    : ($passwordOption
                        ? 'Password Set & Active'
                        : ($shouldActivate ? 'Updated & Activated' : 'Updated'));

                $tableRows[] = [$staffNumber, $info['name'], $info['email'], $info['department'], $statusLabel];
                $updatedCount++;
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run completed. No database changes were persisted.');
            } else {
                DB::commit();
                $this->info("Successfully updated {$updatedCount} staff accounts with departmental emails.");
            }

            $this->table(['Staff #', 'Name', 'Assigned Email', 'Department', 'Status'], $tableRows);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to assign staff emails: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
