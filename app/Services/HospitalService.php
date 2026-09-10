<?php

namespace App\Services;

use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a hospital and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): Hospital
    {
        $hospital = Hospital::create($data);

        $this->auditLog->record('hospital_created', $hospital, null, $hospital->only(array_keys($data)), $request);

        return $hospital;
    }

    /**
     * Update a hospital and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Hospital $hospital, array $data, Request $request): Hospital
    {
        $oldValues = $hospital->only(array_keys($data));

        $hospital->update($data);

        $this->auditLog->record('hospital_updated', $hospital, $oldValues, $hospital->only(array_keys($data)), $request);

        return $hospital;
    }

    /**
     * Soft-delete a hospital, refusing when it still has staff assigned to it.
     *
     * @throws ValidationException
     */
    public function delete(Hospital $hospital, Request $request): void
    {
        if ($hospital->departments()->exists()) {
            throw ValidationException::withMessages([
                'hospital' => 'This hospital cannot be deleted because it still has departments. Remove or reassign them first.',
            ]);
        }

        if ($hospital->staff()->exists()) {
            throw ValidationException::withMessages([
                'hospital' => 'This hospital cannot be deleted because it still has staff assigned to it.',
            ]);
        }

        $oldValues = $hospital->only(['name', 'code', 'status']);

        $hospital->delete();

        $this->auditLog->record('hospital_deleted', $hospital, $oldValues, null, $request);
    }
}
