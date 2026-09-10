<?php

namespace App\Services;

use App\Models\DiagnosisCode;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the diagnosis-code search/filter query shared by the web
 * search-json endpoint and the API index endpoint, so the two channels never
 * drift into inconsistent search behavior — mirrors PatientSearchService.
 * Callers are responsible for validating input, paginating the result, and
 * authorizing access — this class only shapes the query.
 */
class DiagnosisCodeSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['code', 'description', 'category', 'created_at'];

    /**
     * Build a filtered diagnosis-code query from validated search
     * parameters.
     *
     * Supported keys: search (free text across code/description/category),
     * coding_system, category, status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<DiagnosisCode>
     */
    public function query(array $filters): Builder
    {
        $query = DiagnosisCode::query();

        if (filled($filters['coding_system'] ?? null)) {
            $query->where('coding_system', $filters['coding_system']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
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
     * @param  Builder<DiagnosisCode>  $query
     */
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'code', string $defaultDirection = 'asc'): void
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
