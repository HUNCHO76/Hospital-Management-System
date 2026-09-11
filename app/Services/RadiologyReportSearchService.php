<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the radiology reporting worklist query (Phase 5, Step 42, Part 5):
 * completed examinations, whether or not they have a report yet, and
 * whatever status that report is currently at. Mirrors
 * RadiologySchedulingSearchService's shape exactly. Extended in Phase 5,
 * Step 43 with the verification worklist query (verificationQuery()) —
 * kept in this same service rather than a new one, since both queries serve
 * the same underlying report/examination relationship, just from different
 * anchor tables and default filters.
 */
class RadiologyReportSearchService
{
    /**
     * Examinations eligible for reporting. Defaults to every COMPLETED
     * examination (so a fresh, not-yet-started report still appears — Part
     * 6's "completed examinations appear in reporting worklist," not only
     * ones already drafted); an explicit `report_status` filter narrows to
     * `none` (no report row yet), `draft`, or `submitted`.
     *
     * Supported keys: search (order number, procedure, or patient
     * name/number), report_status, modality, priority, date, date_from,
     * date_to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyAppointment>
     */
    public function worklistQuery(array $filters): Builder
    {
        $query = RadiologyAppointment::query()
            ->join('radiology_order_items', 'radiology_order_items.id', '=', 'radiology_appointments.radiology_order_item_id')
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->leftJoin('radiology_reports', 'radiology_reports.radiology_appointment_id', '=', 'radiology_appointments.id')
            ->select('radiology_appointments.*')
            ->where('radiology_appointments.status', RadiologyAppointment::STATUS_COMPLETED);

        $reportStatus = $filters['report_status'] ?? null;

        if ($reportStatus === 'none') {
            $query->whereNull('radiology_reports.id');
        } elseif (filled($reportStatus)) {
            $query->where('radiology_reports.status', $reportStatus);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_order_items.procedure_code', 'like', "%{$search}%")
                    ->orWhere('radiology_order_items.procedure_name', 'like', "%{$search}%")
                    ->orWhere('radiology_orders.order_number', 'like', "%{$search}%")
                    ->orWhereHas('radiologyOrderItem.radiologyOrder.clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['modality'] ?? null)) {
            $query->where('radiology_appointments.modality', $filters['modality']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('radiology_orders.priority', $filters['priority']);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('radiology_appointments.completed_at', $filters['date']);
        } else {
            if (filled($filters['date_from'] ?? null)) {
                $query->where('radiology_appointments.completed_at', '>=', $filters['date_from']);
            }

            if (filled($filters['date_to'] ?? null)) {
                $query->where('radiology_appointments.completed_at', '<=', $filters['date_to']);
            }
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_appointments.completed_at');
    }

    /**
     * Restrict the worklist to examinations booked under the given
     * hospital's departments.
     *
     * @param  Builder<RadiologyAppointment>  $query
     * @return Builder<RadiologyAppointment>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'radiologyOrderItem.radiologyOrder.clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }

    /**
     * Builds the verification worklist query (Phase 5, Step 43, Part 6):
     * anchored on RadiologyReport itself (a report must already exist to be
     * verified — Part 7's own eligibility chain starts there), defaulting to
     * SUBMITTED (the actionable queue) unless an explicit `status` filter
     * asks for something else (e.g. reviewing already-VERIFIED/RETURNED
     * history). Sorted by clinical priority first, then submission time —
     * never a user-toggleable sort (Part 6).
     *
     * Supported keys: search (order number, procedure, or patient
     * name/number), status, modality, priority, reporting_practitioner,
     * date, date_from, date_to (all against submitted_at).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyReport>
     */
    public function verificationQuery(array $filters): Builder
    {
        $query = RadiologyReport::query()
            ->join('radiology_appointments', 'radiology_appointments.id', '=', 'radiology_reports.radiology_appointment_id')
            ->join('radiology_order_items', 'radiology_order_items.id', '=', 'radiology_appointments.radiology_order_item_id')
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->select('radiology_reports.*');

        $status = $filters['status'] ?? null;

        if (filled($status)) {
            $query->where('radiology_reports.status', $status);
        } else {
            $query->where('radiology_reports.status', RadiologyReport::STATUS_SUBMITTED);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_order_items.procedure_code', 'like', "%{$search}%")
                    ->orWhere('radiology_order_items.procedure_name', 'like', "%{$search}%")
                    ->orWhere('radiology_orders.order_number', 'like', "%{$search}%")
                    ->orWhereHas('radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['modality'] ?? null)) {
            $query->where('radiology_appointments.modality', $filters['modality']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('radiology_orders.priority', $filters['priority']);
        }

        if (filled($filters['reporting_practitioner'] ?? null)) {
            $query->where('radiology_reports.reporting_practitioner_id', $filters['reporting_practitioner']);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('radiology_reports.submitted_at', $filters['date']);
        } else {
            if (filled($filters['date_from'] ?? null)) {
                $query->where('radiology_reports.submitted_at', '>=', $filters['date_from']);
            }

            if (filled($filters['date_to'] ?? null)) {
                $query->where('radiology_reports.submitted_at', '<=', $filters['date_to']);
            }
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_reports.submitted_at');
    }

    /**
     * Restrict a verificationQuery() result to reports booked under the
     * given hospital's departments.
     *
     * @param  Builder<RadiologyReport>  $query
     * @return Builder<RadiologyReport>
     */
    public function scopeReportsToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }
}
