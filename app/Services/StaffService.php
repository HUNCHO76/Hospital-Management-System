<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a staff member, optionally with a linked login account and
     * role, in a single transaction. Record the change in the audit log.
     *
     * @param  array<string, mixed>  $staffData
     * @param  array{email: string, password: string, role: string}|null  $accountData
     */
    public function create(array $staffData, ?array $accountData, Request $request): Staff
    {
        $staff = DB::transaction(function () use ($staffData, $accountData) {
            $staff = Staff::create($staffData);

            if ($accountData !== null) {
                $user = User::create([
                    'UserName' => $staff->full_name,
                    'email' => $accountData['email'],
                    'password' => $accountData['password'],
                    'status' => User::STATUS_ACTIVE,
                ]);

                $user->assignRole($accountData['role']);

                $staff->update(['user_id' => $user->id]);
            }

            return $staff;
        });

        $this->auditLog->record(
            'staff_created',
            $staff,
            null,
            [...$staffData, 'account_created' => $accountData !== null],
            $request,
        );

        return $staff;
    }

    /**
     * Update a staff member and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Staff $staff, array $data, Request $request): Staff
    {
        $role = $data['role'] ?? null;
        $userStatus = $data['user_status'] ?? null;
        $password = $data['password'] ?? null;
        $createAccount = $data['create_account'] ?? false;
        unset($data['role'], $data['user_status'], $data['password'], $data['create_account']);

        $oldValues = $staff->only(array_keys($data));

        DB::transaction(function () use ($staff, $data, $role, $userStatus, $password, $createAccount, $request) {
            $staff->update($data);

            if ($staff->user) {
                $user = $staff->user;
                $userUpdates = [
                    'UserName' => $staff->full_name,
                    'phone' => $staff->phone,
                ];
                if (filled($staff->email)) {
                    $userUpdates['email'] = $staff->email;
                }
                if (filled($userStatus)) {
                    $userUpdates['status'] = $userStatus;
                }
                if (filled($password)) {
                    $userUpdates['password'] = $password;
                }
                $user->update($userUpdates);

                if (filled($role)) {
                    $oldRoles = $user->getRoleNames()->all();
                    $user->syncRoles([$role]);
                    $this->auditLog->record('user_roles_synced', $user, ['roles' => $oldRoles], ['roles' => [$role]], $request);
                }
            } elseif ($createAccount && filled($staff->email) && filled($role)) {
                $user = User::create([
                    'UserName' => $staff->full_name,
                    'email' => $staff->email,
                    'phone' => $staff->phone,
                    'password' => $password ?: 'password',
                    'status' => $userStatus ?: User::STATUS_ACTIVE,
                ]);

                $user->assignRole($role);
                $staff->update(['user_id' => $user->id]);

                $this->auditLog->record('user_account_created', $user, null, ['roles' => [$role]], $request);
            }
        });

        $this->auditLog->record('staff_updated', $staff, $oldValues, $staff->only(array_keys($data)), $request);

        return $staff;
    }

    /**
     * Soft-delete a staff member, refusing when a practitioner profile still
     * depends on them.
     *
     * @throws ValidationException
     */
    public function delete(Staff $staff, Request $request): void
    {
        if ($staff->practitioner()->exists()) {
            throw ValidationException::withMessages([
                'staff' => 'This staff member cannot be deleted because they still have a practitioner profile. Remove it first.',
            ]);
        }

        $oldValues = $staff->only(['staff_number', 'first_name', 'last_name', 'status']);

        $staff->delete();

        $this->auditLog->record('staff_deleted', $staff, $oldValues, null, $request);
    }
}
