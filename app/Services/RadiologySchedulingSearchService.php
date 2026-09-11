<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Builds the Radiology Scheduling, Worklist, and Reception queries (Phase
 * 5, Steps 39-40): the "awaiting scheduling" queue (RadiologyOrderItem-
 * based), the operational worklist, and the reception dashboard (both
 * RadiologyAppointment-based) — mirrors AppointmentSearchService's shape.
 * All three are always ordered STAT-first, URGENT-next, ROUTINE-last (Part
 * 16) — never a user-toggleable sort, since the spec treats priority-first
 * ordering as inherent to a radiology work queue, not an optional view.
 */
class RadiologySchedulingSearchService
{
    /**
     * Order items that are ready to be scheduled: still ORDERED, on a
     * non-cancelled order, with no active (scheduled/confirmed) appointment
     * of their own (Part 22 — "not cancelled + not already scheduled").
     *
     * Supported keys: search (order number, procedure, or patient
     * name/number), modality, priority.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyOrderItem>
     */
    public function unscheduledItemsQuery(array $filters): Builder
    {
        $query = RadiologyOrderItem::query()
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->select('radiology_order_items.*')
            ->where('radiology_order_items.status', RadiologyOrderItem::STATUS_ORDERED)
            ->whereIn('radiology_orders.status', [RadiologyOrder::STATUS_ORDERED, RadiologyOrder::STATUS_SCHEDULED])
            ->whereDoesntHave('radiologyAppointments', fn ($query) => $query->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES));

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_order_items.procedure_code', 'like', "%{$search}%")
                    ->orWhere('radiology_order_items.procedure_name', 'like', "%{$search}%")
                    ->orWhere('radiology_orders.order_number', 'like', "%{$search}%")
                    ->orWhereHas('radiologyOrder.clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['modality'] ?? null)) {
            $query->where('radiology_order_items.modality_snapshot', $filters['modality']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('radiology_orders.priority', $filters['priority']);
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_orders.ordered_at');
    }

    /**
     * Restrict the unscheduled-items queue to items booked under the given
     * hospital's departments.
     *
     * @param  Builder<RadiologyOrderItem>  $query
     * @return Builder<RadiologyOrderItem>
     */
    public function scopeUnscheduledToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->whereHas(
            'radiologyOrder.clinicalEncounter.department',
            fn ($query) => $query->where('hospital_id', $hospitalId),
        );
    }

