<?php

namespace App\Services;

use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\ClinicalEncounter;
use App\Models\EncounterDiagnosis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CarePlanService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected PrescriptionService $prescriptions,
    ) {}

    /**
     * Start a care plan for an encounter. Requires at least one non-cancelled
     * diagnosis to exist (spec §14) — this transitively guarantees the
     * consultation was engaged too, since a diagnosis can't exist without
     * one.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): CarePlan
    {
        $carePlan = DB::transaction(function () use ($encounter, $data, $request) {
            // Locking the encounter row serializes concurrent attempts to
            // create a care plan for the same encounter, the same pattern
            // TriageService/ConsultationService/DiagnosisService use for
            // their own create-time invariants.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertDiagnosisExists($encounter);
            $this->assertNoExistingCarePlan($encounter);

            return CarePlan::create([
                ...$data,
                'clinical_encounter_id' => $encounter->id,
                'consultation_id' => $encounter->consultation?->id,
                'status' => CarePlan::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);
        });

        $this->auditLog->record('care_plan_created', $carePlan, null, $carePlan->only(['clinical_encounter_id', 'status']), $request);

        return $carePlan;
    }

    /**
     * Update the plan's goals/instructions. Only allowed while draft/active
     * — a completed/cancelled plan is a committed clinical record, amendable
     * only through correct() (spec §31).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(CarePlan $carePlan, array $data, Request $request): CarePlan
    {
        $this->assertMutable($carePlan);

        $oldValues = $carePlan->only(array_keys($data));

        $carePlan->update($data);

        $this->auditLog->record('care_plan_updated', $carePlan, $oldValues, $carePlan->only(array_keys($data)), $request);

        return $carePlan;
    }

    /**
     * Activate the plan. Requires at least one non-cancelled item — an
     * "active" plan must have actual treatment content (spec §6).
     *
     * @throws ValidationException
     */
    public function activate(CarePlan $carePlan, Request $request): CarePlan
    {
        $this->assertTransitionAllowed($carePlan, CarePlan::STATUS_ACTIVE);

        if (! $carePlan->items()->where('status', '!=', CarePlanItem::STATUS_CANCELLED)->exists()) {
            throw ValidationException::withMessages([
                'status' => 'At least one treatment item must be added before activating the plan.',
            ]);
        }

        $oldValues = $carePlan->only(['status', 'started_at']);

        $carePlan->update(['status' => CarePlan::STATUS_ACTIVE, 'started_at' => now()]);

        $this->auditLog->record('care_plan_activated', $carePlan, $oldValues, $carePlan->only(['status', 'started_at']), $request);

        return $carePlan;
    }

    /**
     * Complete the plan.
     *
     * @throws ValidationException
     */
    public function complete(CarePlan $carePlan, Request $request): CarePlan
    {
        $this->assertTransitionAllowed($carePlan, CarePlan::STATUS_COMPLETED);

        $oldValues = $carePlan->only(['status', 'completed_at', 'completed_by']);

        $carePlan->update([
            'status' => CarePlan::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
        ]);

        $this->auditLog->record('care_plan_completed', $carePlan, $oldValues, $carePlan->only(['status', 'completed_at', 'completed_by']), $request);

        return $carePlan;
    }

    /**
     * Cancel the plan, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(CarePlan $carePlan, string $reason, Request $request): CarePlan
    {
        $this->assertTransitionAllowed($carePlan, CarePlan::STATUS_CANCELLED);

        $oldValues = $carePlan->only(['status', 'cancelled_at', 'cancellation_reason']);

        $carePlan->update([
            'status' => CarePlan::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('care_plan_cancelled', $carePlan, $oldValues, $carePlan->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        return $carePlan;
    }

    /**
     * Amend a completed plan's goals/instructions. The controlled correction
     * workflow required by spec §31 — the audit log's old/new diff is the
     * "what changed" record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correct(CarePlan $carePlan, array $data, string $reason, Request $request): CarePlan
    {
        if ($carePlan->status !== CarePlan::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Only a completed care plan can be corrected.',
            ]);
        }

        $oldValues = $carePlan->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']);

        $carePlan->update([
            ...$data,
            'corrected_at' => now(),
            'corrected_by' => $request->user()->id,
            'correction_reason' => $reason,
        ]);

        $this->auditLog->record(
            'care_plan_corrected',
            $carePlan,
            $oldValues,
            $carePlan->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']),
            $request,
        );

        return $carePlan;
    }

    /**
     * Add a treatment item to the plan. If the item is medication and
     * prescription data is supplied, the structured prescription is created
     * through PrescriptionService (spec §22 — kept separate).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function addItem(CarePlan $carePlan, array $data, Request $request): CarePlanItem
    {
        $this->assertMutable($carePlan);

        if (! empty($data['encounter_diagnosis_id'])) {
            $this->assertDiagnosisBelongsToEncounter($carePlan, (int) $data['encounter_diagnosis_id']);
        }

        $prescriptionData = $data['prescription'] ?? null;
        unset($data['prescription']);

        $item = CarePlanItem::create([
            ...$data,
            'care_plan_id' => $carePlan->id,
            'status' => CarePlanItem::STATUS_PLANNED,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('treatment_item_created', $item, null, $item->only(['care_plan_id', 'type', 'status']), $request);

        if ($item->type === CarePlanItem::TYPE_MEDICATION && $prescriptionData) {
            $this->prescriptions->create($item, $prescriptionData, $request);
        }

        return $item->fresh();
    }

    /**
     * Update an item's editable fields. Only allowed while still planned —
     * an active/completed item represents treatment already underway
     * (spec §31).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateItem(CarePlanItem $item, array $data, Request $request): CarePlanItem
    {
        if ($item->status !== CarePlanItem::STATUS_PLANNED) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a treatment item with status \"{$item->status}\".",
            ]);
        }

        $prescriptionData = $data['prescription'] ?? null;
        unset($data['prescription']);

        if ($data !== []) {
            $oldValues = $item->only(array_keys($data));
            $item->update($data);
            $this->auditLog->record('treatment_item_updated', $item, $oldValues, $item->only(array_keys($data)), $request);
        }

        if ($item->type === CarePlanItem::TYPE_MEDICATION && $prescriptionData) {
            if ($item->prescription) {
                $this->prescriptions->update($item->prescription, $prescriptionData, $request);
            } else {
                $this->prescriptions->create($item, $prescriptionData, $request);
            }
        }

        return $item->fresh();
    }

    /**
     * Advance the item through planned -> active -> completed. Cancellation
     * is deliberately not reachable here — see cancelItem().
     *
     * @throws ValidationException
     */
    public function changeItemStatus(CarePlanItem $item, string $newStatus, Request $request): CarePlanItem
    {
        if ($newStatus === CarePlanItem::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Use the cancel action to cancel a treatment item.',
            ]);
        }

        if (! $item->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change treatment item status from \"{$item->status}\" to \"{$newStatus}\".",
            ]);
        }

        $oldStatus = $item->status;

        $updates = ['status' => $newStatus];
        if ($newStatus === CarePlanItem::STATUS_ACTIVE) {
            $updates['activated_at'] = now();
            $updates['activated_by'] = $request->user()->id;
        } elseif ($newStatus === CarePlanItem::STATUS_COMPLETED) {
            $updates['completed_at'] = now();
            $updates['completed_by'] = $request->user()->id;
        }

        $item->update($updates);

        $this->auditLog->record('treatment_item_status_changed', $item, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $item;
    }

    /**
     * Cancel the item, recording who/when/why. If the item is a medication,
     * the prescription's own cancellation event is also recorded.
     *
     * @throws ValidationException
     */
    public function cancelItem(CarePlanItem $item, string $reason, Request $request): CarePlanItem
    {
        if (! $item->canTransitionTo(CarePlanItem::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a treatment item with status \"{$item->status}\".",
            ]);
        }

        $oldValues = $item->only(['status', 'cancelled_at', 'cancellation_reason']);

        $item->update([
            'status' => CarePlanItem::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('treatment_item_cancelled', $item, $oldValues, $item->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        if ($item->type === CarePlanItem::TYPE_MEDICATION && $item->prescription) {
            $this->prescriptions->cancel($item->prescription, $reason, $request);
        }

        return $item;
    }

    /**
     * Amend a completed item's non-prescription fields. Prescription
     * structured data is never corrected here — a wrong dose on a completed
     * item is cancelled and re-added as a new item, not silently edited
     * (spec §31's safety intent applied to medication specifically).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correctItem(CarePlanItem $item, array $data, string $reason, Request $request): CarePlanItem
    {
        if ($item->status !== CarePlanItem::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Only a completed treatment item can be corrected.',
            ]);
        }

        $oldValues = $item->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']);

        $item->update([
            ...$data,
            'corrected_at' => now(),
            'corrected_by' => $request->user()->id,
            'correction_reason' => $reason,
        ]);

        $this->auditLog->record(
            'treatment_item_corrected',
            $item,
            $oldValues,
            $item->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']),
            $request,
        );

        return $item;
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot start a care plan on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertDiagnosisExists(ClinicalEncounter $encounter): void
    {
        $hasDiagnosis = EncounterDiagnosis::query()
            ->where('clinical_encounter_id', $encounter->id)
            ->where('status', '!=', EncounterDiagnosis::STATUS_CANCELLED)
            ->exists();

        if (! $hasDiagnosis) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'A diagnosis must be recorded before a treatment plan can be created for this encounter.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoExistingCarePlan(ClinicalEncounter $encounter): void
    {
        if (CarePlan::query()->where('clinical_encounter_id', $encounter->id)->exists()) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'This encounter already has a treatment plan.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertDiagnosisBelongsToEncounter(CarePlan $carePlan, int $encounterDiagnosisId): void
    {
        $belongs = EncounterDiagnosis::query()
            ->whereKey($encounterDiagnosisId)
            ->where('clinical_encounter_id', $carePlan->clinical_encounter_id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'encounter_diagnosis_id' => 'The selected diagnosis does not belong to this encounter.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertMutable(CarePlan $carePlan): void
    {
        if (in_array($carePlan->status, [CarePlan::STATUS_COMPLETED, CarePlan::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a care plan with status \"{$carePlan->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(CarePlan $carePlan, string $newStatus): void
    {
        if (! $carePlan->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change care plan status from \"{$carePlan->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
