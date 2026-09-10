<?php

namespace App\Services;

use App\Models\LaboratoryTest;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the laboratory test search/filter query shared by the lightweight
 * search-json combobox endpoint (Step 28) and the full catalog list page
 * (Step 29) — mirrors ClinicalEncounterSearchService's "one service, two
 * callers" shape. Callers are responsible for validating input, paginating
 * the result, and authorizing access — this class only shapes the query.
 */
class LaboratoryTestSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'code', 'created_at'];

    /**
     * Build a filtered laboratory test query from validated search
     * parameters.
     *
     * Supported keys: search (free text across code/name/short_name),
     * category_id, department_id, specimen_type, result_type, status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LaboratoryTest>
     */
    public function query(array $filters): Builder
    {
        $query = LaboratoryTest::query();

        if (filled($filters['category_id'] ?? null)) {
            $query->where('category_id', $filters['category_id']);
        }

        if (filled($filters['department_id'] ?? null)) {
            $query->where('department_id', $filters['department_id']);
        }

        if (filled($filters['specimen_type'] ?? null)) {
            $query->where('specimen_type', $filters['specimen_type']);
        }

        if (filled($filters['result_type'] ?? null)) {
            $query->where('result_type', $filters['result_type']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Apply a validated sort. Only SORTABLE columns are allowed; unknown
     * columns fall back to the default so a client can never inject an
     * arbitrary ORDER BY expression.
     *
     * @param  Builder<LaboratoryTest>  $query
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
