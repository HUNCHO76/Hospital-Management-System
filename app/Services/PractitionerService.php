<?php

namespace App\Services;

use App\Models\Practitioner;
use Illuminate\Http\Request;

class PractitionerService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a practitioner profile for an existing staff member and record
     * the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): Practitioner
    {
        $practitioner = Practitioner::create($data);

        $this->auditLog->record('practitioner_created', $practitioner, null, $practitioner->only(array_keys($data)), $request);

        return $practitioner;
    }

    /**
     * Update a practitioner profile and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Practitioner $practitioner, array $data, Request $request): Practitioner
    {
        $oldValues = $practitioner->only(array_keys($data));

        $practitioner->update($data);

        $this->auditLog->record('practitioner_updated', $practitioner, $oldValues, $practitioner->only(array_keys($data)), $request);

        return $practitioner;
    }

    /**
     * Remove a practitioner profile. The underlying staff record is
     * untouched — this only retires their clinical profile.
     */
    public function delete(Practitioner $practitioner, Request $request): void
    {
        $oldValues = $practitioner->only(['staff_id', 'practitioner_number', 'practitioner_type', 'status']);

        $practitioner->delete();

        $this->auditLog->record('practitioner_deleted', $practitioner, $oldValues, null, $request);
    }
}