    /**
     * The operational worklist: appointments, defaulting to active
     * (scheduled/confirmed) only unless an explicit status filter is given
     * (Part 22 — cancelled appointments are history, not active work,
     * except when a filter deliberately asks for them).
     *
     * Supported keys: search, status, modality, resource_name, priority,
     * date (a single day), date_from, date_to, overdue (bool-ish).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyAppointment>
     */
    public function worklistQuery(array $filters): Builder
    {
        $query = RadiologyAppointment::query()
            ->join('radiology_order_items', 'radiology_order_items.id', '=', 'radiology_appointments.radiology_order_item_id')
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->select('radiology_appointments.*');

        if (filled($filters['status'] ?? null)) {
            $query->where('radiology_appointments.status', $filters['status']);
        } else {
            $query->whereIn('radiology_appointments.status', RadiologyAppointment::ACTIVE_STATUSES);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_appointments.resource_name', 'like', "%{$search}%")
                    ->orWhere('radiology_order_items.procedure_code', 'like', "%{$search}%")
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

        if (filled($filters['resource_name'] ?? null)) {
            $query->where('radiology_appointments.resource_name', $filters['resource_name']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('radiology_orders.priority', $filters['priority']);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('radiology_appointments.scheduled_start', $filters['date']);
        } else {
            if (filled($filters['date_from'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '>=', $filters['date_from']);
            }

            if (filled($filters['date_to'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '<=', $filters['date_to']);
            }
        }

        if (filled($filters['overdue'] ?? null)) {
            $query->where('radiology_appointments.scheduled_start', '<', now())
                ->whereIn('radiology_appointments.status', RadiologyAppointment::ACTIVE_STATUSES);
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_appointments.scheduled_start');
    }

    /**
     * Restrict the worklist to appointments booked under the given
     * hospital's departments.
     *
     * @param  Builder<RadiologyAppointment>  $query
     * @return Builder<RadiologyAppointment>
     */
    public function scopeWorklistToHospital(Builder $query, ?int $hospitalId): Builder
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
     * The radiology examination worklist (Phase 5, Step 41, Part 15):
     * appointments whose patient is ready for (or already in) examination,
     * defaulting to READY/IN_PROGRESS ("what needs to be done right now")
     * unless an explicit status filter widens it to include the COMPLETED/
     * ABORTED history. Shares the exact same join/scope shape as
     * worklistQuery() (see scopeWorklistToHospital() below, reused as-is).
     *
     * Supported keys: search, status, priority, modality, resource_name,
     * date, date_from, date_to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyAppointment>
     */
    public function examinationWorklistQuery(array $filters): Builder
    {
        $query = RadiologyAppointment::query()
            ->join('radiology_order_items', 'radiology_order_items.id', '=', 'radiology_appointments.radiology_order_item_id')
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->select('radiology_appointments.*');

        if (filled($filters['status'] ?? null)) {
            $query->where('radiology_appointments.status', $filters['status']);
        } else {
            $query->whereIn('radiology_appointments.status', [
                RadiologyAppointment::STATUS_READY,
                RadiologyAppointment::STATUS_IN_PROGRESS,
            ]);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_appointments.resource_name', 'like', "%{$search}%")
                    ->orWhere('radiology_order_items.procedure_code', 'like', "%{$search}%")
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

        if (filled($filters['resource_name'] ?? null)) {
            $query->where('radiology_appointments.resource_name', $filters['resource_name']);
        }

        if (filled($filters['priority'] ?? null)) {
            $query->where('radiology_orders.priority', $filters['priority']);
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('radiology_appointments.scheduled_start', $filters['date']);
        } else {
            if (filled($filters['date_from'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '>=', $filters['date_from']);
            }

            if (filled($filters['date_to'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '<=', $filters['date_to']);
            }
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_appointments.scheduled_start');
    }

    /**
     * The radiology reception dashboard (Phase 5, Step 40, Part 19):
     * defaults to today's appointments across every status except
     * CANCELLED (staff need to see expected/arrived/ready/not-ready/
     * no-show patients together, not just active work) unless an explicit
     * date or status filter is given.
     *
     * Supported keys: search (patient number/name/order number/phone),
     * status, arrival_status ('arrived'|'not_arrived'), date, date_from,
     * date_to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RadiologyAppointment>
     */
    public function receptionQuery(array $filters): Builder
    {
        $query = RadiologyAppointment::query()
            ->join('radiology_order_items', 'radiology_order_items.id', '=', 'radiology_appointments.radiology_order_item_id')
            ->join('radiology_orders', 'radiology_orders.id', '=', 'radiology_order_items.radiology_order_id')
            ->select('radiology_appointments.*');

        if (filled($filters['status'] ?? null)) {
            $query->where('radiology_appointments.status', $filters['status']);
        } else {
            $query->where('radiology_appointments.status', '!=', RadiologyAppointment::STATUS_CANCELLED);
        }

        if (($filters['arrival_status'] ?? null) === 'arrived') {
            $query->whereNotNull('radiology_appointments.arrived_at');
        } elseif (($filters['arrival_status'] ?? null) === 'not_arrived') {
            $query->whereNull('radiology_appointments.arrived_at')
                ->whereIn('radiology_appointments.status', [RadiologyAppointment::STATUS_SCHEDULED, RadiologyAppointment::STATUS_CONFIRMED]);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('radiology_orders.order_number', 'like', "%{$search}%")
                    ->orWhereHas('radiologyOrderItem.radiologyOrder.clinicalEncounter.patient', function ($query) use ($search) {
                        $query->where('patient_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['date'] ?? null)) {
            $query->whereDate('radiology_appointments.scheduled_start', $filters['date']);
        } elseif (filled($filters['date_from'] ?? null) || filled($filters['date_to'] ?? null)) {
            if (filled($filters['date_from'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '>=', $filters['date_from']);
            }

            if (filled($filters['date_to'] ?? null)) {
                $query->where('radiology_appointments.scheduled_start', '<=', $filters['date_to']);
            }
        } else {
            $query->whereDate('radiology_appointments.scheduled_start', today());
        }

        return $query
            ->orderByRaw("case radiology_orders.priority when 'stat' then 0 when 'urgent' then 1 when 'routine' then 2 else 3 end")
            ->orderBy('radiology_appointments.scheduled_start');
    }
}
