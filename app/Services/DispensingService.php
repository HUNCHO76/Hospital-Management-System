<?php

namespace App\Services;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the pharmacy dispensing workflow (Phase 6, Step 48) — the actual
 * fulfillment of an already-VERIFIED PrescriptionOrder, entirely separate
 * from prescribing (Step 46) and verification (Step 47). Mirrors
 * PrescriptionVerificationService's shape (transaction + row-lock +
 * re-checked transition + audited event). Only one IN_PROGRESS Dispensing
 * may exist per order at a time — start() enforces this under the order's
 * own row lock, which is what guarantees the total dispensed quantity can
 * never exceed what was prescribed (spec §19) without needing separate
 * per-item locking across concurrent requests.
 *
 * Stock integration (Phase 6, Step 49, spec §14/§28): complete() calls
 * StockService::allocateFefo() for every positively-dispensed item, inside
 * this method's own outer transaction — a nested savepoint, not a separate
 * commit. If stock is insufficient, allocateFefo() throws and the entire
 * transaction (including the DispensingItem rows already created this call)
 * rolls back, so a dispensing event can never complete without a matching,
 * atomic stock deduction.
 *
 * Billing integration (Phase 7, Step 54, spec §12/§17): complete() also
 * calls ChargeCaptureService::captureFromDispensingItem() for every
 * positively-dispensed item, inside this same transaction — one
 * DispensingItem row is always exactly one fulfillment event (never a
 * running total), so charging per DispensingItem id is the correct
 * granularity for partial dispensing. A missing Medication price never
 * blocks or rolls back the dispensing itself — see ChargeCaptureService's
 * own docblock; it is skipped and audited, not thrown.
 */
