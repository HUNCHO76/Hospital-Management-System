<?php

namespace App\Services;

use App\Models\Medication;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the medication search/filter query used by the search-json
 * endpoint — mirrors DiagnosisCodeSearchService exactly. Callers are
 * responsible for validating input, paginating the result, and authorizing
 * access — this class only shapes the query.
 */
class MedicationSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'category', 'created_at'];

    /**
     * Build a filtered medication query from validated search parameters.
     *
     * Supported keys: search (free text across name/category), category,
     * status.
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

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
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
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'name', string $defaultDirection = 'asc'): void
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
