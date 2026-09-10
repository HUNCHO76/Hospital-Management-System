<?php

namespace App\Services;

use App\Models\InsuranceScheme;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InsuranceSchemeService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create an insurance scheme and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): InsuranceScheme
    {
        $scheme = InsuranceScheme::create($data);

        $this->auditLog->record('insurance_scheme_created', $scheme, null, $scheme->only(array_keys($data)), $request);

        return $scheme;
    }

    /**
     * Update an insurance scheme and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(InsuranceScheme $scheme, array $data, Request $request): InsuranceScheme
    {
        $oldValues = $scheme->only(array_keys($data));

        $scheme->update($data);

        $this->auditLog->record('insurance_scheme_updated', $scheme, $oldValues, $scheme->only(array_keys($data)), $request);

        return $scheme;
    }

    /**
     * Soft-delete an insurance scheme, refusing when patients still hold
     * insurance under it.
     *
     * @throws ValidationException
     */
    public function delete(InsuranceScheme $scheme, Request $request): void
    {
        if ($scheme->patientInsurances()->exists()) {
            throw ValidationException::withMessages([
                'scheme' => 'This scheme cannot be deleted because patients still hold insurance under it.',
            ]);
        }

        $oldValues = $scheme->only(['insurance_provider_id', 'name', 'code', 'status']);

        $scheme->delete();

        $this->auditLog->record('insurance_scheme_deleted', $scheme, $oldValues, null, $request);
    }
}
