<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\RadiologyProcedure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radiology is its own sibling branch off the clinical encounter (Phase 5,
 * Step 36) — never nested under a CarePlanItem. Mirrors
 * LaboratoryOrderService's shape deliberately (encounter-lock, consultation-
 * eligibility guard, create/update/cancel + audit), with no diagnosis
 * requirement for the same reason: urgent imaging must not be blocked
 * simply because a final diagnosis has not yet been entered.
 */
class RadiologyOrderService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record a new radiology order with one or more procedures.
     *
     * @param  array{procedure_ids: list<int>, priority?: string|null, clinical_indication?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): RadiologyOrder
    {
        $order = DB::transaction(function () use ($encounter, $data, $request) {
            // Locking the encounter row serializes concurrent order creation
            // the same way LaboratoryOrderService/DiagnosisService/
            // ConsultationService serialize their own create-time invariants.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertConsultationEligible($encounter);

            $procedureIds = array_values(array_unique($data['procedure_ids']));

            if (count($procedureIds) !== count($data['procedure_ids'])) {
                throw ValidationException::withMessages([
                    'procedure_ids' => 'Duplicate procedures cannot be added to the same radiology order.',
                ]);
            }

            $procedures = RadiologyProcedure::query()->whereIn('id', $procedureIds)->where('status', RadiologyProcedure::STATUS_ACTIVE)->get()->keyBy('id');

            if ($procedures->count() !== count($procedureIds)) {
                throw ValidationException::withMessages([
                    'procedure_ids' => 'One or more selected procedures are not available in the catalog.',
                ]);
            }

            $order = RadiologyOrder::create([
                'clinical_encounter_id' => $encounter->id,
                'priority' => $data['priority'] ?? RadiologyOrder::PRIORITY_ROUTINE,
                'clinical_indication' => $data['clinical_indication'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => RadiologyOrder::STATUS_ORDERED,
                'ordered_by' => $request->user()->id,
                'ordered_at' => now(),
            ]);

            foreach ($procedureIds as $procedureId) {
                $this->addItem($order, $procedures[$procedureId], $request);
            }

            return $order;
        });

        $this->auditLog->record(
            'radiology_order_created',
            $order,
            null,
            $order->only(['clinical_encounter_id', 'priority', 'status']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Update an order's editable fields and optionally add more procedures.
     * Only allowed while still ordered — once scheduling/examination
     * begins, this becomes a future step's concern.
     *
     * @param  array{priority?: string|null, clinical_indication?: string|null, notes?: string|null, procedure_ids?: list<int>}  $data
     *
     * @throws ValidationException
     */
    public function update(RadiologyOrder $order, array $data, Request $request): RadiologyOrder
    {
        $this->assertMutable($order);

        $newProcedureIds = $data['procedure_ids'] ?? null;
        unset($data['procedure_ids']);

        if ($data !== []) {
            $oldValues = $order->only(array_keys($data));
            $priorityChanged = array_key_exists('priority', $data) && $data['priority'] !== $order->priority;

            $order->update($data);

            if ($priorityChanged) {
                $this->auditLog->record(
                    'radiology_order_priority_changed',
                    $order,
                    ['priority' => $oldValues['priority']],
                    ['priority' => $order->priority],
                    $request,
                );
                unset($oldValues['priority'], $data['priority']);
            }

            if ($data !== []) {
                $this->auditLog->record('radiology_order_updated', $order, $oldValues, $order->only(array_keys($data)), $request);
            }
        }

        if ($newProcedureIds !== null) {
            $this->addNewItems($order, $newProcedureIds, $request);
        }

        return $order->fresh('items');
    }

    /**
     * Cancel the order, recording who/when/why, and cascade the same
     * cancellation to every non-cancelled item. Never deletes — a
     * cancelled clinical order remains historically traceable (Part 25).
     *
     * @throws ValidationException
     */
    public function cancel(RadiologyOrder $order, string $reason, Request $request): RadiologyOrder
    {
        if (! $order->canTransitionTo(RadiologyOrder::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a radiology order with status \"{$order->status}\".",
            ]);
        }

        $oldValues = $order->only(['status', 'cancelled_at', 'cancellation_reason']);

        DB::transaction(function () use ($order, $reason, $request) {
            $order->update([
                'status' => RadiologyOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            $order->items()->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)->get()->each(function (RadiologyOrderItem $item) use ($reason, $request) {
                $item->update([
                    'status' => RadiologyOrderItem::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $reason,
                ]);
            });
        });

        $this->auditLog->record(
            'radiology_order_cancelled',
            $order,
            $oldValues,
            $order->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Remove a single procedure from an order, only while both the parent
     * order and the item itself are still ordered.
     *
     * @throws ValidationException
     */
    public function cancelItem(RadiologyOrderItem $item, string $reason, Request $request): RadiologyOrderItem
    {
        $order = $item->radiologyOrder;

        $this->assertMutable($order);

        if (! $item->canTransitionTo(RadiologyOrderItem::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot remove a radiology order item with status \"{$item->status}\".",
            ]);
        }

        $oldValues = $item->only(['status', 'cancelled_at', 'cancellation_reason']);

        $item->update([
            'status' => RadiologyOrderItem::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'radiology_order_item_removed',
            $item,
            $oldValues,
            $item->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $item;
    }

    /**
     * Create and audit a single order item, snapshotting the procedure's
     * code/name from the live catalog row at this moment (historical
     * integrity — a later catalog rename/deactivation must never make an
     * existing order ambiguous).
     */
    protected function addItem(RadiologyOrder $order, RadiologyProcedure $procedure, Request $request): RadiologyOrderItem
    {
        $item = RadiologyOrderItem::create([
            'radiology_order_id' => $order->id,
            'radiology_procedure_id' => $procedure->id,
            'procedure_code' => $procedure->code,
            'procedure_name' => $procedure->name,
            'modality_snapshot' => $procedure->modality,
            'body_site_snapshot' => $procedure->body_sites,
            'status' => RadiologyOrderItem::STATUS_ORDERED,
        ]);

        $this->auditLog->record(
            'radiology_order_item_added',
            $item,
            null,
            $item->only(['radiology_order_id', 'radiology_procedure_id', 'procedure_code', 'status']),
            $request,
        );

        return $item;
    }

    /**
     * Add procedures to an already-existing order via update(). Only
     * genuinely new, active, non-duplicate procedures are added — existing
     * items are never touched here (removal is cancelItem()'s job).
     *
     * @param  list<int>  $procedureIds
     *
     * @throws ValidationException
     */
    protected function addNewItems(RadiologyOrder $order, array $procedureIds, Request $request): void
    {
        $procedureIds = array_values(array_unique($procedureIds));

        $existingProcedureIds = $order->items()->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)->pluck('radiology_procedure_id')->all();

        $newProcedureIds = array_values(array_diff($procedureIds, $existingProcedureIds));

        if ($newProcedureIds === []) {
            return;
        }

        $procedures = RadiologyProcedure::query()->whereIn('id', $newProcedureIds)->where('status', RadiologyProcedure::STATUS_ACTIVE)->get()->keyBy('id');

        if ($procedures->count() !== count($newProcedureIds)) {
            throw ValidationException::withMessages([
                'procedure_ids' => 'One or more selected procedures are not available in the catalog.',
            ]);
        }

        foreach ($newProcedureIds as $procedureId) {
            $this->addItem($order, $procedures[$procedureId], $request);
        }

        // A freshly added item can never already be scheduled, so a
        // SCHEDULED order (Step 39's "every item has an appointment"
        // rollup) must revert to ORDERED the moment a new, unscheduled item
        // joins it. This is local to RadiologyOrderService's own fillable
        // status column — no dependency on RadiologySchedulingService.
        if ($order->status === RadiologyOrder::STATUS_SCHEDULED) {
            $order->update(['status' => RadiologyOrder::STATUS_ORDERED]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot record a radiology order on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * Confirm the encounter has a consultation the doctor has actually
     * engaged with (mirrors LaboratoryOrderService::assertConsultationEligible()
     * exactly). Deliberately does NOT require a diagnosis — urgent imaging
     * must not be blocked on a final diagnosis not yet being entered.
     *
     * @throws ValidationException
     */
    protected function assertConsultationEligible(ClinicalEncounter $encounter): Consultation
    {
        $consultation = $encounter->consultation;

        if (! $consultation || ! in_array($consultation->status, [Consultation::STATUS_IN_PROGRESS, Consultation::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'A consultation must be started before a radiology order can be placed for this encounter.',
            ]);
        }

        return $consultation;
    }

    /**
     * ORDERED and SCHEDULED (Phase 5, Step 39's derived rollup — Part 21)
     * are both editable states; only CANCELLED locks an order down.
     *
     * @throws ValidationException
     */
    protected function assertMutable(RadiologyOrder $order): void
    {
        if (! in_array($order->status, [RadiologyOrder::STATUS_ORDERED, RadiologyOrder::STATUS_SCHEDULED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a radiology order with status \"{$order->status}\".",
            ]);
        }
    }
}
