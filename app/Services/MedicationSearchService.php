<?php

namespace App\Services;

use App\Models\Medication;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the medication search/filter query shared by the lightweight
 * search-json combobox endpoint (Step 44) and the full catalog list page
 * (Step 45) — mirrors LaboratoryTestSearchService/RadiologyProcedureSearchService's
 * "one service, two callers" shape. Callers are responsible for validating
 * input, paginating the result, and authorizing access — this class only
 * shapes the query.
 */
class MedicationSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['code', 'generic_name', 'category', 'form', 'created_at'];

    /**
     * Build a filtered medication query from validated search parameters.
     *
     * Supported keys: search (free text across code/generic_name/
     * brand_name), category, form, route, status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Medication>
     */
    public function query(array $filters): Builder
    {
        $query = Medication::query();

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['form'] ?? null)) {
            $query->where('form', $filters['form']);
        }

        if (filled($filters['route'] ?? null)) {
            $query->where('route', $filters['route']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('generic_name', 'like', "%{$search}%")
                    ->orWhere('brand_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Apply a validated sort. Only SORTABLE columns are allowed; unknown
     * columns fall back to the default so a client can never inject an
     * arbitrary ORDER BY expression.
     *
     * @param  Builder<Medication>  $query
     */
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'generic_name', string $defaultDirection = 'asc'): void
    {
        if (! $sort) {
            $query->orderBy($defaultColumn, $defaultDirection);

            return;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            $query->orderBy($defaultColumn, $defaultDirection);

            return;
        }

        $query->orderBy($column, $direction);
    }
}
