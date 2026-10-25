<?php

namespace App\Services;

use App\Models\StaffAccountActivation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StaffAccountActivationService
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Create or regenerate an activation token and return the secure activation URL.
     *
     * @return array{activation: StaffAccountActivation, plainToken: string, activationUrl: string}
     */
    public function createActivationInvitation(
        User $user,
        ?string $verifiedEmail = null,
        ?User $actor = null,
        ?Request $request = null,
    ): array {
        if ($verifiedEmail !== null) {
            $existing = User::query()
                ->where('email', $verifiedEmail)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'email' => "The email \"{$verifiedEmail}\" is already assigned to another user account.",
                ]);
            }
        }

        $result = DB::transaction(function () use ($user, $verifiedEmail, $actor, $request) {
            if ($verifiedEmail !== null && $user->email !== $verifiedEmail) {
                $oldEmail = $user->email;
                $user->update(['email' => $verifiedEmail]);

                if ($request) {
                    $this->auditLog->record(
                        'staff_account_email_updated',
                        $user,
                        ['email' => $oldEmail],
                        ['email' => $verifiedEmail],
                        $request,
                    );
                }
            }

            return StaffAccountActivation::createForUser(
                $user,
                $user->staff,
                $verifiedEmail ?? $user->email,
                $actor,
            );
        });

        $url = url('/account-activation/' . $result['plainToken']);

        if ($request) {
            $this->auditLog->record(
                'staff_account_activation_link_generated',
                $user,
                null,
                [
                    'user_id' => $user->id,
                    'staff_id' => $user->staff?->id,
                    'expires_at' => $result['activation']->expires_at->toIso8601String(),
                ],
                $request,
            );
        }

        return [
            'activation' => $result['activation'],
            'plainToken' => $result['plainToken'],
            'activationUrl' => $url,
        ];
    }

    /**
     * Complete staff account activation with password setup.
     *
     * @throws ValidationException
     */
    public function completeActivation(
        string $plainToken,
        string $password,
        ?Request $request = null,
    ): User {
        $activation = StaffAccountActivation::findValidByPlainToken($plainToken);

        if (! $activation) {
            throw ValidationException::withMessages([
                'token' => 'This account activation link is invalid or has expired. Please contact an administrator.',
            ]);
        }

        $user = $activation->user;

        DB::transaction(function () use ($activation, $user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ])->save();

            $activation->update([
                'activated_at' => now(),
            ]);
        });

        if ($request) {
            $this->auditLog->record(
                'staff_account_activated',
                $user,
                ['status' => User::STATUS_INACTIVE],
                ['status' => User::STATUS_ACTIVE, 'activated_at' => now()->toIso8601String()],
                $request,
            );
        }

        return $user;
    }
}
