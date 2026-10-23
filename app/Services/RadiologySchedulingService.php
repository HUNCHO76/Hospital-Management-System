<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\NHIF\NhifOrderPreApprovalService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radiology scheduling (Phase 5, Step 39). Mirrors AppointmentService's
 * proven concurrency pattern (transaction + row lock + interval-overlap
 * check, one audited status-transition helper) applied to a room/resource
 * axis instead of a practitioner — see RadiologyAppointment's own
 * doc-comment for why this is a dedicated service/table rather than a
 * reuse of AppointmentService/Appointment.
 */
class RadiologySchedulingService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected FinancialAuthorizationService $financialAuth,
    ) {}

    /**
     * Schedule a single, still-unscheduled order item onto a resource/time
     * slot, safely serialized against concurrent bookings of the same
     * resource.
     *
     * @param  array{resource_name: string, scheduled_start: string, scheduled_end: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function schedule(RadiologyOrderItem $item, array $data, Request $request): RadiologyAppointment
    {
        $appointment = DB::transaction(function () use ($item, $data, $request) {
            // Locking the item row serializes concurrent scheduling attempts
            // for the same item, the same idiom RadiologyOrderService uses
            // for the encounter row at order-creation time.
            $item = RadiologyOrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $this->assertItemSchedulable($item);

            // Step 62: financial authorization gate — no-charge rule: procedures
            // with no billing mapping (capture skipped) never block scheduling.
            $this->financialAuth->assertAuthorizedForSources(
                RadiologyOrderItem::class,
                [$item->id],
                FinancialAuthorizationService::STATUS_AUTHORIZED,
            );

            // NHIF pre-approval gate: ensure procedure has been requested & issued if NHIF-covered
            app(NhifOrderPreApprovalService::class)->assertRadiologyOrderItemCanSchedule($item);

            $start = $data['scheduled_start'];
            $end = $data['scheduled_end'];

            if ($this->findConflict($data['resource_name'], $start, $end)) {
                throw ValidationException::withMessages([
                    'resource_name' => 'This resource is already booked during the selected time. Please choose another time or resource.',
                ]);
            }

            return RadiologyAppointment::create([
                'radiology_order_item_id' => $item->id,
                // Never user-editable — always the true modality of the
                // procedure being scheduled (Part 9's safest-available
                // validation absent a modality-to-room registry).
                'modality' => $item->modality_snapshot,
                'resource_name' => $data['resource_name'],
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'status' => RadiologyAppointment::STATUS_SCHEDULED,
                'notes' => $data['notes'] ?? null,
                'scheduled_by' => $request->user()->id,
            ]);
        });

        $this->auditLog->record(
            'radiology_appointment_scheduled',
            $appointment,
            null,
            $appointment->only(['radiology_order_item_id', 'modality', 'resource_name', 'scheduled_start', 'scheduled_end', 'status']),
            $request,
        );

        $this->recomputeOrderStatus($item->radiologyOrder, $request);

        return $appointment;
    }

    /**
     * Reschedule an active appointment to a new time, re-checking for
     * conflicts under the same resource lock used at schedule time. The
     * previous time is preserved as history via the audit log's
     * old_values, plus a quick-glance rescheduled_at/reschedule_reason pair
     * on the row itself — never silently overwritten (Part 18).
     *
     * @throws ValidationException
     */
    public function reschedule(RadiologyAppointment $appointment, CarbonInterface $start, CarbonInterface $end, string $reason, Request $request): RadiologyAppointment
    {
        $this->assertReschedulable($appointment);

        $oldValues = $appointment->only(['scheduled_start', 'scheduled_end']);

        DB::transaction(function () use ($appointment, $start, $end, $reason) {
            $conflict = $this->findConflict($appointment->resource_name, $start, $end, excludeId: $appointment->id);

            if ($conflict) {
                throw ValidationException::withMessages([
                    'scheduled_start' => 'This resource is already booked during the selected time. Please choose another time.',
                ]);
            }

            $appointment->update([
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'rescheduled_at' => now(),
                'reschedule_reason' => $reason,
            ]);
        });

        $this->auditLog->record(
            'radiology_appointment_rescheduled',
            $appointment,
            $oldValues,
            $appointment->only(['scheduled_start', 'scheduled_end']),
            $request,
        );

        return $appointment;
    }

    /**
     * Confirm a scheduled appointment.
     *
     * @throws ValidationException
     */
    public function confirm(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_CONFIRMED);

        $appointment->update([
            'status' => RadiologyAppointment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'radiology_appointment_confirmed',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_SCHEDULED],
            ['status' => RadiologyAppointment::STATUS_CONFIRMED],
            $request,
        );

        return $appointment;
    }

    /**
     * Cancel an appointment, recording who/when/why. Never deletes — a
     * cancelled appointment remains historically traceable, and recomputes
     * the parent order's derived scheduling rollup (Part 21).
     *
     * @throws ValidationException
     */
    public function cancel(RadiologyAppointment $appointment, string $reason, Request $request): RadiologyAppointment
    {
        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_CANCELLED);

        $oldValues = $appointment->only(['status', 'cancelled_at', 'cancellation_reason']);

        $appointment->update([
            'status' => RadiologyAppointment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'radiology_appointment_cancelled',
            $appointment,
            $oldValues,
            $appointment->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        $this->recomputeOrderStatus($appointment->radiologyOrderItem->radiologyOrder, $request);

        return $appointment;
    }

    /**
     * Mark an appointment's patient as a no-show. Never applied
     * automatically — only through an explicit authorized action. Frees the
     * item up for a fresh scheduling attempt, so also recomputes the
     * parent order's rollup.
     *
     * @throws ValidationException
     */
    public function markNoShow(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_NO_SHOW);

        $oldStatus = $appointment->status;

        $appointment->update(['status' => RadiologyAppointment::STATUS_NO_SHOW]);

        $this->auditLog->record(
            'radiology_appointment_no_show',
            $appointment,
            ['status' => $oldStatus],
            ['status' => RadiologyAppointment::STATUS_NO_SHOW],
            $request,
        );

        $this->recomputeOrderStatus($appointment->radiologyOrderItem->radiologyOrder, $request);

        return $appointment;
    }

    /**
     * Cancel a single item's active appointment, if it has one — called by
     * EncounterRadiologyOrderController when an order item itself is
     * cancelled, so an appointment is never left scheduled for a procedure
     * that is no longer being ordered.
     */
    public function cancelActiveAppointmentForItem(RadiologyOrderItem $item, string $reason, Request $request): void
    {
        $appointment = $item->activeRadiologyAppointment;

        if ($appointment) {
            $this->cancel($appointment, $reason, $request);
        }
    }

    /**
     * Cancel every active appointment belonging to an order — called by
     * EncounterRadiologyOrderController when the whole order is cancelled.
     */
    public function cancelActiveAppointmentsForOrder(RadiologyOrder $order, string $reason, Request $request): void
    {
        $order->items()->with('activeRadiologyAppointment')->get()->each(function (RadiologyOrderItem $item) use ($reason, $request) {
            if ($item->activeRadiologyAppointment) {
                $this->cancel($item->activeRadiologyAppointment, $reason, $request);
            }
        });
    }

    /**
     * Find an active appointment for the given resource whose scheduled
     * window overlaps the given start/end, excluding the given appointment
     * id (used when rescheduling an appointment against itself). Locks any
     * matching row(s) for the remainder of the caller's transaction — the
     * `active_slot_key` unique index on the table is the backstop for the
     * case where no row yet exists to lock (the first booking of a
     * resource on a given day).
     */
    public function findConflict(string $resourceName, CarbonInterface|string $start, CarbonInterface|string $end, ?int $excludeId = null): ?RadiologyAppointment
    {
        return RadiologyAppointment::query()
            ->where('resource_name', $resourceName)
            ->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES)
            ->when($excludeId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @throws ValidationException
     */
    protected function assertItemSchedulable(RadiologyOrderItem $item): void
    {
        if ($item->status !== RadiologyOrderItem::STATUS_ORDERED) {
            throw ValidationException::withMessages([
                'radiology_order_item_id' => "Cannot schedule an order item with status \"{$item->status}\".",
            ]);
        }

        $order = $item->radiologyOrder;

        if (! in_array($order->status, [RadiologyOrder::STATUS_ORDERED, RadiologyOrder::STATUS_SCHEDULED], true)) {
            throw ValidationException::withMessages([
                'radiology_order_item_id' => "Cannot schedule an item on a radiology order with status \"{$order->status}\".",
            ]);
        }

        if ($item->activeRadiologyAppointment) {
            throw ValidationException::withMessages([
                'radiology_order_item_id' => 'This procedure already has an active appointment scheduled.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertReschedulable(RadiologyAppointment $appointment): void
    {
        if (! in_array($appointment->status, RadiologyAppointment::RESCHEDULABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot reschedule an appointment with status \"{$appointment->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(RadiologyAppointment $appointment, string $newStatus): void
    {
        if (! $appointment->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change appointment status from \"{$appointment->status}\" to \"{$newStatus}\".",
            ]);
        }
    }

    /**
     * Recompute the order's derived SCHEDULED/ORDERED rollup (Part 21): an
     * order becomes SCHEDULED only once every one of its non-cancelled
     * items has an active (scheduled/confirmed) appointment, and reverts to
     * ORDERED the moment that stops being true. Never touches a CANCELLED
     * order. Locks the order row so concurrent appointment mutations for
     * different items on the same order never race each other's rollup.
     */
    protected function recomputeOrderStatus(RadiologyOrder $order, Request $request): void
    {
        DB::transaction(function () use ($order, $request) {
            $order = RadiologyOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === RadiologyOrder::STATUS_CANCELLED) {
                return;
            }

            $activeItems = $order->items()
                ->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)
                ->with('activeRadiologyAppointment')
                ->get();

            if ($activeItems->isEmpty()) {
                return;
            }

            $newStatus = $activeItems->every(fn (RadiologyOrderItem $item) => $item->activeRadiologyAppointment !== null)
                ? RadiologyOrder::STATUS_SCHEDULED
                : RadiologyOrder::STATUS_ORDERED;

            if ($newStatus !== $order->status) {
                $oldStatus = $order->status;
                $order->update(['status' => $newStatus]);

                $this->auditLog->record(
                    'radiology_order_status_changed',
                    $order,
                    ['status' => $oldStatus],
                    ['status' => $newStatus],
                    $request,
                );
            }
        });
    }
}