class DispensingService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected StockService $stockService,
        protected ChargeCaptureService $chargeCapture,
        protected FinancialAuthorizationService $financialAuth,
        protected NHIFPreauthorizationService $nhifPreauth,
    ) {}

    /**
     * Open a new dispensing event for a verified prescription.
     *
     * @throws ValidationException
     */
    public function start(PrescriptionOrder $order, Request $request): Dispensing
    {
        $dispensing = DB::transaction(function () use ($order, $request) {
            // Locking the order row serializes concurrent start() attempts
            // the same way PrescriptionOrderService/RadiologyOrderService
            // lock their own parent row for create-time invariants — this
            // is the mechanism that prevents two pharmacists from both
            // opening a dispensing session for the same remaining quantity.
            $order = PrescriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertEligible($order);

            $activeItemIds = $order->items()
                ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
                ->pluck('id')
                ->all();

            // Step 69: NHIF preauthorization gate
            $this->nhifPreauth->assertPreauthorizedForSources(PrescriptionOrderItem::class, $activeItemIds);

            // Step 62: financial authorization gate — all prescription order items
            // must have their charges paid before dispensing may begin.
            $this->financialAuth->assertAuthorizedForSources(PrescriptionOrderItem::class, $activeItemIds);

            if ($order->dispensings()->where('status', Dispensing::STATUS_IN_PROGRESS)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'A dispensing event is already in progress for this prescription.',
                ]);
            }

            return Dispensing::create([
                'prescription_order_id' => $order->id,
                'status' => Dispensing::STATUS_IN_PROGRESS,
                'dispensed_by' => $request->user()->id,
                'started_at' => now(),
            ]);
        });

        $this->auditLog->record(
            'dispensing_started',
            $dispensing,
            null,
            $dispensing->only(['prescription_order_id', 'status', 'dispensed_by']),
            $request,
        );

        return $dispensing;
    }

    /**
     * Complete an in-progress dispensing event, recording what was actually
     * handed to the patient (or explicitly marked unavailable) for each
     * medication. Never trusts a client-supplied quantity or item id
     * without revalidating both against the live, freshly-locked state.
     *
     * @param  array{items: list<array{prescription_order_item_id: int, dispensed_quantity: int, status?: string, notes?: string|null}>, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function complete(Dispensing $dispensing, array $data, Request $request): Dispensing
    {
        $stockTransactions = [];

        $dispensing = DB::transaction(function () use ($dispensing, $data, $request, &$stockTransactions) {
            $dispensing = Dispensing::query()->whereKey($dispensing->id)->lockForUpdate()->firstOrFail();

            if (! $dispensing->canTransitionTo(Dispensing::STATUS_COMPLETED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot complete a dispensing event with status \"{$dispensing->status}\".",
                ]);
            }

            $order = PrescriptionOrder::query()->whereKey($dispensing->prescription_order_id)->lockForUpdate()->firstOrFail();
            $this->assertEligible($order);

            $hospitalId = $order->clinicalEncounter->department->hospital_id;

            $items = $this->validatedItems($order, $data['items']);

            foreach ($items as $itemData) {
                $dispensingItem = DispensingItem::create([
                    'dispensing_id' => $dispensing->id,
                    'prescription_order_item_id' => $itemData['prescription_order_item_id'],
                    'dispensed_quantity' => $itemData['dispensed_quantity'],
                    'status' => $itemData['status'],
                    'notes' => $itemData['notes'],
                ]);

                if ($itemData['dispensed_quantity'] > 0 && $itemData['status'] === DispensingItem::STATUS_DISPENSED) {
                    $stockTransactions = [
                        ...$stockTransactions,
                        ...$this->stockService->allocateFefo(
                            $hospitalId,
                            $itemData['medication_id'],
                            $itemData['dispensed_quantity'],
                            'dispensing_item',
                            $dispensingItem->id,
                            $request,
                        ),
                    ];

                    // Step 62: charge was already captured at prescription ORDER time
                    // via PrescriptionOrderService::addItem() → captureFromPrescriptionOrderItem().
                    // No second capture here — the financial gate at start() already
                    // verified payment against that charge.
                }
            }

            $dispensing->update([
                'status' => Dispensing::STATUS_COMPLETED,
                'completed_at' => now(),
                'notes' => $this->validateText($data['notes'] ?? null, 'notes', 1000),
            ]);

            return $dispensing;
        });

        $dispensing->load('items');

        foreach ($dispensing->items as $item) {
            $this->auditLog->record(
                'dispensing_item_added',
                $item,
                null,
                $item->only(['dispensing_id', 'prescription_order_item_id', 'dispensed_quantity', 'status']),
                $request,
            );
        }

        foreach ($stockTransactions as $stockTransaction) {
            $this->auditLog->record(
                'stock_dispensed',
                $stockTransaction,
                null,
                $stockTransaction->only(['stock_item_id', 'stock_batch_id', 'quantity', 'balance_after', 'reference_type', 'reference_id']),
                $request,
            );
        }

        $order = $dispensing->prescriptionOrder;
        $fulfillmentAction = $this->fulfillmentStatus($order) === 'dispensed' ? 'dispensing_completed' : 'dispensing_partially_completed';

        $this->auditLog->record(
            $fulfillmentAction,
            $dispensing,
            ['status' => Dispensing::STATUS_IN_PROGRESS],
            $dispensing->only(['status', 'completed_at']),
            $request,
        );

        return $dispensing;
    }

    /**
     * Cancel an in-progress dispensing event (e.g. the patient did not
     * arrive, or nothing could be dispensed) — never deletes; the event
     * remains historically visible with its reason.
     *
     * @throws ValidationException
     */
    public function cancel(Dispensing $dispensing, string $reason, Request $request): Dispensing
    {
        $dispensing = DB::transaction(function () use ($dispensing, $reason, $request) {
            $dispensing = Dispensing::query()->whereKey($dispensing->id)->lockForUpdate()->firstOrFail();

            if (! $dispensing->canTransitionTo(Dispensing::STATUS_CANCELLED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot cancel a dispensing event with status \"{$dispensing->status}\".",
                ]);
            }

            $dispensing->update([
                'status' => Dispensing::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            return $dispensing;
        });

        $this->auditLog->record(
            'dispensing_cancelled',
            $dispensing,
            ['status' => Dispensing::STATUS_IN_PROGRESS],
            $dispensing->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $dispensing;
    }

    /**
     * The quantity of this prescription item already dispensed across every
     * non-cancelled Dispensing event — always a fresh query, never a cached
     * or stored total, so it is safe to call from inside a locked
     * transaction.
     */
    public function dispensedQuantity(PrescriptionOrderItem $item): int
    {
        return (int) DispensingItem::query()
            ->where('prescription_order_item_id', $item->id)
            ->whereHas('dispensing', fn ($query) => $query->where('status', '!=', Dispensing::STATUS_CANCELLED))
            ->sum('dispensed_quantity');
    }

    /**
     * The quantity of this prescription item that may still be dispensed.
     * Never negative — dispensed_quantity can never exceed the prescribed
     * quantity because every write path validates against this same value
     * inside a locked transaction (spec §9).
     */
    public function remainingQuantity(PrescriptionOrderItem $item): int
    {
        return max(0, $item->quantity - $this->dispensedQuantity($item));
    }

    /**
     * The prescription's overall fulfillment state, derived purely from its
     * items' dispensed/remaining quantities — deliberately never stored on
     * PrescriptionOrder itself (spec §24: fulfillment status must stay
     * separate from both the order's own clinical status and the
     * verification status).
     *
     * @return 'pending'|'partially_dispensed'|'dispensed'
     */
    public function fulfillmentStatus(PrescriptionOrder $order): string
    {
        $activeItems = $order->items()->where('status', PrescriptionOrderItem::STATUS_ACTIVE)->get();

        if ($activeItems->isEmpty()) {
            return 'pending';
        }

        $anyDispensed = false;
        $allFulfilled = true;

        foreach ($activeItems as $item) {
            $dispensed = $this->dispensedQuantity($item);

            if ($dispensed > 0) {
                $anyDispensed = true;
            }

            if ($dispensed < $item->quantity) {
                $allFulfilled = false;
            }
        }

        if ($allFulfilled) {
            return 'dispensed';
        }

        return $anyDispensed ? 'partially_dispensed' : 'pending';
    }

    /**
     * Validate the submitted item payload against the live, locked order:
     * every item must belong to this order, still be active, and request a
     * quantity within [0, remaining]. Never trusts the client's own
     * prescription_order_item_id/quantity pairing without this.
     *
     * @param  list<array{prescription_order_item_id: int, dispensed_quantity: int, status?: string, notes?: string|null}>  $items
     * @return list<array{prescription_order_item_id: int, medication_id: int, dispensed_quantity: int, status: string, notes: string|null, order_item: PrescriptionOrderItem}>
     *
     * @throws ValidationException
     */
    protected function validatedItems(PrescriptionOrder $order, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one medication must be recorded before completing dispensing.',
            ]);
        }

        $orderItemIds = $order->items()->where('status', PrescriptionOrderItem::STATUS_ACTIVE)->pluck('id', 'id');
        $validated = [];

        foreach ($items as $itemData) {
            $orderItemId = (int) $itemData['prescription_order_item_id'];

            if (! isset($orderItemIds[$orderItemId])) {
                throw ValidationException::withMessages([
                    'items' => 'One or more medications do not belong to this prescription.',
                ]);
            }

            $quantity = (int) $itemData['dispensed_quantity'];

            if ($quantity < 0) {
                throw ValidationException::withMessages([
                    'items' => 'Dispensed quantity cannot be negative.',
                ]);
            }

            $orderItem = PrescriptionOrderItem::query()->whereKey($orderItemId)->lockForUpdate()->firstOrFail();
            $remaining = $this->remainingQuantity($orderItem);

            if ($quantity > $remaining) {
                throw ValidationException::withMessages([
                    'items' => "Cannot dispense {$quantity} of \"{$orderItem->medication_name_snapshot}\" — only {$remaining} remain.",
                ]);
            }

            $status = $itemData['status'] ?? ($quantity > 0 ? DispensingItem::STATUS_DISPENSED : DispensingItem::STATUS_UNAVAILABLE);

            if (! in_array($status, DispensingItem::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'items' => 'Invalid dispensing item status.',
                ]);
            }

            if ($quantity === 0 && $status === DispensingItem::STATUS_DISPENSED) {
                // A zero-quantity row with nothing to say is simply
                // untouched this event — skip it rather than recording a
                // meaningless DispensingItem row.
                continue;
            }

            $validated[] = [
                'prescription_order_item_id' => $orderItemId,
                'medication_id' => $orderItem->medication_id,
                'dispensed_quantity' => $quantity,
                'status' => $status,
                'notes' => $this->validateText($itemData['notes'] ?? null, 'items', 500),
                'order_item' => $orderItem,
            ];
        }

        if ($validated === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one medication must be recorded before completing dispensing.',
            ]);
        }

        return $validated;
    }

    /**
     * @throws ValidationException
     */
    public function assertEligible(PrescriptionOrder $order): void
    {
        if ($order->status !== PrescriptionOrder::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => "Cannot dispense a prescription order with status \"{$order->status}\".",
            ]);
        }

        $verification = $order->verification;

        if (! $verification || $verification->status !== PrescriptionVerification::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'status' => 'This prescription has not been verified and cannot be dispensed.',
            ]);
        }

        if ($this->fulfillmentStatus($order) === 'dispensed') {
            throw ValidationException::withMessages([
                'status' => 'This prescription has already been fully dispensed.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validateText(?string $value, string $field, int $maxLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([
                $field => ucfirst($field)." may not exceed {$maxLength} characters.",
            ]);
        }

        return $value;
    }
}
