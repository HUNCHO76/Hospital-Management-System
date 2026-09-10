<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Laboratory is its own sibling branch off the clinical encounter (Phase 4,
 * Step 30) — never nested under a CarePlanItem. Mirrors DiagnosisService's
 * shape (encounter-lock, consultation-eligibility guard, create/update/
 * cancel + audit) but deliberately has no diagnosis requirement: spec §17
 * is explicit that urgent laboratory testing must not be blocked simply
 * because a final diagnosis has not yet been entered.
 */
class LaboratoryOrderService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record a new laboratory order with one or more tests.
     *
     * @param  array{test_ids: list<int>, priority?: string|null, clinical_indication?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): LaboratoryOrder
    {
        $order = DB::transaction(function () use ($encounter, $data, $request) {
            // Locking the encounter row serializes concurrent order creation
            // the same way TriageService/ConsultationService/DiagnosisService
            // serialize their own create-time invariants.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertConsultationEligible($encounter);

            $testIds = array_values(array_unique($data['test_ids']));

            if (count($testIds) !== count($data['test_ids'])) {
                throw ValidationException::withMessages([
                    'test_ids' => 'Duplicate tests cannot be added to the same laboratory order.',
                ]);
            }

            $tests = LaboratoryTest::query()->whereIn('id', $testIds)->where('status', LaboratoryTest::STATUS_ACTIVE)->get()->keyBy('id');

            if ($tests->count() !== count($testIds)) {
                throw ValidationException::withMessages([
                    'test_ids' => 'One or more selected tests are not available in the catalog.',
                ]);
            }

            $order = LaboratoryOrder::create([
                'clinical_encounter_id' => $encounter->id,
                'priority' => $data['priority'] ?? LaboratoryOrder::PRIORITY_ROUTINE,
                'clinical_indication' => $data['clinical_indication'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => LaboratoryOrder::STATUS_ORDERED,
                'ordered_by' => $request->user()->id,
                'ordered_at' => now(),
            ]);

            foreach ($testIds as $testId) {
                $this->addItem($order, $tests[$testId], $request);
            }

            return $order;
        });

        $this->auditLog->record(
            'laboratory_order_created',
            $order,
            null,
            $order->only(['clinical_encounter_id', 'priority', 'status']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Update an order's editable fields and optionally add more tests. Only
     * allowed while still ordered — once sample collection begins, this
     * becomes a laboratory-processing concern for a future step.
     *
     * @param  array{priority?: string|null, clinical_indication?: string|null, notes?: string|null, test_ids?: list<int>}  $data
     *
     * @throws ValidationException
     */
    public function update(LaboratoryOrder $order, array $data, Request $request): LaboratoryOrder
    {
        $this->assertMutable($order);

        $newTestIds = $data['test_ids'] ?? null;
        unset($data['test_ids']);

        if ($data !== []) {
            $oldValues = $order->only(array_keys($data));
            $priorityChanged = array_key_exists('priority', $data) && $data['priority'] !== $order->priority;

            $order->update($data);

            if ($priorityChanged) {
                $this->auditLog->record(
                    'laboratory_order_priority_changed',
                    $order,
                    ['priority' => $oldValues['priority']],
                    ['priority' => $order->priority],
                    $request,
                );
                unset($oldValues['priority'], $data['priority']);
            }

            if ($data !== []) {
                $this->auditLog->record('laboratory_order_updated', $order, $oldValues, $order->only(array_keys($data)), $request);
            }
        }

        if ($newTestIds !== null) {
            $this->addNewItems($order, $newTestIds, $request);
        }

        return $order->fresh('items');
    }

    /**
     * Cancel the order, recording who/when/why, and cascade the same
     * cancellation to every non-cancelled item.
     *
     * @throws ValidationException
     */
    public function cancel(LaboratoryOrder $order, string $reason, Request $request): LaboratoryOrder
    {
        if (! $order->canTransitionTo(LaboratoryOrder::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a laboratory order with status \"{$order->status}\".",
            ]);
        }

        $oldValues = $order->only(['status', 'cancelled_at', 'cancellation_reason']);

        DB::transaction(function () use ($order, $reason, $request) {
            $order->update([
                'status' => LaboratoryOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            $order->items()->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->get()->each(function (LaboratoryOrderItem $item) use ($reason, $request) {
                $item->update([
                    'status' => LaboratoryOrderItem::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $reason,
                ]);
            });
        });

        $this->auditLog->record(
            'laboratory_order_cancelled',
            $order,
            $oldValues,
            $order->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $order->fresh('items');
    }

    /**
     * Remove a single test from an order, only while both the parent order
     * and the item itself are still ordered (spec §26 — only while the order
     * is in an editable state).
     *
     * @throws ValidationException
     */
    public function cancelItem(LaboratoryOrderItem $item, string $reason, Request $request): LaboratoryOrderItem
    {
        $order = $item->laboratoryOrder;

        $this->assertMutable($order);

        if (! $item->canTransitionTo(LaboratoryOrderItem::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot remove a laboratory order item with status \"{$item->status}\".",
            ]);
        }

        $oldValues = $item->only(['status', 'cancelled_at', 'cancellation_reason']);

        $item->update([
            'status' => LaboratoryOrderItem::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'laboratory_order_item_removed',
            $item,
            $oldValues,
            $item->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $item;
    }

    /**
     * Create and audit a single order item, snapshotting the test's code/name
     * from the live catalog row at this moment (spec §44 — historical
     * integrity, so a later catalog rename/deactivation never makes an
     * existing order ambiguous).
     */
    protected function addItem(LaboratoryOrder $order, LaboratoryTest $test, Request $request): LaboratoryOrderItem
    {
        $item = LaboratoryOrderItem::create([
            'laboratory_order_id' => $order->id,
            'laboratory_test_id' => $test->id,
            'test_code' => $test->code,
            'test_name' => $test->name,
            'status' => LaboratoryOrderItem::STATUS_ORDERED,
        ]);

        $this->auditLog->record(
            'laboratory_order_item_added',
            $item,
            null,
            $item->only(['laboratory_order_id', 'laboratory_test_id', 'test_code', 'status']),
            $request,
        );

        return $item;
    }

    /**
     * Add tests to an already-existing order via update(). Only genuinely
     * new, active, non-duplicate tests are added — existing items are never
     * touched here (removal is cancelItem()'s job, spec §26).
     *
     * @param  list<int>  $testIds
     *
     * @throws ValidationException
     */
    protected function addNewItems(LaboratoryOrder $order, array $testIds, Request $request): void
    {
        $testIds = array_values(array_unique($testIds));

        $existingTestIds = $order->items()->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->pluck('laboratory_test_id')->all();

        $newTestIds = array_values(array_diff($testIds, $existingTestIds));

        if ($newTestIds === []) {
            return;
        }

        $tests = LaboratoryTest::query()->whereIn('id', $newTestIds)->where('status', LaboratoryTest::STATUS_ACTIVE)->get()->keyBy('id');

        if ($tests->count() !== count($newTestIds)) {
            throw ValidationException::withMessages([
                'test_ids' => 'One or more selected tests are not available in the catalog.',
            ]);
        }

        foreach ($newTestIds as $testId) {
            $this->addItem($order, $tests[$testId], $request);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot record a laboratory order on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * Confirm the encounter has a consultation the doctor has actually
     * engaged with (mirrors DiagnosisService::assertConsultationEligible()
     * exactly). Deliberately does NOT require a diagnosis — spec §17's
     * explicit exception so urgent care is never blocked on a final
     * diagnosis not yet being entered.
     *
     * @throws ValidationException
     */
    protected function assertConsultationEligible(ClinicalEncounter $encounter): Consultation
    {
        $consultation = $encounter->consultation;

        if (! $consultation || ! in_array($consultation->status, [Consultation::STATUS_IN_PROGRESS, Consultation::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'A consultation must be started before a laboratory order can be placed for this encounter.',
            ]);
        }

        return $consultation;
    }

    /**
     * @throws ValidationException
     */
    protected function assertMutable(LaboratoryOrder $order): void
    {
        if ($order->status !== LaboratoryOrder::STATUS_ORDERED) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a laboratory order with status \"{$order->status}\".",
            ]);
        }
    }
}
