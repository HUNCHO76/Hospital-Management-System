<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Models\User;
use App\Services\StaffAccountActivationService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivateStaffAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hms:activate-staff-account
                            {staff : The staff number (e.g. STF-2026-000017) or username}
                            {--email= : The verified real email address of the staff member}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a secure activation link for an individual staff account';

    public function handle(StaffAccountActivationService $activationService): int
    {
        $identifier = $this->argument('staff');
        $email = $this->option('email');

        $staff = Staff::query()
            ->where('staff_number', $identifier)
            ->orWhereHas('user', fn ($q) => $q->where('UserName', $identifier))
            ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), $identifier)
            ->first();

        if (! $staff) {
            $this->error("Staff member \"{$identifier}\" not found.");
            return self::FAILURE;
        }

        $user = $staff->user;
        if (! $user) {
            $this->error("Staff member {$staff->staff_number} does not have an attached user account.");
            return self::FAILURE;
        }

        if (empty($email) && empty($user->email)) {
            $this->error('An email address is required to activate the account. Pass --email=staff@domain.com');
            return self::FAILURE;
        }

        try {
            $fakeRequest = Request::create("/staff/{$staff->id}/activation", 'POST');
            $result = $activationService->createActivationInvitation(
                $user,
                $email ?: $user->email,
                null,
                $fakeRequest,
            );

            $this->info("Activation link generated for {$staff->first_name} {$staff->last_name} ({$staff->staff_number}):");
            $this->line($result['activationUrl']);
            $this->comment("Link expires at: {$result['activation']->expires_at->toDateTimeString()}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to generate activation link: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
