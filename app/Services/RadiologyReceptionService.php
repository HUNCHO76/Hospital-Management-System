<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Radiology reception & preparation (Phase 5, Step 40): the arrival ->
 * identity/order verification -> preparation/screening/consent -> readiness
 * pipeline for one already-scheduled RadiologyAppointment. Mirrors
 * RadiologySchedulingService's own transition-helper/audit shape.
 *
 * Patient/order/procedure consistency is enforced by construction, not by a
 * separate cross-check: an appointment only ever resolves to one
 * RadiologyOrderItem, which only ever resolves to one RadiologyOrder/
 * ClinicalEncounter/patient — there is no input path anywhere in this
 * service that accepts a patient_id, order_id, or procedure_id from the
 * caller, so a wrong-patient mix-up (Part 7) is structurally impossible,
 * exactly like every other Radiology write path already proven in Steps
 * 38-39. "Verification" here is therefore a deliberate staff confirmation
 * checkpoint (a workflow gate), not a computation that could fail.
 */
class RadiologyReceptionService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Mark a scheduled/confirmed appointment's patient as arrived.
     *
     * @throws ValidationException
     */
    public function markArrived(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $oldStatus = $appointment->status;

        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_ARRIVED);

        $appointment->update([
            'status' => RadiologyAppointment::STATUS_ARRIVED,
            'arrived_at' => now(),
            'arrived_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'radiology_patient_arrived',
            $appointment,
            ['status' => $oldStatus],
            $appointment->only(['status', 'arrived_at']),
            $request,
        );

        return $appointment;
    }

    /**
     * Record the staff confirmation that the patient's identity and
     * radiology order/procedure have been checked. Auto-routes the
     * appointment onward: procedures with no preparation requirement stay
     * at ARRIVED (immediately eligible for markReady()); procedures that do
     * require preparation move to PREPARATION_PENDING.
     *
     * @throws ValidationException
     */
    public function verify(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        if ($appointment->status !== RadiologyAppointment::STATUS_ARRIVED) {
            throw ValidationException::withMessages([
                'status' => "Cannot verify a patient with appointment status \"{$appointment->status}\".",
            ]);
        }

        $procedure = $appointment->radiologyOrderItem->radiologyProcedure;
        $requiresPreparation = $procedure->preparation_type !== 'none';

        $appointment->update([
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
            'status' => $requiresPreparation ? RadiologyAppointment::STATUS_PREPARATION_PENDING : RadiologyAppointment::STATUS_ARRIVED,
        ]);

        $this->auditLog->record(
            'radiology_patient_verified',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_ARRIVED],
            $appointment->only(['status', 'verified_at']),
            $request,
        );

        $this->auditLog->record(
            'radiology_order_verified',
            $appointment,
            null,
            ['radiology_order_item_id' => $appointment->radiology_order_item_id],
            $request,
        );

        return $appointment;
    }

    /**
     * Begin preparation for a procedure that requires it.
     *
     * @throws ValidationException
     */
    public function startPreparation(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $oldStatus = $appointment->status;

        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS);

        $appointment->update(['status' => RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS]);

        $this->auditLog->record(
            'radiology_preparation_started',
            $appointment,
            ['status' => $oldStatus],
            ['status' => RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS],
            $request,
        );

        return $appointment;
    }

    /**
     * Record that preparation instructions were given and/or preparation
     * was completed. Does not itself change the appointment's status —
     * markReady() is the deliberate, separate final confirmation.
     *
     * @param  array{instructions_given?: bool, completed?: bool, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function completePreparation(RadiologyAppointment $appointment, array $data, Request $request): RadiologyAppointment
    {
        if ($appointment->status !== RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => "Cannot complete preparation for an appointment with status \"{$appointment->status}\".",
            ]);
        }

        $oldValues = $appointment->only(['instructions_given_at', 'preparation_completed_at', 'preparation_notes']);

        $update = ['preparation_notes' => $data['notes'] ?? $appointment->preparation_notes];

        if (! empty($data['instructions_given']) && ! $appointment->instructions_given_at) {
            $update['instructions_given_at'] = now();
            $update['instructions_given_by'] = $request->user()->id;
        }

        if (! empty($data['completed'])) {
            $update['preparation_completed_at'] = now();
            $update['preparation_completed_by'] = $request->user()->id;
        }

        $appointment->update($update);

        $this->auditLog->record(
            'radiology_preparation_completed',
            $appointment,
            $oldValues,
            $appointment->only(['instructions_given_at', 'preparation_completed_at', 'preparation_notes']),
            $request,
        );

        return $appointment;
    }

    /**
     * Waive preparation for this appointment (Part 9's WAIVED outcome) —
     * satisfies the same readiness gate as completing it.
     *
     * @throws ValidationException
     */
    public function waivePreparation(RadiologyAppointment $appointment, string $reason, Request $request): RadiologyAppointment
    {
        if (! in_array($appointment->status, [RadiologyAppointment::STATUS_PREPARATION_PENDING, RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot waive preparation for an appointment with status \"{$appointment->status}\".",
            ]);
        }

        $appointment->update([
            'preparation_waived_at' => now(),
            'preparation_waived_by' => $request->user()->id,
            'preparation_waived_reason' => $reason,
        ]);

        $this->auditLog->record(
            'radiology_preparation_waived',
            $appointment,
            null,
            $appointment->only(['preparation_waived_at', 'preparation_waived_reason']),
            $request,
        );

        return $appointment;
    }

    /**
     * Record MRI safety screening (Part 12) — a checklist/flag capture
     * point only, never an automated safety determination. A flagged
     * screening blocks markReady() until cleared by a subsequent,
     * unflagged screening record.
     *
     * @throws ValidationException
     */
    public function recordScreening(RadiologyAppointment $appointment, bool $flagged, ?string $notes, Request $request): RadiologyAppointment
    {
        $this->assertReceivable($appointment);

        $appointment->update([
            'mri_screening_completed_at' => now(),
            'mri_screening_completed_by' => $request->user()->id,
            'mri_screening_flagged' => $flagged,
            'mri_screening_notes' => $notes,
        ]);

        $this->auditLog->record(
            'radiology_safety_screening_recorded',
            $appointment,
            null,
            $appointment->only(['mri_screening_completed_at', 'mri_screening_flagged']),
            $request,
        );

        return $appointment;
    }

    /**
     * Record the contrast-requirement review checkpoint (Part 13) — never
     * dose/allergy/medication management.
     *
     * @throws ValidationException
     */
    public function reviewContrast(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $this->assertReceivable($appointment);

        $appointment->update([
            'contrast_reviewed_at' => now(),
            'contrast_reviewed_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'radiology_contrast_reviewed',
            $appointment,
            null,
            $appointment->only(['contrast_reviewed_at']),
            $request,
        );

        return $appointment;
    }

    /**
     * Record consent status (Part 14) — reuses no separate consent
     * architecture, since none exists anywhere in this codebase; each
     * appointment records its own status rather than assuming every
     * procedure follows the same process.
     *
     * @throws ValidationException
     */
    public function recordConsent(RadiologyAppointment $appointment, string $status, Request $request): RadiologyAppointment
    {
        $this->assertReceivable($appointment);

        $oldValues = $appointment->only(['consent_status']);

        $appointment->update([
            'consent_status' => $status,
            'consent_recorded_at' => now(),
            'consent_recorded_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'radiology_consent_recorded',
            $appointment,
            $oldValues,
            $appointment->only(['consent_status', 'consent_recorded_at']),
            $request,
        );

        return $appointment;
    }

    /**
     * Mark the patient ready for examination — the final gate. Checks
     * identity/order verification, preparation completion (or waiver),
     * MRI screening clearance, contrast review, and consent, exactly as
     * Part 16 requires, before allowing the transition.
     *
     * @throws ValidationException
     */
    public function markReady(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_READY);

        $errors = $this->readinessBlockers($appointment);

        if ($errors !== []) {
            throw ValidationException::withMessages(['status' => $errors]);
        }

        $oldStatus = $appointment->status;

        $appointment->update([
            'status' => RadiologyAppointment::STATUS_READY,
            'ready_at' => now(),
            'ready_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'radiology_patient_marked_ready',
            $appointment,
            ['status' => $oldStatus],
            $appointment->only(['status', 'ready_at']),
            $request,
        );

        return $appointment;
    }

    /**
     * Mark the patient not ready, with a required reason. Never cancels
     * the underlying order (Part 15) — the appointment can resume() back
     * toward readiness once the issue is resolved.
     *
     * @throws ValidationException
     */
    public function markNotReady(RadiologyAppointment $appointment, string $reason, Request $request): RadiologyAppointment
    {
        $oldStatus = $appointment->status;

        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_NOT_READY);

        $appointment->update([
            'status' => RadiologyAppointment::STATUS_NOT_READY,
            'not_ready_reason' => $reason,
        ]);

        $this->auditLog->record(
            'radiology_patient_marked_not_ready',
            $appointment,
            ['status' => $oldStatus],
            $appointment->only(['status', 'not_ready_reason']),
            $request,
        );

        return $appointment;
    }

    /**
     * Resume a not-ready appointment back into the active workflow once
     * the blocking issue has been resolved.
     *
     * @throws ValidationException
     */
    public function resume(RadiologyAppointment $appointment, Request $request): RadiologyAppointment
    {
        $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_ARRIVED);

        $appointment->update(['status' => RadiologyAppointment::STATUS_ARRIVED, 'not_ready_reason' => null]);

        $this->auditLog->record(
            'radiology_patient_marked_not_ready',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_NOT_READY],
            ['status' => RadiologyAppointment::STATUS_ARRIVED],
            $request,
        );

        return $appointment;
    }

    /**
     * The human-readable list of readiness gates this appointment has not
     * yet satisfied — empty when it is eligible for markReady(). Exposed
     * separately from markReady() so the reception UI can show staff
     * exactly what remains, not just a rejected submission.
     *
     * @return list<string>
     */
    public function readinessBlockers(RadiologyAppointment $appointment): array
    {
        $errors = [];

        if (! $appointment->verified_at) {
            $errors[] = 'Patient identity and radiology order must be verified first.';
        }

        $procedure = $appointment->radiologyOrderItem->radiologyProcedure;

        if ($procedure->preparation_type !== 'none') {
            if (! $appointment->preparation_completed_at && ! $appointment->preparation_waived_at) {
                $errors[] = 'Preparation must be completed or waived first.';
            }

            if ($procedure->preparation_type === 'mri_safety_screening') {
                if (! $appointment->mri_screening_completed_at) {
                    $errors[] = 'MRI safety screening must be completed first.';
                } elseif ($appointment->mri_screening_flagged) {
                    $errors[] = 'A flagged MRI safety screening requires clinical review before this patient can be marked ready.';
                }
            }
        }

        if ($procedure->contrast_requirement === 'required' && ! $appointment->contrast_reviewed_at) {
            $errors[] = 'The contrast requirement must be reviewed first.';
        }

        if (in_array($appointment->consent_status, [RadiologyAppointment::CONSENT_PENDING, RadiologyAppointment::CONSENT_DECLINED], true)) {
            $errors[] = 'Consent must be obtained before this patient can be marked ready.';
        }

        return $errors;
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
     * Preparation/screening/consent can be recorded any time after arrival
     * and before the patient is marked ready, cancelled, or a no-show.
     *
     * @throws ValidationException
     */
    protected function assertReceivable(RadiologyAppointment $appointment): void
    {
        $receivableStatuses = [
            RadiologyAppointment::STATUS_ARRIVED,
            RadiologyAppointment::STATUS_PREPARATION_PENDING,
            RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS,
            RadiologyAppointment::STATUS_NOT_READY,
        ];

        if (! in_array($appointment->status, $receivableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot record this for an appointment with status \"{$appointment->status}\".",
            ]);
        }
    }
}
