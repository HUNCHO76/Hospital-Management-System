<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the central billing service catalog's own lifecycle (Phase 7, Step
 * 53) — create, update, activate, deactivate. Mirrors
 * MedicationService/LaboratoryTestService/RadiologyProcedureService's own
 * shape exactly: `code` locks once the service has been priced
 * (`Service::isReferenced()`), and a service is deactivated, never deleted.
 */
class ServiceCatalogService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, category: string, is_billable?: bool, serviceable_type?: string|null, serviceable_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): Service
    {
        $this->assertValidCategory($data['category']);

        $service = Service::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'serviceable_type' => $data['serviceable_type'] ?? null,
            'serviceable_id' => $data['serviceable_id'] ?? null,
            'is_billable' => $data['is_billable'] ?? true,
            'status' => Service::STATUS_ACTIVE,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'service_created',
            $service,
            null,
            $service->only(['code', 'name', 'category', 'is_billable', 'status']),
            $request,
        );

        return $service;
    }

    /**
     * Update a service's own definition. `code` is silently ignored once
     * the service has been priced (spec §37 — protecting historical
     * financial meaning), mirroring `MedicationService::update()`'s own
     * "locked once referenced" precedent rather than rejecting the whole
     * request over one immutable field.
     *
     * @param  array{code?: string, name?: string, description?: string|null, category?: string, is_billable?: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(Service $service, array $data, Request $request): Service
    {
        if (isset($data['category'])) {
            $this->assertValidCategory($data['category']);
        }

        $before = $service->only(['code', 'name', 'category', 'is_billable']);

        $attributes = [
            'name' => $data['name'] ?? $service->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $service->description,
            'category' => $data['category'] ?? $service->category,
            'is_billable' => array_key_exists('is_billable', $data) ? $data['is_billable'] : $service->is_billable,
            'updated_by' => $request->user()->id,
        ];

        if (isset($data['code']) && ! $service->isReferenced()) {
            $attributes['code'] = $data['code'];
        }

        $service->update($attributes);

        $this->auditLog->record('service_updated', $service, $before, $service->only(['code', 'name', 'category', 'is_billable']), $request);

        return $service->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function activate(Service $service, Request $request): Service
    {
        return $this->transition($service, Service::STATUS_ACTIVE, 'service_activated', $request);
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(Service $service, Request $request): Service
    {
        return $this->transition($service, Service::STATUS_INACTIVE, 'service_deactivated', $request);
    }

    /**
     * @throws ValidationException
     */
    protected function transition(Service $service, string $status, string $action, Request $request): Service
    {
        $service = DB::transaction(function () use ($service, $status, $request) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                throw ValidationException::withMessages([
                    'status' => "This service is already \"{$status}\".",
                ]);
            }

            $locked->update(['status' => $status, 'updated_by' => $request->user()->id]);

            return $locked;
        });

        $this->auditLog->record($action, $service, null, $service->only(['status']), $request);

        return $service->fresh();
    }

    /**
     * @throws ValidationException
     */
    protected function assertValidCategory(string $category): void
    {
        if (! in_array($category, Service::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Invalid service category.']);
        }
    }
}
