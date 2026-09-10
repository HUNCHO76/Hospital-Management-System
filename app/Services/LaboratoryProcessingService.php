<?php

namespace App\Services;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the bench-side processing lifecycle (Phase 4, Step 32): accepting a
 * received specimen into the lab, assigning it to a technician/bench,
 * starting and completing processing, transferring between benches, and
 * rejecting a specimen found unsuitable mid-processing. One
 * LaboratoryProcessing record exists per (sample, item) pair — accept/
 * reject act on the whole specimen (every test it carries), while assign/
 * start/complete/transfer act on a single test's own processing record, so
 * tests sharing one specimen can progress independently (spec §15-16).
 *
 * Depends on LaboratorySampleService (one-directional — processing is
 * downstream of collection/receipt) rather than the reverse, so the two
 * services never form a circular dependency; LaboratorySampleController is
 * the one place that calls both in sequence (receive(), then
 * initializeForSample()).
 */
class LaboratoryProcessingService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected LaboratorySampleService $samples,
    ) {}

    /**
     * Create a processing record for every item this specimen satisfies,
     * the moment it is received (spec §7 — a received specimen enters
     * laboratory processing at the RECEIVED stage automatically; there is
     * nothing further to "start" here — acceptance is its own action).
     * Idempotent: never creates a duplicate row for the same (sample,
     * item) pair.
     */
    public function initializeForSample(LaboratorySample $sample, Request $request): void
    {
        $itemIds = $sample->items()->pluck('laboratory_order_items.id');

        foreach ($itemIds as $itemId) {
            LaboratoryProcessing::firstOrCreate(
                ['laboratory_sample_id' => $sample->id, 'laboratory_order_item_id' => $itemId],
                ['status' => LaboratoryProcessing::STATUS_RECEIVED],
            );
        }
    }

    /**
     * Accept a received specimen into laboratory processing — every test
     * it carries moves from RECEIVED to ACCEPTED together, since accepting
     * (or rejecting) a specimen is a whole-tube decision, not a per-test
     * one (spec §7).
     *
     * @throws ValidationException
     */
    public function acceptSample(LaboratorySample $sample, Request $request): LaboratorySample
    {
        DB::transaction(function () use ($sample, $request) {
            $sample = LaboratorySample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if ($sample->status !== LaboratorySample::STATUS_RECEIVED) {
                throw ValidationException::withMessages([
                    'status' => "Cannot accept a sample with status \"{$sample->status}\".",
                ]);
            }

            $records = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->lockForUpdate()->get();

            if ($records->isEmpty() || $records->contains(fn (LaboratoryProcessing $r) => $r->status !== LaboratoryProcessing::STATUS_RECEIVED)) {
                throw ValidationException::withMessages([
                    'status' => 'This sample has already been accepted or is already being processed.',
                ]);
            }

            foreach ($records as $record) {
                $record->update([
                    'status' => LaboratoryProcessing::STATUS_ACCEPTED,
                    'accepted_by' => $request->user()->id,
                    'accepted_at' => now(),
                ]);
            }
        });

        $this->auditLog->record(
            'laboratory_sample_accepted',
            $sample,
            null,
            ['accepted_by' => $request->user()->id],
            $request,
        );

        return $sample->fresh();
    }

    /**
     * Assign a single test's processing to a technician and (optionally) a
     * bench. Assignment is optional in the overall workflow (spec §12) —
     * reachable from ACCEPTED, and re-assignable while still ASSIGNED
     * (before work starts; once IN_PROGRESS, use transfer() instead).
     *
     * @param  array{assigned_to: int, bench_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function assign(LaboratoryProcessing $processing, array $data, Request $request): LaboratoryProcessing
    {
        $processing = DB::transaction(function () use ($processing, $data, $request) {
            $processing = LaboratoryProcessing::query()->whereKey($processing->id)->lockForUpdate()->firstOrFail();

            if (! in_array($processing->status, [LaboratoryProcessing::STATUS_ACCEPTED, LaboratoryProcessing::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot assign processing with status \"{$processing->status}\".",
                ]);
            }

            $processing->update([
                'status' => LaboratoryProcessing::STATUS_ASSIGNED,
                'bench_id' => $data['bench_id'] ?? $processing->bench_id,
                'assigned_to' => $data['assigned_to'],
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
            ]);

            return $processing;
        });

        $this->auditLog->record(
            'laboratory_sample_assigned',
            $processing,
            null,
            $processing->only(['status', 'bench_id', 'assigned_to']),
            $request,
        );

        return $processing;
    }

    /**
     * Start processing a single test. Reachable directly from ACCEPTED
     * (assignment is optional, spec §12) or from ASSIGNED.
     *
     * @param  array{processing_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function start(LaboratoryProcessing $processing, array $data, Request $request): LaboratoryProcessing
    {
        $processing = DB::transaction(function () use ($processing, $data, $request) {
            $processing = LaboratoryProcessing::query()->whereKey($processing->id)->lockForUpdate()->firstOrFail();

            if (! $processing->canTransitionTo(LaboratoryProcessing::STATUS_IN_PROGRESS)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot start processing with status \"{$processing->status}\".",
                ]);
            }

            $processing->update([
                'status' => LaboratoryProcessing::STATUS_IN_PROGRESS,
                'started_by' => $request->user()->id,
                'started_at' => now(),
                'processing_notes' => $data['processing_notes'] ?? $processing->processing_notes,
            ]);

            $this->recomputeItemStatus($processing->item, $request);

            return $processing;
        });

        $this->auditLog->record(
            'laboratory_sample_processing_started',
            $processing,
            null,
            $processing->only(['status', 'started_by', 'started_at']),
            $request,
        );

        return $processing;
    }

    /**
     * Complete processing of a single test. Immediately advances from
     * PROCESSING_COMPLETE to RESULT_PENDING within the same call (see
     * LaboratoryProcessing's own class docblock) — this step's boundary;
     * result entry is a future step's job.
     *
     * @param  array{processing_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function complete(LaboratoryProcessing $processing, array $data, Request $request): LaboratoryProcessing
    {
        $processing = DB::transaction(function () use ($processing, $data, $request) {
            $processing = LaboratoryProcessing::query()->whereKey($processing->id)->lockForUpdate()->firstOrFail();

            if (! $processing->canTransitionTo(LaboratoryProcessing::STATUS_PROCESSING_COMPLETE)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot complete processing with status \"{$processing->status}\".",
                ]);
            }

            $processing->update([
                'status' => LaboratoryProcessing::STATUS_PROCESSING_COMPLETE,
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
                'processing_notes' => $data['processing_notes'] ?? $processing->processing_notes,
            ]);

            $processing->update(['status' => LaboratoryProcessing::STATUS_RESULT_PENDING]);

            $this->recomputeItemStatus($processing->item, $request);

            return $processing;
        });

        $this->auditLog->record(
            'laboratory_sample_processing_completed',
            $processing,
            null,
            $processing->only(['completed_by', 'completed_at']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_processing_status_changed',
            $processing,
            ['status' => LaboratoryProcessing::STATUS_PROCESSING_COMPLETE],
            ['status' => LaboratoryProcessing::STATUS_RESULT_PENDING],
            $request,
        );

        return $processing;
    }

    /**
     * Transfer a single test's processing to a different bench (spec §19).
     * Only ever changes `bench_id` — status stays whatever it was
     * (ASSIGNED or IN_PROGRESS); the move itself is what gets audited.
     *
     * @param  array{bench_id: int, transfer_reason: string}  $data
     *
     * @throws ValidationException
     */
    public function transfer(LaboratoryProcessing $processing, array $data, Request $request): LaboratoryProcessing
    {
        $fromBenchId = null;

        $processing = DB::transaction(function () use ($processing, $data, &$fromBenchId) {
            $processing = LaboratoryProcessing::query()->whereKey($processing->id)->lockForUpdate()->firstOrFail();

            if (! in_array($processing->status, [LaboratoryProcessing::STATUS_ASSIGNED, LaboratoryProcessing::STATUS_IN_PROGRESS], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transfer processing with status \"{$processing->status}\".",
                ]);
            }

            $fromBenchId = $processing->bench_id;

            $processing->update(['bench_id' => $data['bench_id']]);

            return $processing;
        });

        $this->auditLog->record(
            'laboratory_sample_transferred',
            $processing,
            ['bench_id' => $fromBenchId],
            ['bench_id' => $processing->bench_id, 'transfer_reason' => $data['transfer_reason']],
            $request,
        );

        return $processing;
    }

    /**
     * Reject a specimen found unsuitable during processing itself (spec
     * §18) — reuses LaboratorySampleService::reject() rather than a second
     * rejection concept, then cascades every still-open processing record
     * for this specimen to REJECTED so the processing history/worklist
     * accurately reflects it.
     *
     * Blocked outright once any one of the specimen's tests has already
     * reached RESULT_PENDING: rejecting the whole specimen at that point
     * would silently orphan a result that is already being entered against
     * it, and there is no sample-level status to represent "rejected for
     * some tests, resulted for others." Every processing record is locked
     * up front (before LaboratorySampleService::reject() takes its own lock
     * on the sample) so a concurrent completeProcessing() on another of
     * this specimen's tests can never race past this check.
     *
     * @param  array{rejection_reason: string, rejection_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function rejectSample(LaboratorySample $sample, array $data, Request $request): LaboratorySample
    {
        return DB::transaction(function () use ($sample, $data, $request) {
            $records = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->lockForUpdate()->get();

            if ($records->contains(fn (LaboratoryProcessing $r) => $r->status === LaboratoryProcessing::STATUS_RESULT_PENDING)) {
                throw ValidationException::withMessages([
                    'status' => 'This specimen cannot be rejected — at least one of its tests has already reached result entry.',
                ]);
            }

            $sample = $this->samples->reject($sample, $data, $request);

            $records
                ->reject(fn (LaboratoryProcessing $r) => in_array($r->status, [LaboratoryProcessing::STATUS_REJECTED, LaboratoryProcessing::STATUS_RESULT_PENDING], true))
                ->each(fn (LaboratoryProcessing $record) => $record->update(['status' => LaboratoryProcessing::STATUS_REJECTED]));

            return $sample;
        });
    }

    /**
     * Roll the item's own status up from whichever processing record is
     * currently active for it, then roll the parent order up in turn.
     * Deliberately bypasses LaboratoryOrderItem::canTransitionTo() — this
     * is a derived read-model driven by the processing record, not a
     * user-facing transition.
     */
    protected function recomputeItemStatus(LaboratoryOrderItem $item, Request $request): void
    {
        if ($item->status === LaboratoryOrderItem::STATUS_CANCELLED) {
            return;
        }

        $active = $item->processingRecords()->reorder('laboratory_processing.id', 'desc')->first();

        $newStatus = match (true) {
            $active === null => $item->status,
            $active->status === LaboratoryProcessing::STATUS_IN_PROGRESS => LaboratoryOrderItem::STATUS_IN_PROGRESS,
            in_array($active->status, [LaboratoryProcessing::STATUS_PROCESSING_COMPLETE, LaboratoryProcessing::STATUS_RESULT_PENDING], true) => LaboratoryOrderItem::STATUS_RESULT_PENDING,
            default => LaboratoryOrderItem::STATUS_RECEIVED,
        };

        if ($newStatus !== $item->status) {
            $item->update(['status' => $newStatus]);
        }

        $this->recomputeOrderStatus($item->laboratoryOrder, $request);
    }

    /**
     * Recompute the order's own status from the aggregate progress of its
     * non-cancelled items, sharing the exact same rule
     * LaboratorySampleService uses (LaboratoryOrder::statusFromItemProgress())
     * so the two phases never drift into inconsistent rollup logic.
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
}
