<?php

namespace App\Services;

use App\Models\Patient;
use App\Support\Patients\PhoneNormalizer;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the patient search/filter query shared by the web and API "index"
 * endpoints, so the two channels never drift into inconsistent search
 * behavior. Callers are responsible for validating input, paginating the
 * result, and authorizing access — this class only shapes the query.
 */
class PatientSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['patient_number', 'first_name', 'last_name', 'date_of_birth', 'created_at', 'updated_at'];

    /**
     * Build a filtered patient query from validated search parameters.
     *
     * Supported keys: patient_number, national_id, passport_number, phone,
     * date_of_birth, gender, status, search (free text: name/number/phone/
     * identifiers, tolerant of multi-word full-name queries).
     *
     * Exact-identifier parameters (patient_number, national_id,
     * passport_number, phone) are matched precisely rather than with a
     * wildcard scan — both because that's what an identifier lookup means and
     * because it stays index-friendly. `search` is the only fuzzy/wildcard path
     * and is reserved for free-text lookups where the user doesn't have a
     * specific field in mind.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Patient>
     */
    public function query(array $filters): Builder
    {
        $query = Patient::query();

        if (filled($filters['patient_number'] ?? null)) {
            // An exact business-identifier lookup needs nothing else — skip
            // any broader scan entirely once it's supplied.
            return $query->where('patient_number', trim($filters['patient_number']));
        }

        if (filled($filters['national_id'] ?? null)) {
            $query->where('national_id', trim($filters['national_id']));
        }

        if (filled($filters['passport_number'] ?? null)) {
            $query->where('passport_number', trim($filters['passport_number']));
        }

        if (filled($filters['phone'] ?? null)) {
            $variants = PhoneNormalizer::variants($filters['phone']);
            $query->when($variants !== [], fn ($query) => $query->whereIn('phone', $variants));
        }

        if (filled($filters['date_of_birth'] ?? null)) {
            $query->whereDate('date_of_birth', $filters['date_of_birth']);
        }

        if (filled($filters['gender'] ?? null)) {
            $query->where('gender', $filters['gender']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $this->applyFreeTextSearch($query, $search);
        }

        return $query;
    }

    /**
     * Apply the free-text search: matches the identifier columns directly,
     * plus a whitespace-tolerant multi-word match across the name columns so
     * "John Michael Smith" finds a patient regardless of column order.
     *
     * @param  Builder<Patient>  $query
     */
    protected function applyFreeTextSearch(Builder $query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $query->where('patient_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('national_id', 'like', "%{$search}%")
                ->orWhere('passport_number', 'like', "%{$search}%");

            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

            if (count($tokens) > 1) {
                $query->orWhere(function ($query) use ($tokens) {
                    foreach ($tokens as $token) {
                        $query->where(function ($query) use ($token) {
                            $query->where('first_name', 'like', "%{$token}%")
                                ->orWhere('middle_name', 'like', "%{$token}%")
                                ->orWhere('last_name', 'like', "%{$token}%");
                        });
                    }
                });
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search)) {
                $query->orWhereDate('date_of_birth', $search);
            }
        });
    }

    /**
     * Apply a validated sort. Only SORTABLE columns are allowed; unknown
     * columns fall back to the default so a client can never inject an
     * arbitrary ORDER BY expression.
     *
     * @param  Builder<Patient>  $query
     */
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'last_name', string $defaultDirection = 'asc'): void
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
