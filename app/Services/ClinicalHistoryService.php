<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A read-only, clinically-permissioned aggregation of a patient's clinical
 * encounters — distinct from PatientHistoryService, which is deliberately
 * non-clinical and reachable by non-clinical roles (see its own doc-comment).
 * This service creates no new storage: every record it surfaces already
 * lives in its own authoritative table (ClinicalEncounter, TriageAssessment,
 * Consultation, EncounterDiagnosis, CarePlan, ClinicalNote) and is only
 * assembled here for display.
 */
class ClinicalHistoryService
{
    /**
     * Build the patient's clinical timeline, paginated by encounter (not by
     * flattening every child record) to avoid loading a patient's entire
     * lifetime record at once.
     *
     * @param  array{date_from?: string, date_to?: string, department_id?: int|string, practitioner_id?: int|string, encounter_type?: string, status?: string, diagnosis?: string, note_type?: string}  $filters
     */
    public function timeline(Patient $patient, array $filters = []): LengthAwarePaginator
    {
        $query = ClinicalEncounter::query()
            ->where('patient_id', $patient->id)
            ->with([
                'department:id,name,code',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
                'triageAssessment',
                'consultation',
                'diagnoses.diagnosisCode',
                'carePlan.items',
                'clinicalNotes.author:id,UserName',
                // Only RELEASED results are ever shown as final laboratory
                // results on the patient's timeline (Phase 4, Step 35, spec
                // Part 23/29) — filtered in the resource itself rather than
                // here, since the item/order chain is still needed to reach
                // the result regardless of its status.
                'laboratoryOrders.items.result.releasedBy:id,UserName',
                'laboratoryOrders.items.result.verifiedBy:id,UserName',
                'laboratoryOrders.items.result.sample:id,sample_number',
                'laboratoryOrders.activeReport:id,laboratory_order_id,status',
            ]);

        if (! empty($filters['date_from'])) {
            $query->whereDate('started_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('started_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['practitioner_id'])) {
            $query->where('practitioner_id', $filters['practitioner_id']);
        }

        if (! empty($filters['encounter_type'])) {
            $query->where('encounter_type', $filters['encounter_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['diagnosis'])) {
            $search = $filters['diagnosis'];
            $query->whereHas('diagnoses.diagnosisCode', function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['note_type'])) {
            $noteType = $filters['note_type'];
            $query->whereHas('clinicalNotes', fn ($q) => $q->where('note_type', $noteType));
        }

        return $query->orderByDesc('started_at')->orderByDesc('id')->paginate(10)->withQueryString();
    }
}
