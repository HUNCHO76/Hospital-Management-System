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
        $oldValues = $staff->only(array_keys($data));

        $staff->update($data);

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
