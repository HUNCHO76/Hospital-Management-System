<?php

namespace App\Services;

use App\Models\RadiologyProcedure;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the radiology procedure search query shared by the lightweight
 * search-json combobox endpoint (Step 36) and any future full catalog list
 * page (Step 37) — mirrors LaboratoryTestSearchService's "one service, two
 * callers" shape. Callers are responsible for validating input, paginating
 * the result, and authorizing access — this class only shapes the query.
 */
class RadiologyProcedureSearchService
{
    /**
     * Build a filtered radiology procedure query from validated search
     * parameters.
     *
     * Supported keys: search (free text across code/name), modality
     * (serves both the catalog's "Category" and "Modality" filters — see
     * RadiologyProcedure's own docblock for why there is only one field),
     * body_site (matched within the `body_sites` JSON list), department_id,
     * status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyProcedure>
     */
    public function query(array $filters): Builder
    {
        $query = RadiologyProcedure::query();

        if (filled($filters['modality'] ?? null)) {
            $query->where('modality', $filters['modality']);
        }

        if (filled($filters['body_site'] ?? null)) {
            $query->whereJsonContains('body_sites', $filters['body_site']);
        }

        if (filled($filters['department_id'] ?? null)) {
            $query->where('department_id', $filters['department_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
