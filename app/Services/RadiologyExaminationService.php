<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\NHIF\NHIFClinicalUtilizationService;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radiology examination (Phase 5, Step 41): the actual imaging procedure,
 * READY -> IN_PROGRESS -> COMPLETED/ABORTED, for one already-ready
 * RadiologyAppointment. Mirrors RadiologySchedulingService/
 * RadiologyReceptionService's own transaction + row-lock + audited-
 * transition-helper shape exactly.
 *
 * Patient/order/procedure consistency is enforced by construction, the same
 * way every other Radiology write path already is: an appointment only ever
 * resolves to one RadiologyOrderItem, which only ever resolves to one
 * RadiologyOrder/ClinicalEncounter/patient — there is no input path here
 * that accepts a patient_id, order_id, item_id, or procedure_id from the
 * caller, so a wrong-patient mix-up is structurally impossible. The
 * performer is always the authenticated user, never a value the client
 * submits.
 */
class RadiologyExaminationService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected FinancialAuthorizationService $financialAuth,
        protected NHIFPreauthorizationService $nhifPreauth,
        protected NHIFClinicalUtilizationService $nhifUtilization,
    ) {}

    /**
     * Start the examination for a READY appointment, safely serialized
     * against a second technician starting the same appointment
     * concurrently. An optional `resource_name` lets staff correct the
     * actual room/equipment used if it differs from what was scheduled —
     * audited as its own distinct event, never a silent overwrite.
     *
     * @param  array{resource_name?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function start(RadiologyAppointment $appointment, array $data, Request $request): RadiologyAppointment
    {
        $resourceChange = null;

        $appointment = DB::transaction(function () use ($appointment, $data, $request, &$resourceChange) {
            $appointment = RadiologyAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_IN_PROGRESS);
            $this->assertOrderAndItemActive($appointment);

            $itemIds = [$appointment->radiologyOrderItem->id];

            // Step 69: NHIF preauthorization gate
            $this->nhifPreauth->assertPreauthorizedForSources(
                RadiologyOrderItem::class,
                $itemIds,
            );

            // Step 62: financial authorization gate — no-charge rule: procedures
            // with no billing mapping (capture skipped) never block examination.
            $this->financialAuth->assertAuthorizedForSources(
                RadiologyOrderItem::class,
                $itemIds,
                FinancialAuthorizationService::STATUS_AUTHORIZED,
            );

            $update = [
                'status' => RadiologyAppointment::STATUS_IN_PROGRESS,
                'performed_by' => $request->user()->id,
                'started_at' => now(),
            ];

            $newResourceName = trim((string) ($data['resource_name'] ?? ''));

            if ($newResourceName !== '' && $newResourceName !== $appointment->resource_name) {
                $resourceChange = ['old' => $appointment->resource_name, 'new' => $newResourceName];
                $update['resource_name'] = $newResourceName;
            }

            $appointment->update($update);

            return $appointment;
        });

        if ($resourceChange !== null) {
            $this->auditLog->record(
                'radiology_examination_resource_changed',
                $appointment,
                ['resource_name' => $resourceChange['old']],
                ['resource_name' => $resourceChange['new']],
                $request,
            );
        }

        $this->auditLog->record(
            'radiology_examination_started',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_READY],
            $appointment->only(['status', 'performed_by', 'started_at']),
            $request,
        );

        return $appointment;
    }

    /**
     * Record (or update) modality-specific technical information while the
     * examination is under way. Never itself changes status — completion is
     * its own deliberate action.
     *
     * @param  array{technical_notes?: string|null, technical_details?: array<string, mixed>|null}  $data
     *
     * @throws ValidationException
     */
    public function recordTechnicalData(RadiologyAppointment $appointment, array $data, Request $request): RadiologyAppointment
    {
        if ($appointment->status !== RadiologyAppointment::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => "Cannot record technical information for an examination with status \"{$appointment->status}\".",
            ]);
        }

        $oldValues = $appointment->only(['technical_notes', 'technical_details']);

        $appointment->update([
            'technical_notes' => $this->validateNotes($data['technical_notes'] ?? null),
            'technical_details' => $this->validateTechnicalDetails($appointment, $data['technical_details'] ?? null),
        ]);

        $this->auditLog->record(
            'radiology_examination_technical_data_recorded',
            $appointment,
            $oldValues,
            $appointment->only(['technical_notes', 'technical_details']),
            $request,
        );

        return $appointment;
    }

    /**
     * Complete an in-progress examination — the handoff point to Step 42
     * (Findings & Results). Accepts the same optional technical-information
     * payload as recordTechnicalData() so a technician may finalize it in
     * the same action, but never accepts or finalizes any clinical
     * interpretation.
     *
     * @param  array{technical_notes?: string|null, technical_details?: array<string, mixed>|null}  $data
     *
     * @throws ValidationException
     */
    public function complete(RadiologyAppointment $appointment, array $data, Request $request): RadiologyAppointment
    {
        $appointment = DB::transaction(function () use ($appointment, $data, $request) {
            $appointment = RadiologyAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_COMPLETED);

            $appointment->update([
                'status' => RadiologyAppointment::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
                'technical_notes' => array_key_exists('technical_notes', $data)
                    ? $this->validateNotes($data['technical_notes'])
                    : $appointment->technical_notes,
                'technical_details' => array_key_exists('technical_details', $data)
                    ? $this->validateTechnicalDetails($appointment, $data['technical_details'])
                    : $appointment->technical_details,
            ]);

            return $appointment;
        });

        $this->auditLog->record(
            'radiology_examination_completed',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_IN_PROGRESS],
            $appointment->only(['status', 'completed_at', 'completed_by']),
            $request,
        );

        // Phase 8, Step 71: a COMPLETED examination is the performed service.
        // Report it to NHIF post-commit; never throws, never blocks or reverses
        // the completion. (Aborted/uncompleted examinations and report-only
        // activity never reach this line.)
        $this->nhifUtilization->captureRadiologyExamination($appointment, $request);

        return $appointment;
    }

    /**
     * Abort an in-progress examination with a required reason. Never
     * deletes the row — the examination remains fully historically
     * traceable, and the underlying order item is never itself cancelled,
     * so it becomes freely re-schedulable for a fresh attempt (Part 14; see
     * RadiologyAppointment::ACTIVE_STATUSES for how this is enforced).
     *
     * @throws ValidationException
     */
    public function abort(RadiologyAppointment $appointment, string $reason, Request $request): RadiologyAppointment
    {
        $appointment = DB::transaction(function () use ($appointment, $reason, $request) {
            $appointment = RadiologyAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransitionAllowed($appointment, RadiologyAppointment::STATUS_ABORTED);

            $appointment->update([
                'status' => RadiologyAppointment::STATUS_ABORTED,
                'aborted_at' => now(),
                'aborted_by' => $request->user()->id,
                'abort_reason' => $reason,
            ]);

            return $appointment;
        });

        $this->auditLog->record(
            'radiology_examination_aborted',
            $appointment,
            ['status' => RadiologyAppointment::STATUS_IN_PROGRESS],
            $appointment->only(['status', 'aborted_at', 'abort_reason']),
            $request,
        );

        return $appointment;
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(RadiologyAppointment $appointment, string $newStatus): void
    {
        if (! $appointment->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change examination status from \"{$appointment->status}\" to \"{$newStatus}\".",
            ]);
        }
    }

    /**
     * Defense-in-depth re-verification that the order/item behind this
     * appointment are still active (Part 7's explicit "order is not
     * cancelled" check). Structurally redundant in the ordinary flow —
     * cancelling an order/item already cascades to cancel any active
     * appointment (RadiologySchedulingService::cancelActiveAppointment*()),
     * and a cancelled appointment can never reach READY — but cheap
     * insurance against that invariant ever being violated, and explicitly
     * required by this step's own spec.
     *
     * @throws ValidationException
     */
    protected function assertOrderAndItemActive(RadiologyAppointment $appointment): void
    {
        $item = $appointment->radiologyOrderItem;

        if ($item->status === RadiologyOrderItem::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Cannot start an examination for a cancelled procedure.',
            ]);
        }

        if ($item->radiologyOrder->status === RadiologyOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Cannot start an examination for a cancelled radiology order.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validateNotes(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        if (mb_strlen($notes) > 4000) {
            throw ValidationException::withMessages(['technical_notes' => 'Technical notes may not exceed 4000 characters.']);
        }

        return $notes;
    }

    /**
     * Whitelist `technical_details` against the fields recognized for this
     * appointment's own modality (RadiologyAppointment::
     * TECHNICAL_FIELDS_BY_MODALITY) — never trust arbitrary client-supplied
     * keys, and never invent a field a modality does not actually use.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, string>|null
     *
     * @throws ValidationException
     */
    protected function validateTechnicalDetails(RadiologyAppointment $appointment, ?array $details): ?array
    {
        if ($details === null || $details === []) {
            return null;
        }

        $allowedKeys = RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY[$appointment->modality] ?? [];

        $unknown = array_diff(array_keys($details), $allowedKeys);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'technical_details' => 'The following technical fields are not recognized for this procedure\'s modality: '.implode(', ', $unknown).'.',
            ]);
        }

        $validated = [];

        foreach ($details as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw ValidationException::withMessages([
                    "technical_details.{$key}" => 'Each technical detail must be a simple value.',
                ]);
            }

            $value = (string) $value;

            if (mb_strlen($value) > 500) {
                throw ValidationException::withMessages([
                    "technical_details.{$key}" => 'Each technical detail may not exceed 500 characters.',
                ]);
            }

            $validated[$key] = $value;
        }

        return $validated === [] ? null : $validated;
    }
}
