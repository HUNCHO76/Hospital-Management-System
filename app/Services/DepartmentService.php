<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a department and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): Department
    {
        $department = Department::create($data);

        $this->auditLog->record('department_created', $department, null, $department->only(array_keys($data)), $request);

        return $department;
    }

    /**
     * Update a department and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data, Request $request): Department
    {
        $oldValues = $department->only(array_keys($data));

        $department->update($data);

        $this->auditLog->record('department_updated', $department, $oldValues, $department->only(array_keys($data)), $request);

        return $department;
    }

    /**
     * Soft-delete a department, refusing when it still has staff assigned to it.
     *
     * @throws ValidationException
     */
    public function delete(Department $department, Request $request): void
    {
        if ($department->staff()->exists()) {
            throw ValidationException::withMessages([
                'department' => 'This department cannot be deleted because it still has staff assigned to it.',
            ]);
        }

        $oldValues = $department->only(['hospital_id', 'name', 'code', 'status']);

        $department->delete();

        $this->auditLog->record('department_deleted', $department, $oldValues, null, $request);
    }
}
