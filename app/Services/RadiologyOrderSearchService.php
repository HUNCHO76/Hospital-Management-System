<?php

namespace App\Services;

use App\Models\RadiologyOrder;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the radiology order search/filter query for the Radiology
 * worklist — mirrors LaboratoryOrderSearchService's shape exactly.
 * RadiologyOrder belongs directly to ClinicalEncounter (Phase 5, Step 36),
 * so filters reach the patient/encounter/practitioner/department through
 * that single relation.
 */
class RadiologyOrderSearchService
{
    /**
     * Build a filtered radiology order query from validated search
     * parameters.
     *
     * Supported keys: search (order number or patient name/number), status,
     * priority, practitioner_id, department_id, date_from, date_to (both
     * scoped to ordered_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyOrder>
     */
    public function query(array $filters): Builder
    {
        $query = RadiologyOrder::query();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('priority', $filters['priority']);
        }

        if (filled($filters['modality'] ?? null)) {
            $query->whereHas('items', fn ($query) => $query->where('modality_snapshot', $filters['modality']));
        }

        if (filled($filters['practitioner_id'] ?? null)) {
            $query->whereHas('clinicalEncounter', fn ($query) => $query->where('practitioner_id', $filters['practitioner_id']));
        }

        if (filled($filters['department_id'] ?? null)) {
            $query->whereHas('clinicalEncounter', fn ($query) => $query->where('department_id', $filters['department_id']));
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('ordered_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('ordered_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Restrict a query to orders booked under the given hospital's
     * departments. Used to enforce hospital-admin scoping — never trust a
     * client-supplied hospital_id, only the authenticated user's own.
     *
     * @param  Builder<RadiologyOrder>  $query
     * @return Builder<RadiologyOrder>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }
}
