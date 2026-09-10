<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\ReceptionVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceptionVisitService
{
    public function __construct(protected AuditLogService $auditLog, protected AppointmentService $appointments) {}

    /**
     * Check in a scheduled appointment at the front desk, creating its
     * reception visit. Reuses AppointmentService::checkIn() so Step 18's own
     * transition rules (rejects cancelled/no-show/completed/already
     * checked-in) are the single source of truth — never re-implemented
     * here.
     *
     * @throws ValidationException
     */
    public function checkInAppointment(Appointment $appointment, int $departmentId, Request $request): ReceptionVisit
    {
        $visit = DB::transaction(function () use ($appointment, $departmentId, $request) {
            // Locking both rows is what makes this reception-side action
            // concurrency-safe: two simultaneous check-in attempts for the
            // same appointment/patient will serialize on these locks, even
            // though AppointmentService::checkIn() itself doesn't lock.
            Patient::query()->whereKey($appointment->patient_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertNoActiveVisit($appointment->patient_id);

            $this->appointments->checkIn($appointment, $request);

            return ReceptionVisit::create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'department_id' => $departmentId,
                'visit_type' => 'appointment',
                'status' => ReceptionVisit::STATUS_CHECKED_IN,
            ]);
        });

        $this->auditLog->record('reception_visit_checked_in', $visit, null, $visit->only(['patient_id', 'appointment_id', 'department_id', 'status']), $request);

        return $visit;
    }

    /**
     * Register a walk-in patient's visit at the front desk.
     *
     * @param  array{patient_id: int, department_id: int, visit_type: string}  $data
     *
     * @throws ValidationException
     */
    public function createWalkIn(array $data, Request $request): ReceptionVisit
    {
        $visit = DB::transaction(function () use ($data) {
            Patient::query()->whereKey($data['patient_id'])->lockForUpdate()->firstOrFail();

            $this->assertNoActiveVisit($data['patient_id']);

            return ReceptionVisit::create([
                'patient_id' => $data['patient_id'],
                'appointment_id' => null,
                'department_id' => $data['department_id'],
                'visit_type' => $data['visit_type'],
                'status' => ReceptionVisit::STATUS_CHECKED_IN,
            ]);
        });

        $this->auditLog->record('reception_visit_walkin_created', $visit, null, $visit->only(['patient_id', 'department_id', 'visit_type', 'status']), $request);

        return $visit;
    }

    /**
     * Move a checked-in visit to waiting.
     */
    public function markWaiting(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        return $this->transition($visit, ReceptionVisit::STATUS_WAITING, 'reception_visit_waiting', $request);
    }

    /**
     * Mark a waiting visit ready for service.
     */
    public function markReadyForService(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        return $this->transition($visit, ReceptionVisit::STATUS_READY_FOR_SERVICE, 'reception_visit_ready', $request);
    }

    /**
     * Send a ready visit to its destination service/department.
     */
    public function sendToService(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        return $this->transition($visit, ReceptionVisit::STATUS_SENT_TO_SERVICE, 'reception_visit_sent_to_service', $request);
    }

    /**
     * Complete a visit.
     */
    public function complete(ReceptionVisit $visit, Request $request): ReceptionVisit
    {
        $oldValues = $visit->only(['status', 'completed_at']);

        $this->assertTransitionAllowed($visit, ReceptionVisit::STATUS_COMPLETED);

        $visit->update(['status' => ReceptionVisit::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->auditLog->record('reception_visit_completed', $visit, $oldValues, $visit->only(['status', 'completed_at']), $request);

        return $visit;
    }

    /**
     * Cancel a visit, recording the reason.
     */
    public function cancel(ReceptionVisit $visit, string $reason, Request $request): ReceptionVisit
    {
        $oldValues = $visit->only(['status', 'cancelled_at', 'cancellation_reason']);

        $this->assertTransitionAllowed($visit, ReceptionVisit::STATUS_CANCELLED);

        $visit->update([
            'status' => ReceptionVisit::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('reception_visit_cancelled', $visit, $oldValues, $visit->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        return $visit;
    }

    /**
     * The patient's current active visit, if any — used both to block a
     * second concurrent visit and to show the existing one to staff instead
     * of a raw error.
     */
    public function findActiveVisit(int $patientId): ?ReceptionVisit
    {
        return ReceptionVisit::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ReceptionVisit::ACTIVE_STATUSES)
            ->first();
    }

    /**
     * The reception dashboard's data: today's appointment/visit summary
     * counts, today's appointments (with reception + insurance status), and
     * today's walk-ins. Hospital-scoped exactly like AppointmentController's
     * own index/calendar actions — $hospitalId is null for an unrestricted
     * user (super-admin or any role without hospital scoping).
     *
     * @return array{summary: array<string, int>, todaysAppointments: Collection, todaysWalkIns: \Illuminate\Database\Eloquent\Collection}
     */
    public function dashboardData(?int $hospitalId): array
    {
        $appointmentStatusCounts = Appointment::query()
            ->whereDate('scheduled_start', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $visitStatusCounts = ReceptionVisit::query()
            ->whereDate('created_at', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $summary = [
            'total_appointments' => (int) $appointmentStatusCounts->sum(),
            'no_show' => (int) ($appointmentStatusCounts['no_show'] ?? 0),
            'checked_in' => (int) ($visitStatusCounts['checked_in'] ?? 0),
            'waiting' => (int) ($visitStatusCounts['waiting'] ?? 0),
            'ready_for_service' => (int) ($visitStatusCounts['ready_for_service'] ?? 0),
            'sent_to_service' => (int) ($visitStatusCounts['sent_to_service'] ?? 0),
            'completed' => (int) ($visitStatusCounts['completed'] ?? 0),
            'cancelled' => (int) (($appointmentStatusCounts['cancelled'] ?? 0) + ($visitStatusCounts['cancelled'] ?? 0)),
        ];

        $todaysAppointments = Appointment::query()
            ->whereDate('scheduled_start', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->with([
                'patient:id,patient_number,first_name,middle_name,last_name,phone',
                'patient.insurances' => fn ($query) => $query->orderByDesc('is_primary'),
                'department:id,name,code',
                'practitioner:id,practitioner_number,staff_id',
                'practitioner.staff:id,first_name,last_name',
                'receptionVisit:id,appointment_id,visit_number,status',
            ])
            ->orderBy('scheduled_start')
            ->get()
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'appointment_number' => $appointment->appointment_number,
                'appointment_type' => $appointment->appointment_type,
                'scheduled_start' => $appointment->scheduled_start,
                'status' => $appointment->status,
                'patient' => $appointment->patient?->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']),
                'department' => $appointment->department?->only(['id', 'name']),
                'practitioner' => $appointment->practitioner?->staff?->only(['first_name', 'last_name']),
                'reception_visit' => $appointment->receptionVisit?->only(['id', 'visit_number', 'status']),
                'insurance_indicator' => $appointment->patient ? $this->insuranceIndicator($appointment->patient) : 'none',
            ]);

        $todaysWalkIns = ReceptionVisit::query()
            ->where('visit_type', 'walk_in')
            ->whereDate('created_at', today())
            ->when($hospitalId, fn ($query, $id) => $query->whereHas('department', fn ($query) => $query->where('hospital_id', $id)))
            ->with(['patient:id,patient_number,first_name,middle_name,last_name', 'department:id,name'])
            ->latest('created_at')
            ->get();

        return compact('summary', 'todaysAppointments', 'todaysWalkIns');
    }

    /**
     * A concise, front-desk-appropriate coverage indicator derived from the
     * patient's primary insurance record — reuses PatientInsurance's own
     * status/verification fields rather than duplicating insurance logic.
     */
    public function insuranceIndicator(Patient $patient): string
    {
        $insurance = $patient->relationLoaded('insurances')
            ? $patient->insurances->firstWhere('is_primary', true) ?? $patient->insurances->first()
            : $patient->insurances()->orderByDesc('is_primary')->first();

        if (! $insurance) {
            return 'none';
        }

        if ($insurance->status !== PatientInsurance::STATUS_ACTIVE) {
            return $insurance->status === PatientInsurance::STATUS_EXPIRED ? 'expired' : 'none';
        }

        if ($insurance->effective_to && $insurance->effective_to->isPast()) {
            return 'expired';
        }

        return match ($insurance->verification_status) {
            PatientInsurance::VERIFICATION_VERIFIED => 'active',
            PatientInsurance::VERIFICATION_FAILED, PatientInsurance::VERIFICATION_EXPIRED => 'not_verified',
            default => 'pending_verification',
        };
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoActiveVisit(int $patientId): void
    {
        $existing = $this->findActiveVisit($patientId);

        if ($existing) {
            throw ValidationException::withMessages([
                'patient_id' => "This patient already has an active visit ({$existing->visit_number}). Complete or cancel it before starting another.",
            ]);
        }
    }

    /**
     * Apply a validated status transition and audit it.
     *
     * @throws ValidationException
     */
    protected function transition(ReceptionVisit $visit, string $newStatus, string $auditAction, Request $request): ReceptionVisit
    {
        $oldStatus = $visit->status;

        $this->assertTransitionAllowed($visit, $newStatus);

        $visit->update(['status' => $newStatus]);

        $this->auditLog->record($auditAction, $visit, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $visit;
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(ReceptionVisit $visit, string $newStatus): void
    {
        if (! $visit->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change visit status from \"{$visit->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
