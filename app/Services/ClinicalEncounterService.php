<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalEncounterService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a clinical encounter, safely serialized against a concurrent
     * duplicate for the same patient.
     *
     * @param  array{patient_id: int, reception_visit_id?: int|null, appointment_id?: int|null, department_id: int, practitioner_id?: int|null, encounter_type: string}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): ClinicalEncounter
    {
        $encounter = DB::transaction(function () use ($data, $request) {
            // Locking the patient row is what makes this concurrency-safe:
            // two simultaneous attempts to open an encounter for the same
            // patient serialize on this lock, so the active-encounter check
            // below and the insert that follows it are never interleaved
            // with another request's.
            Patient::query()->whereKey($data['patient_id'])->lockForUpdate()->firstOrFail();

            $this->assertNoActiveEncounter($data['patient_id']);

            $data['status'] = ClinicalEncounter::STATUS_OPEN;
            $data['created_by'] = $request->user()->id;

            return ClinicalEncounter::create($data);
        });

        $this->auditLog->record(
            'clinical_encounter_created',
            $encounter,
            null,
            $encounter->only(['patient_id', 'reception_visit_id', 'appointment_id', 'department_id', 'practitioner_id', 'encounter_type', 'status']),
            $request,
        );

        return $encounter;
    }

    /**
     * Start a clinical encounter (OPEN -> IN_PROGRESS), recording the actual
     * start time of clinical service.
     *
     * @throws ValidationException
     */
    public function start(ClinicalEncounter $encounter, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'started_at']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_IN_PROGRESS);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
            'started_at' => $encounter->started_at ?? now(),
        ]);

        $this->auditLog->record('clinical_encounter_started', $encounter, $oldValues, $encounter->only(['status', 'started_at']), $request);

        return $encounter;
    }

    /**
     * Complete a clinical encounter (IN_PROGRESS -> COMPLETED), recording its
     * clinical outcome. `disposition` is optional and defaults to COMPLETED
     * — a simple encounter is never forced to declare an outcome it doesn't
     * have (spec §15's "do not require unnecessary steps for every encounter
     * type"). Each disposition is a handoff boundary only: FOLLOW_UP points
     * staff at the existing Appointment module, ADMITTED/REFERRED/
     * TRANSFERRED are recorded for a future module to read — none of those
     * workflows are implemented here.
     *
     * @param  array{disposition?: string|null, disposition_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function complete(ClinicalEncounter $encounter, array $data, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'ended_at', 'closed_by', 'disposition', 'disposition_notes']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_COMPLETED);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_COMPLETED,
            'ended_at' => now(),
            'closed_by' => $request->user()->id,
            'disposition' => $data['disposition'] ?? ClinicalEncounter::DISPOSITION_COMPLETED,
            'disposition_notes' => $data['disposition_notes'] ?? null,
        ]);

        $this->auditLog->record(
            'clinical_encounter_completed',
            $encounter,
            $oldValues,
            $encounter->only(['status', 'ended_at', 'closed_by', 'disposition', 'disposition_notes']),
            $request,
        );

        return $encounter;
    }

    /**
     * Cancel a clinical encounter, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(ClinicalEncounter $encounter, string $reason, Request $request): ClinicalEncounter
    {
        $oldValues = $encounter->only(['status', 'ended_at', 'closed_by', 'cancellation_reason']);

        $this->assertTransitionAllowed($encounter, ClinicalEncounter::STATUS_CANCELLED);

        $encounter->update([
            'status' => ClinicalEncounter::STATUS_CANCELLED,
            'ended_at' => now(),
            'closed_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'clinical_encounter_cancelled',
            $encounter,
            $oldValues,
            $encounter->only(['status', 'ended_at', 'closed_by', 'cancellation_reason']),
            $request,
        );

        return $encounter;
    }

    /**
     * The patient's current active encounter, if any — used both to block a
     * second concurrent encounter and to surface the existing one to
     * authorized staff instead of a raw error.
     */
    public function findActiveEncounter(int $patientId): ?ClinicalEncounter
    {
        return ClinicalEncounter::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', ClinicalEncounter::ACTIVE_STATUSES)
            ->first();
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoActiveEncounter(int $patientId): void
    {
        $existing = $this->findActiveEncounter($patientId);

        if ($existing) {
            throw ValidationException::withMessages([
                'patient_id' => "This patient already has an active clinical encounter ({$existing->encounter_number}). Complete or cancel it before starting another.",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(ClinicalEncounter $encounter, string $newStatus): void
    {
        if (! $encounter->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change encounter status from \"{$encounter->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
