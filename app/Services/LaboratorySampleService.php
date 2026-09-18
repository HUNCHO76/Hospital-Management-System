<?php

namespace App\Services;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratorySample;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the specimen lifecycle (Phase 4, Step 31): registering a sample
 * against one or more compatible order items, recording its physical
 * collection, labelling, laboratory receipt, rejection, and recollection.
 * Every mutation rolls the affected LaboratoryOrderItem(s) and their parent
 * LaboratoryOrder's own status up to reflect the aggregate specimen state
 * (spec §39-40) — those rollups are internal derived state, never a
 * user-facing transition, so they deliberately bypass canTransitionTo()
 * where a rejection needs to move an item/order backward.
 */
class LaboratorySampleService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected FinancialAuthorizationService $financialAuth,
        protected NHIFPreauthorizationService $nhifPreauth,
    ) {}

    /**
     * Register a new specimen against one or more of an order's compatible
     * items. Concurrency-safe: locks the order and the requested item rows
     * so two simultaneous registrations can never both claim the same
     * item (spec §45).
     *
     * @param  array{specimen_type: string, container_type?: string|null, item_ids: list<int>}  $data
     *
     * @throws ValidationException
     */
    public function create(LaboratoryOrder $order, array $data, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($order, $data, $request) {
            $order = LaboratoryOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertOrderCollectable($order);

            $itemIds = array_values(array_unique($data['item_ids']));

            $items = LaboratoryOrderItem::query()
                ->whereIn('id', $itemIds)
                ->where('laboratory_order_id', $order->id)
                ->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)
                ->with('laboratoryTest')
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'One or more selected tests do not belong to this order or are no longer active.',
                ]);
            }

            $itemIds = $items->pluck('id')->all();

            // Step 69: NHIF preauthorization gate — runs before financial auth
            // and only blocks when the service mapping requires preauth.
            $this->nhifPreauth->assertPreauthorizedForSources(
                LaboratoryOrderItem::class,
                $itemIds,
            );

            // Step 62: financial authorization gate
            $this->financialAuth->assertAuthorizedForSources(
                LaboratoryOrderItem::class,
                $itemIds,
            );

            $containerType = $data['container_type'] ?? null;
            $this->assertSpecimenMatchesCatalog($items, $data['specimen_type'], $containerType);
            $this->assertNoActiveSampleConflict($items);

            $sample = LaboratorySample::create([
                'laboratory_order_id' => $order->id,
                'clinical_encounter_id' => $order->clinical_encounter_id,
                'patient_id' => $order->clinicalEncounter->patient_id,
                'specimen_type' => $data['specimen_type'],
                'container_type' => $containerType,
                'status' => LaboratorySample::STATUS_PENDING_COLLECTION,
            ]);

            $sample->items()->attach($items->pluck('id'));

            foreach ($items as $item) {
                $this->recomputeItemStatus($item);
            }

            $this->recomputeOrderStatus($order, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_created',
            $sample,
            null,
            $sample->only(['laboratory_order_id', 'specimen_type', 'container_type', 'status']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Record the physical collection of a registered specimen.
     *
     * @param  array{collection_site?: string|null, collection_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function collect(LaboratorySample $sample, array $data, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($sample, $data, $request) {
            $sample = LaboratorySample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if (! $sample->canTransitionTo(LaboratorySample::STATUS_COLLECTED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot collect a sample with status \"{$sample->status}\".",
                ]);
            }

            $sample->update([
                'status' => LaboratorySample::STATUS_COLLECTED,
                'collected_by' => $request->user()->id,
                'collected_at' => now(),
                'collection_site' => $data['collection_site'] ?? null,
                'collection_notes' => $data['collection_notes'] ?? null,
            ]);

            $this->rollUpFromSample($sample, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_collected',
            $sample,
            null,
            $sample->only(['status', 'collected_by', 'collected_at']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Generate (or reprint) the specimen label. Only the first call
     * transitions the sample to LABELLED; every subsequent call is a
     * reprint that leaves the sample number and status untouched (spec
     * §24 — reprinting must never create a new sample).
     *
     * @throws ValidationException
     */
    public function label(LaboratorySample $sample, Request $request): LaboratorySample
    {
        if (in_array($sample->status, [LaboratorySample::STATUS_LABELLED, LaboratorySample::STATUS_RECEIVED], true)) {
            $this->auditLog->record('laboratory_sample_label_reprinted', $sample, null, null, $request);

            return $sample;
        }

        if (! $sample->canTransitionTo(LaboratorySample::STATUS_LABELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot label a sample with status \"{$sample->status}\".",
            ]);
        }

        $sample->update(['status' => LaboratorySample::STATUS_LABELLED, 'labelled_at' => now()]);

        $this->auditLog->record('laboratory_sample_labelled', $sample, null, $sample->only(['status', 'labelled_at']), $request);

        return $sample;
    }

    /**
     * Record laboratory receipt of a correctly labelled specimen (spec
     * §35-36).
     *
     * @throws ValidationException
     */
    public function receive(LaboratorySample $sample, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($sample, $request) {
            $sample = LaboratorySample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if (! $sample->canTransitionTo(LaboratorySample::STATUS_RECEIVED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot receive a sample with status \"{$sample->status}\". It must be labelled first.",
                ]);
            }

            $sample->update([
                'status' => LaboratorySample::STATUS_RECEIVED,
                'received_by' => $request->user()->id,
                'received_at' => now(),
            ]);

            $this->rollUpFromSample($sample, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_received',
            $sample,
            null,
            $sample->only(['status', 'received_by', 'received_at']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Reject a collected specimen, recording who/when/why. Never deletes
     * or overwrites the sample (spec §32) — the affected items revert to
     * needing a fresh specimen (see recollect()).
     *
     * @param  array{rejection_reason: string, rejection_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function reject(LaboratorySample $sample, array $data, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($sample, $data, $request) {
            $sample = LaboratorySample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if (! $sample->canTransitionTo(LaboratorySample::STATUS_REJECTED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot reject a sample with status \"{$sample->status}\".",
                ]);
            }

            $sample->update([
                'status' => LaboratorySample::STATUS_REJECTED,
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => $data['rejection_reason'],
                'rejection_notes' => $data['rejection_notes'] ?? null,
            ]);

            $this->rollUpFromSample($sample, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_rejected',
            $sample,
            null,
            $sample->only(['status', 'rejected_by', 'rejected_at', 'rejection_reason']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Create a fresh specimen to replace a rejected one, preserving the
     * full history via `recollection_of_sample_id` (spec §33-34). Carries
     * over the rejected sample's own item set; specimen/container may be
     * overridden (e.g. a wrong-container rejection needs a different tube).
     *
     * @param  array{specimen_type?: string|null, container_type?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function recollect(LaboratorySample $rejected, array $data, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($rejected, $data, $request) {
            $rejected = LaboratorySample::query()->whereKey($rejected->id)->lockForUpdate()->firstOrFail();

            if ($rejected->status !== LaboratorySample::STATUS_REJECTED) {
                throw ValidationException::withMessages([
                    'status' => 'Only a rejected sample can be recollected.',
                ]);
            }

            $order = LaboratoryOrder::query()->whereKey($rejected->laboratory_order_id)->lockForUpdate()->firstOrFail();

            $itemIds = $rejected->items()->pluck('laboratory_order_items.id');
            $items = LaboratoryOrderItem::query()->whereIn('id', $itemIds)->with('laboratoryTest')->lockForUpdate()->get();
            $specimenType = $data['specimen_type'] ?? $rejected->specimen_type;
            $containerType = $data['container_type'] ?? $rejected->container_type;

            $this->assertSpecimenMatchesCatalog($items, $specimenType, $containerType);
            $this->assertNoActiveSampleConflict($items);

            $sample = LaboratorySample::create([
                'laboratory_order_id' => $order->id,
                'clinical_encounter_id' => $rejected->clinical_encounter_id,
                'patient_id' => $rejected->patient_id,
                'specimen_type' => $specimenType,
                'container_type' => $containerType,
                'status' => LaboratorySample::STATUS_PENDING_COLLECTION,
                'recollection_of_sample_id' => $rejected->id,
            ]);

            $sample->items()->attach($items->pluck('id'));

            foreach ($items as $item) {
                $this->recomputeItemStatus($item);
            }

            $this->recomputeOrderStatus($order, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_recollection_created',
            $sample,
            null,
            $sample->only(['laboratory_order_id', 'recollection_of_sample_id', 'specimen_type', 'container_type']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Cancel a specimen before it has ever been physically collected (spec
     * §42). Never reachable once collected — see reject() instead.
     *
     * @throws ValidationException
     */
    public function cancel(LaboratorySample $sample, string $reason, Request $request): LaboratorySample
    {
        $sample = DB::transaction(function () use ($sample, $reason, $request) {
            $sample = LaboratorySample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if (! $sample->canTransitionTo(LaboratorySample::STATUS_CANCELLED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot cancel a sample with status \"{$sample->status}\".",
                ]);
            }

            $sample->update([
                'status' => LaboratorySample::STATUS_CANCELLED,
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->rollUpFromSample($sample, $request);

            return $sample;
        });

        $this->auditLog->record(
            'laboratory_sample_cancelled',
            $sample,
            null,
            $sample->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $sample->fresh('items');
    }

    /**
     * Roll every item attached to this sample, then the parent order, up
     * to reflect the sample's new status.
     */
    protected function rollUpFromSample(LaboratorySample $sample, Request $request): void
    {
        $order = LaboratoryOrder::query()->whereKey($sample->laboratory_order_id)->firstOrFail();

        $sample->items()->get()->each(fn (LaboratoryOrderItem $item) => $this->recomputeItemStatus($item));

        $this->recomputeOrderStatus($order, $request);
    }

    /**
     * Recompute a single item's own `status` from whichever of its samples
     * is currently the active one (its most recent non-cancelled sample).
     * A rejected sample is treated the same as "no active sample" — the
     * item simply needs a fresh one (spec §33). Deliberately bypasses
     * canTransitionTo(): this is a derived read-model, not a user-facing
     * transition, and must be free to move backward.
     */
    protected function recomputeItemStatus(LaboratoryOrderItem $item): void
    {
        if ($item->status === LaboratoryOrderItem::STATUS_CANCELLED) {
            return;
        }

        $active = $item->samples()
            ->where('laboratory_samples.status', '!=', LaboratorySample::STATUS_CANCELLED)
            ->reorder('laboratory_samples.id', 'desc')
            ->first();

        $newStatus = match (true) {
            $active === null, $active->status === LaboratorySample::STATUS_REJECTED => LaboratoryOrderItem::STATUS_ORDERED,
            $active->status === LaboratorySample::STATUS_PENDING_COLLECTION => LaboratoryOrderItem::STATUS_SAMPLE_PENDING,
            in_array($active->status, [LaboratorySample::STATUS_COLLECTED, LaboratorySample::STATUS_LABELLED], true) => LaboratoryOrderItem::STATUS_SAMPLE_COLLECTED,
            $active->status === LaboratorySample::STATUS_RECEIVED => LaboratoryOrderItem::STATUS_RECEIVED,
            default => $item->status,
        };

        if ($newStatus !== $item->status) {
            $item->update(['status' => $newStatus]);
        }
    }

    /**
     * Recompute the order's own `status` from the aggregate state of its
     * non-cancelled items (spec §39-40 — partial collection must never be
     * reported as fully collected). Deliberately bypasses
     * canTransitionTo() for the same reason as recomputeItemStatus().
     */
    protected function recomputeOrderStatus(LaboratoryOrder $order, Request $request): void
    {
        $items = $order->items()->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->get();

        if ($items->isEmpty() || $order->status === LaboratoryOrder::STATUS_CANCELLED) {
            return;
        }

        $newStatus = LaboratoryOrder::statusFromItemProgress($items->pluck('status'));

        if ($newStatus !== $order->status) {
            $oldStatus = $order->status;
            $order->update(['status' => $newStatus]);

            $this->auditLog->record(
                'laboratory_order_status_changed',
                $order,
                ['status' => $oldStatus],
                ['status' => $newStatus],
                $request,
            );
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertOrderCollectable(LaboratoryOrder $order): void
    {
        if (! in_array($order->status, [LaboratoryOrder::STATUS_ORDERED, LaboratoryOrder::STATUS_SAMPLE_PENDING], true)) {
            throw ValidationException::withMessages([
                'laboratory_order_id' => "Cannot register a specimen against an order with status \"{$order->status}\".",
            ]);
        }
    }

    /**
     * Verify the chosen specimen/container against every selected item's
     * own catalog requirement (spec §28-29) — never against a hard-coded
     * test name.
     *
     * @param  Collection<int, LaboratoryOrderItem>  $items
     *
     * @throws ValidationException
     */
    protected function assertSpecimenMatchesCatalog(Collection $items, string $specimenType, ?string $containerType): void
    {
        foreach ($items as $item) {
            $test = $item->laboratoryTest;

            if (! $test || $test->specimen_type === null) {
                throw ValidationException::withMessages([
                    'specimen_type' => "\"{$item->test_name}\" has no specimen requirement configured in the catalog.",
                ]);
            }

            if ($test->specimen_type !== $specimenType) {
                throw ValidationException::withMessages([
                    'specimen_type' => "\"{$item->test_name}\" requires a {$test->specimen_type} specimen, not {$specimenType}.",
                ]);
            }

            if ($test->specimen_container !== null && $test->specimen_container !== $containerType) {
                throw ValidationException::withMessages([
                    'container_type' => "\"{$item->test_name}\" requires a {$test->specimen_container} container.",
                ]);
            }
        }
    }

    /**
     * Prevent a test from being assigned to two live specimens at once
     * (spec §45's concurrency requirement, checked under the caller's own
     * row locks).
     *
     * @param  Collection<int, LaboratoryOrderItem>  $items
     *
     * @throws ValidationException
     */
    protected function assertNoActiveSampleConflict(Collection $items): void
    {
        foreach ($items as $item) {
            $hasActiveSample = $item->samples()
                ->whereNotIn('laboratory_samples.status', [LaboratorySample::STATUS_CANCELLED, LaboratorySample::STATUS_REJECTED])
                ->exists();

            if ($hasActiveSample) {
                throw ValidationException::withMessages([
                    'item_ids' => "\"{$item->test_name}\" already has an active specimen registered.",
                ]);
            }
        }
    }
}
