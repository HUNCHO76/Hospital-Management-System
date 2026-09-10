<?php

namespace App\Services;

use App\Models\CarePlanItem;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owns the prescription-specific audit vocabulary (spec §30) separately from
 * the parent CarePlanItem's own treatment_item_* events (spec §22) — the
 * item's lifecycle (planned/active/completed/cancelled) is always
 * CarePlanService's job; this class only ever writes/audits the structured
 * medication data attached to a medication-type item.
 */
class PrescriptionService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record the structured prescription for a medication-type item.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(CarePlanItem $item, array $data, Request $request): Prescription
    {
        if ($item->type !== CarePlanItem::TYPE_MEDICATION) {
            throw ValidationException::withMessages([
                'type' => 'A prescription can only be attached to a medication item.',
            ]);
        }

        $prescription = Prescription::create([
            ...$data,
            'care_plan_item_id' => $item->id,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLog->record('prescription_created', $prescription, null, $prescription->only([
            'care_plan_item_id', 'medication_id', 'strength', 'dose', 'route', 'frequency', 'duration', 'quantity',
        ]), $request);

        return $prescription;
    }

    /**
     * Update a prescription's structured fields. Callers (CarePlanService)
     * are responsible for confirming the parent item is still mutable
     * before calling this.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Prescription $prescription, array $data, Request $request): Prescription
    {
        $oldValues = $prescription->only(array_keys($data));

        $prescription->update($data);

        $this->auditLog->record('prescription_updated', $prescription, $oldValues, $prescription->only(array_keys($data)), $request);

        return $prescription;
    }

    /**
     * Record that the prescription's parent item was cancelled. The item
     * itself carries the actual cancellation state (spec §9's separation
     * means there is no independent prescription status) — this exists
     * purely to emit the distinct prescription_cancelled audit event.
     */
    public function cancel(Prescription $prescription, string $reason, Request $request): void
    {
        $this->auditLog->record('prescription_cancelled', $prescription, null, ['cancellation_reason' => $reason], $request);
    }
}
