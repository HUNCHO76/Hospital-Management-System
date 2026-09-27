<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the service-catalog and pricing search/filter queries (Phase 7,
 * Step 53) — mirrors MedicationSearchService/StockSearchService's "one
 * query builder, several callers" shape.
 */
class ServiceCatalogSearchService
{
    /**
     * Supported keys: search (code/name), category, status, is_billable.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Service>
     */
    public function servicesQuery(array $filters): Builder
    {
        $query = Service::query()->withCount('prices');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['is_billable'] ?? null)) {
            $query->where('is_billable', $filters['is_billable'] === '1');
        }

        return $query->orderBy('name');
    }

    /**
     * Supported keys: search (service code/name), service_id, hospital_id,
     * status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ServicePrice>
     */
    public function pricesQuery(array $filters): Builder
    {
        $query = ServicePrice::query()->with([
            'service:id,code,name',
            'hospital:id,name',
            'createdBy:id,UserName',
            'practitioner:id,staff_id,practitioner_number',
            'practitioner.staff:id,first_name,last_name',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->whereHas('service', function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (filled($filters['service_id'] ?? null)) {
            $query->where('service_id', $filters['service_id']);
        }

        if (filled($filters['hospital_id'] ?? null)) {
            $query->where('hospital_id', $filters['hospital_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('effective_from');
    }

    /**
     * @param  Builder<ServicePrice>  $query
     * @return Builder<ServicePrice>
     */
    public function scopePricesToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('hospital_id', $hospitalId);
    }
}
