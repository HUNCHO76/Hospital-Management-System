<?php

namespace App\Services;

use App\Models\PrescriptionOrder;
use App\Models\PrescriptionVerification;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the pharmacy's cross-encounter prescriptions worklist query. Since
 * Phase 6, Step 46 promoted Prescription to a first-class PrescriptionOrder
 * entity (mirroring how Radiology/Laboratory were each promoted out of
 * CarePlanItem nesting in their own steps), this queries PrescriptionOrder
 * directly — the pre-existing CarePlanItem-nested Prescription is no longer
 * this worklist's data source.
 */
class PharmacySearchService
{
    /**
     * Supported keys: search (patient name/number, medication name, or
     * prescription/encounter number), status, date_from, date_to (against
     * prescribed_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PrescriptionOrder>
     */
    public function prescriptionsQuery(array $filters): Builder
    {
        $query = PrescriptionOrder::query()
            ->with([
                'items.medication:id,generic_name,category',
                'prescriber:id,UserName',
                'clinicalEncounter:id,encounter_number,patient_id,department_id',
                'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
                'clinicalEncounter.department:id,name',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('prescription_number', 'like', "%{$search}%")
                    ->orWhereHas('items.medication', function ($query) use ($search) {
                        $query->where('generic_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('clinicalEncounter', function ($query) use ($search) {
                        $query->where('encounter_number', 'like', "%{$search}%");
                    })
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

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('prescribed_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('prescribed_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('prescribed_at');
    }

    /**
     * Restrict a prescriptionsQuery() result to orders booked under the
     * given hospital's departments.
     *
     * @param  Builder<PrescriptionOrder>  $query
     * @return Builder<PrescriptionOrder>
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

    /**
     * Build the pharmacy verification worklist query (Phase 6, Step 47) —
     * every PrescriptionOrder with its own PrescriptionVerification review,
     * joined so the status filter/default and search can reach both.
     * Defaults to PENDING_VERIFICATION when no status filter is given,
     * mirroring RadiologyReportSearchService::verificationQuery() exactly.
     *
     * Supported keys: search (patient name/number, medication name, or
     * prescription/encounter number), status, date_from, date_to (against
     * submitted_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PrescriptionOrder>
     */
    public function verificationQueue(array $filters): Builder
    {
        $query = PrescriptionOrder::query()
            ->join('prescription_verifications', 'prescription_verifications.prescription_order_id', '=', 'prescription_orders.id')
            ->select('prescription_orders.*')
            ->with([
                'items.medication:id,generic_name,category',
                'prescriber:id,UserName',
                'verification.submittedBy:id,UserName',
                'clinicalEncounter:id,encounter_number,patient_id,department_id',
                'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name',
                'clinicalEncounter.department:id,name',
            ]);

        $status = $filters['status'] ?? null;

        if (filled($status)) {
            $query->where('prescription_verifications.status', $status);
        } else {
            $query->where('prescription_verifications.status', PrescriptionVerification::STATUS_PENDING_VERIFICATION);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('prescription_orders.prescription_number', 'like', "%{$search}%")
                    ->orWhereHas('items.medication', function ($query) use ($search) {
                        $query->where('generic_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('clinicalEncounter', function ($query) use ($search) {
                        $query->where('encounter_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('prescription_verifications.submitted_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('prescription_verifications.submitted_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('prescription_verifications.submitted_at');
    }
}
