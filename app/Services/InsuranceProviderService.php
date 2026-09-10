<?php

namespace App\Services;

use App\Models\InsuranceProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InsuranceProviderService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create an insurance provider and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Request $request): InsuranceProvider
    {
        $provider = InsuranceProvider::create($data);

        $this->auditLog->record('insurance_provider_created', $provider, null, $provider->only(array_keys($data)), $request);

        return $provider;
    }

    /**
     * Update an insurance provider and record the change in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(InsuranceProvider $provider, array $data, Request $request): InsuranceProvider
    {
        $oldValues = $provider->only(array_keys($data));

        $provider->update($data);

        $this->auditLog->record('insurance_provider_updated', $provider, $oldValues, $provider->only(array_keys($data)), $request);

        return $provider;
    }

    /**
     * Soft-delete an insurance provider, refusing when it still has schemes
     * or patient insurance records depending on it.
     *
     * @throws ValidationException
     */
    public function delete(InsuranceProvider $provider, Request $request): void
    {
        if ($provider->schemes()->exists()) {
            throw ValidationException::withMessages([
                'provider' => 'This provider cannot be deleted because it still has insurance schemes. Remove or reassign them first.',
            ]);
        }

        if ($provider->patientInsurances()->exists()) {
            throw ValidationException::withMessages([
                'provider' => 'This provider cannot be deleted because patients still hold insurance under it.',
            ]);
        }

        $oldValues = $provider->only(['name', 'code', 'status']);

        $provider->delete();

        $this->auditLog->record('insurance_provider_deleted', $provider, $oldValues, null, $request);
    }
}
