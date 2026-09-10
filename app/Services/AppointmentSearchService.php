<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the appointment search/filter query shared by the web and API
 * "index" endpoints, so the two channels never drift into inconsistent
 * search behavior. Callers are responsible for validating input, applying
 * hospital scoping, paginating the result, and authorizing access — this
 * class only shapes the query.
 */
class AppointmentSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['scheduled_start', 'scheduled_end', 'status', 'created_at'];

    /**
     * Build a filtered appointment query from validated search parameters.
     *
     * Supported keys: search (appointment number or patient name/number),
     * patient_id, department_id, practitioner_id, status, date_from,
     * date_to (both scoped to scheduled_start).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Appointment>
     */
    public function query(array $filters): Builder
    {
        $query = Appointment::query();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('appointment_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['patient_id'] ?? null)) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (filled($filters['department_id'] ?? null)) {
            $query->where('department_id', $filters['department_id']);
        }

        if (filled($filters['practitioner_id'] ?? null)) {
            $query->where('practitioner_id', $filters['practitioner_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('scheduled_start', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('scheduled_start', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Restrict a query to appointments booked under the given hospital's
     * departments. Used to enforce hospital-admin scoping — never trust a
     * client-supplied hospital_id, only the authenticated user's own.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas('department', fn ($query) => $query->where('hospital_id', $hospitalId));
    }

    /**
     * Apply a validated sort. Only SORTABLE columns are allowed; unknown
     * columns fall back to the default so a client can never inject an
     * arbitrary ORDER BY expression.
     *
     * @param  Builder<Appointment>  $query
     */
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'scheduled_start', string $defaultDirection = 'asc'): void
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
