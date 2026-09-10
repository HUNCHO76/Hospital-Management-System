<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the clinical encounter search/filter query shared by the web and
 * API "index" endpoints. Callers are responsible for validating input,
 * applying hospital scoping, paginating the result, and authorizing access —
 * this class only shapes the query.
 */
class ClinicalEncounterSearchService
{
    /**
     * Columns a caller may sort search results by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['created_at', 'started_at', 'ended_at', 'status'];

    /**
     * The dashboard/worklist quick-filter groups (Step 27, spec §23/24) — a
     * convenience layer over conditions not expressible with a plain
     * `status` equality alone, since "ready for consultation" etc. depend on
     * the triage/consultation relations, not the encounter's own status
     * column.
     *
     * @var list<string>
     */
    public const WORKFLOW_STAGES = ['waiting_for_triage', 'ready_for_consultation', 'in_consultation', 'completed'];

    /**
     * Build a filtered clinical encounter query from validated search
     * parameters.
     *
     * Supported keys: search (encounter number or patient name/number),
     * patient_id, department_id, practitioner_id, status, encounter_type,
     * date_from, date_to (both scoped to created_at), workflow_stage (one of
     * WORKFLOW_STAGES).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ClinicalEncounter>
     */
    public function query(array $filters): Builder
    {
        $query = ClinicalEncounter::query();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('encounter_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
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

        if (filled($filters['encounter_type'] ?? null)) {
            $query->where('encounter_type', $filters['encounter_type']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (in_array($filters['workflow_stage'] ?? null, self::WORKFLOW_STAGES, true)) {
            $this->applyWorkflowStage($query, $filters['workflow_stage']);
        }

        return $query;
    }

    /**
     * @param  Builder<ClinicalEncounter>  $query
     */
    protected function applyWorkflowStage(Builder $query, string $stage): void
    {
        match ($stage) {
            'waiting_for_triage' => $query->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
                ->whereDoesntHave('triageAssessment', fn ($q) => $q->where('status', 'completed')),
            'ready_for_consultation' => $query->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
                ->whereHas('triageAssessment', fn ($q) => $q->where('status', 'completed'))
                ->whereDoesntHave('consultation'),
            'in_consultation' => $query->whereHas('consultation', fn ($q) => $q->where('status', 'in_progress')),
            'completed' => $query->where('status', ClinicalEncounter::STATUS_COMPLETED),
            default => null,
        };
    }

    /**
     * Restrict a query to encounters booked under the given hospital's
     * departments. Used to enforce hospital-admin scoping — never trust a
     * client-supplied hospital_id, only the authenticated user's own.
     *
     * @param  Builder<ClinicalEncounter>  $query
     * @return Builder<ClinicalEncounter>
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
     * @param  Builder<ClinicalEncounter>  $query
     */
    public function applySort(Builder $query, ?string $sort, string $defaultColumn = 'created_at', string $defaultDirection = 'desc'): void
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
