<?php

namespace App\Services;

use App\Exceptions\AppointmentConflictException;
use App\Models\Appointment;
use App\Models\Practitioner;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Book a new appointment, safely serialized against concurrent bookings
     * for the same practitioner.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AppointmentConflictException
     */
    public function create(array $data, Request $request): Appointment
    {
        $appointment = DB::transaction(function () use ($data) {
            // Locking the practitioner row serializes concurrent create()
            // calls for the same practitioner, so the conflict check below
            // and the insert that follows it are never interleaved with
            // another request's — this is what actually prevents a race
            // condition double-booking, not the SELECT by itself.
            Practitioner::query()->whereKey($data['practitioner_id'])->lockForUpdate()->firstOrFail();

            $conflict = $this->findConflict(
                (int) $data['practitioner_id'],
                $data['scheduled_start'],
                $data['scheduled_end'],
            );

            if ($conflict) {
                throw new AppointmentConflictException($conflict);
            }

            // Set explicitly rather than relying on the column's DB default:
            // Eloquent doesn't re-fetch default values after an insert, so
            // the in-memory model (used immediately below for the audit log
            // and API response) would otherwise report a null status.
            $data['status'] = Appointment::STATUS_SCHEDULED;

            return Appointment::create($data);
        });

        $this->auditLog->record('appointment_created', $appointment, null, $appointment->only(array_keys($data)), $request);

        return $appointment;
    }

    /**
     * Update non-scheduling fields (type/reason/notes) on an appointment.
     * Use reschedule() to change the scheduled time.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Appointment $appointment, array $data, Request $request): Appointment
    {
        $oldValues = $appointment->only(array_keys($data));

        $appointment->update($data);

        $this->auditLog->record('appointment_updated', $appointment, $oldValues, $appointment->only(array_keys($data)), $request);

        return $appointment;
    }

    /**
     * Reschedule an appointment to a new time, re-checking for conflicts
     * under the same practitioner-row lock used at creation time. The
     * previous time is preserved as history via the audit log's old_values
     * rather than a separate linked record.
     *
     * @throws AppointmentConflictException
     */
    public function reschedule(Appointment $appointment, CarbonInterface $start, CarbonInterface $end, Request $request): Appointment
    {
        $oldValues = $appointment->only(['scheduled_start', 'scheduled_end']);

        DB::transaction(function () use ($appointment, $start, $end) {
            Practitioner::query()->whereKey($appointment->practitioner_id)->lockForUpdate()->firstOrFail();

            $conflict = $this->findConflict($appointment->practitioner_id, $start, $end, excludeId: $appointment->id);

            if ($conflict) {
                throw new AppointmentConflictException($conflict);
            }

            $appointment->update(['scheduled_start' => $start, 'scheduled_end' => $end]);
        });

        $this->auditLog->record(
            'appointment_rescheduled',
            $appointment,
            $oldValues,
            $appointment->only(['scheduled_start', 'scheduled_end']),
            $request,
        );

        return $appointment;
    }

    /**
     * Confirm a scheduled appointment.
     */
    public function confirm(Appointment $appointment, Request $request): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_CONFIRMED, 'appointment_confirmed', $request);
    }

    /**
     * Mark an appointment's patient as checked in.
     */
    public function checkIn(Appointment $appointment, Request $request): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_CHECKED_IN, 'appointment_checked_in', $request);
    }

    /**
     * Mark a checked-in appointment as in progress.
     */
    public function start(Appointment $appointment, Request $request): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_IN_PROGRESS, 'appointment_in_progress', $request);
    }

    /**
     * Mark an appointment as completed.
     */
    public function complete(Appointment $appointment, Request $request): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_COMPLETED, 'appointment_completed', $request);
    }

    /**
     * Cancel an appointment, recording who/when/why.
     */
    public function cancel(Appointment $appointment, string $reason, Request $request): Appointment
    {
        $oldValues = $appointment->only(['status', 'cancelled_at', 'cancellation_reason']);

        $this->assertTransitionAllowed($appointment, Appointment::STATUS_CANCELLED);

        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'appointment_cancelled',
            $appointment,
            $oldValues,
            $appointment->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $appointment;
    }

    /**
     * Mark an appointment as a no-show. Never applied automatically — only
     * through an explicit authorized action.
     */
    public function markNoShow(Appointment $appointment, Request $request): Appointment
    {
        return $this->transition($appointment, Appointment::STATUS_NO_SHOW, 'appointment_no_show', $request);
    }

    /**
     * Apply a validated status transition and audit it.
     *
     * @throws ValidationException
     */
    protected function transition(Appointment $appointment, string $newStatus, string $auditAction, Request $request): Appointment
    {
        $oldStatus = $appointment->status;

        $this->assertTransitionAllowed($appointment, $newStatus);

        $appointment->update(['status' => $newStatus]);

        $this->auditLog->record($auditAction, $appointment, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $appointment;
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(Appointment $appointment, string $newStatus): void
    {
        if (! $appointment->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change appointment status from \"{$appointment->status}\" to \"{$newStatus}\".",
            ]);
        }
    }

    /**
     * Find an active appointment for the given practitioner whose scheduled
     * window overlaps the given start/end, excluding the given appointment
     * id (used when rescheduling an appointment against itself).
     */
    public function findConflict(int $practitionerId, CarbonInterface|string $start, CarbonInterface|string $end, ?int $excludeId = null): ?Appointment
    {
        return Appointment::query()
            ->where('practitioner_id', $practitionerId)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->when($excludeId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->first();
    }
}
