<?php

namespace App\Services;

use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the pharmacy stock ledger (Phase 6, Step 49): receiving, corrective
 * adjustments (including expired/damaged write-offs), quarantine/release, and
 * FEFO allocation for dispensing. Every quantity-changing action here runs
 * inside a locked DB transaction and writes an append-only StockTransaction
 * row — `stock_batches.quantity` is a maintained running total, but the
 * ledger is the source of truth (spec §10-11/§47).
 *
 * `allocateFefo()` is the sole integration point with dispensing (spec §14):
 * it is always called from *inside* DispensingService::complete()'s own
 * outer transaction (as a nested savepoint), never audits itself, and simply
 * returns what it created — the caller owns auditing once its own top-level
 * transaction actually commits, mirroring how DispensingService itself
 * already audits its DispensingItem rows only after commit.
 */
class StockService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Receive stock into a hospital's pharmacy inventory — creates the
     * StockItem on first use (spec §5: identity/config only) and either
     * creates a new StockBatch or tops up an existing one matched by
     * (stock_item_id, batch_number), never silently duplicating a batch
     * (spec §13). The manufacturer's own batch_number is preserved verbatim
     * (spec §7) and never replaced.
     *
     * @param  array{medication_id: int, batch_number: string, manufacture_date?: string|null, expiry_date: string, quantity: int, unit_cost?: float|string|null, supplier_name?: string|null, notes?: string|null, reorder_level?: int|null, maximum_level?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function receive(int $hospitalId, array $data, Request $request): StockBatch
    {
        [$item, $batch, $transaction, $itemWasNew, $batchWasNew] = DB::transaction(function () use ($hospitalId, $data, $request) {
            // Locking the stock item row serializes concurrent receives into
            // the same medication/hospital, which is what prevents two
            // simultaneous receipts from both creating a duplicate batch row
            // for a brand-new batch_number (spec §13/§27).
            $item = StockItem::query()
                ->where('hospital_id', $hospitalId)
                ->where('medication_id', $data['medication_id'])
                ->lockForUpdate()
                ->first();

            $itemWasNew = ! $item;

            if (! $item) {
                $item = StockItem::create([
                    'hospital_id' => $hospitalId,
                    'medication_id' => $data['medication_id'],
                    'reorder_level' => $data['reorder_level'] ?? 0,
                    'maximum_level' => $data['maximum_level'] ?? null,
                    'status' => StockItem::STATUS_ACTIVE,
                    'created_by' => $request->user()->id,
                ]);
            } elseif (array_key_exists('reorder_level', $data) || array_key_exists('maximum_level', $data)) {
                $item->update([
                    'reorder_level' => $data['reorder_level'] ?? $item->reorder_level,
                    'maximum_level' => array_key_exists('maximum_level', $data) ? $data['maximum_level'] : $item->maximum_level,
                    'updated_by' => $request->user()->id,
                ]);
            }

            $batch = StockBatch::query()
                ->where('stock_item_id', $item->id)
                ->where('batch_number', $data['batch_number'])
                ->lockForUpdate()
                ->first();

            $batchWasNew = ! $batch;

            if ($batch) {
                if ($batch->expiry_date->toDateString() !== $data['expiry_date']) {
                    throw ValidationException::withMessages([
                        'expiry_date' => "Batch \"{$data['batch_number']}\" already exists for this medication with a different expiry date ({$batch->expiry_date->toDateString()}). Use a different batch number or verify the expiry date.",
                    ]);
                }

                if ($batch->status !== StockBatch::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'batch_number' => "Batch \"{$data['batch_number']}\" is currently \"{$batch->status}\" and cannot receive additional stock. Use a different batch number.",
                    ]);
                }
            } else {
                $batch = StockBatch::create([
                    'stock_item_id' => $item->id,
                    'batch_number' => $data['batch_number'],
                    'manufacture_date' => $data['manufacture_date'] ?? null,
                    'expiry_date' => $data['expiry_date'],
                    'quantity' => 0,
                    'unit_cost' => $data['unit_cost'] ?? null,
                    'supplier_name' => $data['supplier_name'] ?? null,
                    'status' => StockBatch::STATUS_ACTIVE,
                    'created_by' => $request->user()->id,
                ]);
            }

            $before = $batch->quantity;
            $after = $before + $data['quantity'];

            $batch->update(['quantity' => $after]);

            $transaction = StockTransaction::create([
                'stock_item_id' => $item->id,
                'stock_batch_id' => $batch->id,
                'transaction_type' => StockTransaction::TYPE_RECEIPT,
                'quantity' => $data['quantity'],
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $data['notes'] ?? null,
                'performed_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return [$item, $batch, $transaction, $itemWasNew, $batchWasNew];
        });

        if ($itemWasNew) {
            $this->auditLog->record('stock_item_created', $item, null, $item->only(['hospital_id', 'medication_id', 'reorder_level', 'maximum_level']), $request);
        }

        if ($batchWasNew) {
            $this->auditLog->record('stock_batch_created', $batch, null, $batch->only(['stock_item_id', 'batch_number', 'expiry_date', 'status']), $request);
        }

        $this->auditLog->record('stock_received', $transaction, null, $transaction->only(['stock_item_id', 'stock_batch_id', 'quantity', 'balance_after']), $request);

        return $batch->fresh();
    }

    /**
     * Correct a batch's physical quantity — a positive count correction, a
     * negative count correction, or a write-off for expired/damaged stock
     * (spec §19-20). All four share one reason-required, ledgered code path;
     * EXPIRED/DAMAGED are distinguished only by their resulting
     * StockTransaction type (and, when the write-off empties the batch, the
     * batch's own resulting status) — spec never lists a separate permission
     * for them beyond 'pharmacy-stock.adjust'.
     *
     * $quantity is always the positive magnitude being added or removed;
     * this method derives the signed delta from $type.
     *
     * @throws ValidationException
     */
    public function adjust(StockBatch $batch, string $type, int $quantity, string $reason, Request $request): StockBatch
    {
        if (! in_array($type, [StockTransaction::TYPE_ADJUSTMENT_IN, StockTransaction::TYPE_ADJUSTMENT_OUT, StockTransaction::TYPE_EXPIRED, StockTransaction::TYPE_DAMAGED], true)) {
            throw ValidationException::withMessages(['type' => 'Invalid stock adjustment type.']);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Adjustment quantity must be at least 1.']);
        }

        $batch = DB::transaction(function () use ($batch, $type, $quantity, $reason, $request) {
            $locked = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [StockBatch::STATUS_EXPIRED, StockBatch::STATUS_DAMAGED], true)) {
                throw ValidationException::withMessages([
                    'status' => "This batch has already been written off as \"{$locked->status}\" and can no longer be adjusted.",
                ]);
            }

            $delta = $type === StockTransaction::TYPE_ADJUSTMENT_IN ? $quantity : -$quantity;
            $before = $locked->quantity;
            $after = $before + $delta;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Cannot remove {$quantity} — only {$before} available in this batch.",
                ]);
            }

            $newStatus = match (true) {
                $after === 0 && $type === StockTransaction::TYPE_EXPIRED => StockBatch::STATUS_EXPIRED,
                $after === 0 && $type === StockTransaction::TYPE_DAMAGED => StockBatch::STATUS_DAMAGED,
                $after === 0 => StockBatch::STATUS_DEPLETED,
                $locked->status === StockBatch::STATUS_DEPLETED => StockBatch::STATUS_ACTIVE,
                default => $locked->status,
            };

            $locked->update(['quantity' => $after, 'status' => $newStatus]);

            StockTransaction::create([
                'stock_item_id' => $locked->stock_item_id,
                'stock_batch_id' => $locked->id,
                'transaction_type' => $type,
                'quantity' => $delta,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $reason,
                'performed_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $locked;
        });

        $this->auditLog->record('stock_adjusted', $batch, null, $batch->only(['id', 'quantity', 'status']) + ['type' => $type, 'reason' => $reason], $request);

        return $batch->fresh();
    }

    /**
     * Hold a batch out of dispensable inventory pending investigation —
     * reversible via release(). Never deletes or changes quantity.
     *
     * @throws ValidationException
     */
    public function quarantine(StockBatch $batch, string $reason, Request $request): StockBatch
    {
        $batch = DB::transaction(function () use ($batch, $reason, $request) {
            $locked = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockBatch::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => "Only an active batch can be quarantined (current status: \"{$locked->status}\").",
                ]);
            }

            $locked->update(['status' => StockBatch::STATUS_QUARANTINED]);

            StockTransaction::create([
                'stock_item_id' => $locked->stock_item_id,
                'stock_batch_id' => $locked->id,
                'transaction_type' => StockTransaction::TYPE_QUARANTINE,
                'quantity' => 0,
                'balance_before' => $locked->quantity,
                'balance_after' => $locked->quantity,
                'reason' => $reason,
                'performed_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $locked;
        });

        $this->auditLog->record('stock_quarantined', $batch, ['status' => StockBatch::STATUS_ACTIVE], $batch->only(['id', 'status']) + ['reason' => $reason], $request);

        return $batch->fresh();
    }

    /**
     * Release a quarantined batch back into active, dispensable inventory.
     * Refuses to release a batch whose expiry has passed while it sat in
     * quarantine — that stock must go through adjust()'s EXPIRED write-off
     * instead, never silently become dispensable again (spec §40).
     *
     * @throws ValidationException
     */
    public function release(StockBatch $batch, ?string $note, Request $request): StockBatch
    {
        $batch = DB::transaction(function () use ($batch, $note, $request) {
            $locked = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockBatch::STATUS_QUARANTINED) {
                throw ValidationException::withMessages([
                    'status' => "Only a quarantined batch can be released (current status: \"{$locked->status}\").",
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages([
                    'status' => 'This batch has expired while quarantined and cannot be released back into active stock.',
                ]);
            }

            $locked->update(['status' => StockBatch::STATUS_ACTIVE]);

            StockTransaction::create([
                'stock_item_id' => $locked->stock_item_id,
                'stock_batch_id' => $locked->id,
                'transaction_type' => StockTransaction::TYPE_RELEASE,
                'quantity' => 0,
                'balance_before' => $locked->quantity,
                'balance_after' => $locked->quantity,
                'reason' => $note,
                'performed_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $locked;
        });

        $this->auditLog->record('stock_released', $batch, ['status' => StockBatch::STATUS_QUARANTINED], $batch->only(['id', 'status']), $request);

        return $batch->fresh();
    }

    /**
     * Allocate `$quantity` units of a medication out of a hospital's
     * dispensable inventory using FEFO (First Expired, First Out, spec §15):
     * candidate batches (ACTIVE, not expired, quantity > 0) are locked and
     * consumed in expiry-date order, splitting across as many batches as
     * necessary (spec §37, multi-batch dispensing) — never expired or
     * quarantined stock, regardless of what the caller asks for (spec §16,
     * enforced here in the query itself, not left to the UI).
     *
     * Always called from inside another service's own outer transaction
     * (DispensingService::complete()) — this method never audits; the caller
     * audits the returned rows once its own transaction actually commits
     * (spec §28's atomicity: if this throws, the caller's whole transaction
     * rolls back, so dispensing can never complete without a matching
     * deduction).
     *
     * @return list<StockTransaction>
     *
     * @throws ValidationException
     */
    public function allocateFefo(int $hospitalId, int $medicationId, int $quantity, string $referenceType, int $referenceId, Request $request): array
    {
        return DB::transaction(function () use ($hospitalId, $medicationId, $quantity, $referenceType, $referenceId, $request) {
            $item = StockItem::query()
                ->where('hospital_id', $hospitalId)
                ->where('medication_id', $medicationId)
                ->lockForUpdate()
                ->first();

            $batches = $item
                ? StockBatch::query()
                    ->where('stock_item_id', $item->id)
                    ->where('status', StockBatch::STATUS_ACTIVE)
                    ->where('expiry_date', '>=', now()->toDateString())
                    ->where('quantity', '>', 0)
                    ->orderBy('expiry_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            $available = (int) $batches->sum('quantity');

            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient available stock: {$available} available, {$quantity} requested.",
                ]);
            }

            $remaining = $quantity;
            $transactions = [];

            foreach ($batches as $stockBatch) {
                if ($remaining <= 0) {
                    break;
                }

                $allocated = min($stockBatch->quantity, $remaining);
                $before = $stockBatch->quantity;
                $after = $before - $allocated;

                $stockBatch->update([
                    'quantity' => $after,
                    'status' => $after === 0 ? StockBatch::STATUS_DEPLETED : $stockBatch->status,
                ]);

                $transactions[] = StockTransaction::create([
                    'stock_item_id' => $item->id,
                    'stock_batch_id' => $stockBatch->id,
                    'transaction_type' => StockTransaction::TYPE_DISPENSE,
                    'quantity' => -$allocated,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'performed_by' => $request->user()->id,
                    'occurred_at' => now(),
                ]);

                $remaining -= $allocated;
            }

            return $transactions;
        });
    }

    /**
     * The quantity of this stock item currently dispensable — active,
     * unexpired batches only. Always computed live (spec §5's "do not store
     * batch-specific data on the stock item").
     */
    public function availableQuantity(StockItem $item): int
    {
        return (int) $item->batches()
            ->where('status', StockBatch::STATUS_ACTIVE)
            ->where('expiry_date', '>=', now()->toDateString())
            ->sum('quantity');
    }

    /**
     * The total physical quantity on record for this stock item across every
     * batch status (quarantined stock is still physically present).
     */
    public function totalQuantity(StockItem $item): int
    {
        return (int) $item->batches()->sum('quantity');
    }

    /**
     * Low-stock detection (spec §17): available stock at or below the
     * configured reorder level.
     */
    public function isLowStock(StockItem $item): bool
    {
        return $this->availableQuantity($item) <= $item->reorder_level;
    }

    /**
     * Expiring-soon detection (spec §8) against the configurable warning
     * window — never hard-coded.
     */
    public function isExpiringSoon(StockBatch $batch): bool
    {
        return ! $batch->isExpired()
            && $batch->expiry_date->lte(now()->addDays((int) config('pharmacy.stock_expiry_warning_days')));
    }

    /**
     * Reconcile a batch's current quantity against its own ledger (spec
     * §47): opening balance (0) plus every recorded transaction's signed
     * quantity must equal the batch's stored quantity exactly. This never
     * *corrects* a mismatch — by construction, every StockService write path
     * updates both the batch row and its ledger row atomically in the same
     * DB transaction, so a mismatch here can only mean something outside
     * this service wrote to `stock_batches.quantity` directly, which is
     * exactly what this method exists to catch and flag for investigation,
     * never silently paper over.
     *
     * @return array{ledger_balance: int, recorded_quantity: int, reconciled: bool}
     */
    public function reconcile(StockBatch $batch): array
    {
        $ledgerBalance = (int) $batch->transactions()->sum('quantity');

        return [
            'ledger_balance' => $ledgerBalance,
            'recorded_quantity' => $batch->quantity,
            'reconciled' => $ledgerBalance === $batch->quantity,
        ];
    }
}
