<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrescriptionOrder\CancelPrescriptionOrderItemRequest;
use App\Http\Requests\PrescriptionOrder\CancelPrescriptionOrderRequest;
use App\Http\Requests\PrescriptionOrder\StorePrescriptionOrderRequest;
use App\Http\Requests\PrescriptionOrder\UpdatePrescriptionOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\PrescriptionOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Prescription orders are presented as part of the clinical encounter
 * screen, not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 * Prescription is its own sibling branch off the encounter (Phase 6, Step
 * 46), mirroring EncounterRadiologyOrderController exactly.
 */
class EncounterPrescriptionOrderController extends Controller
{
    public function store(
        StorePrescriptionOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        PrescriptionOrderService $service,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        $this->authorize('create', [PrescriptionOrder::class, $clinicalEncounter]);

        $order = $service->create($clinicalEncounter, $request->validated(), $request);

        $insurance = $clinicalEncounter->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $clinicalEncounter,
                    [],
                    $itemIds,
                    $request,
                    $order->clinical_notes,
                    $clinicalEncounter->practitioner?->nhif_practitioner_no,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}). Please issue the pre-approval request.";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed', [
                    'prescription_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $hospitalId = $clinicalEncounter->department?->hospital_id ?? $request->user()?->staff?->hospital_id;
        $stockNotice = null;

        if ($hospitalId) {
            $deficits = [];
            foreach ($order->items as $item) {
                if ($item->status === PrescriptionOrderItem::STATUS_CANCELLED) {
                    continue;
                }
                $stockItem = StockItem::where('hospital_id', $hospitalId)
                    ->where('medication_id', $item->medication_id)
                    ->first();
                $available = $stockItem ? (int) $stockItem->batches()
                    ->where('status', StockBatch::STATUS_ACTIVE)
                    ->where('expiry_date', '>=', now()->toDateString())
                    ->sum('quantity') : 0;

                $req = (int) $item->quantity;
                if ($available === 0) {
                    $deficits[] = "{$item->medication_name_snapshot} (Out of Stock)";
                } elseif ($req > $available) {
                    $deficits[] = "{$item->medication_name_snapshot} (Req: {$req}, Avail: {$available})";
                }
            }

            if (! empty($deficits)) {
                $stockNotice = 'Note: Pharmacy stock alert for ' . implode('; ', $deficits) . '.';
            }
        }

        $parts = array_filter(['Prescription written.', $preApprovalMessage, $stockNotice]);
        $message = implode(' ', $parts);

        return back()->with('success', $message);
    }

    public function update(UpdatePrescriptionOrderRequest $request, ClinicalEncounter $clinicalEncounter, PrescriptionOrder $prescriptionOrder, PrescriptionOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $prescriptionOrder);

        $this->authorize('update', $prescriptionOrder);

        $service->update($prescriptionOrder, $request->validated(), $request);

        return back()->with('success', 'Prescription updated.');
    }

    public function cancel(CancelPrescriptionOrderRequest $request, ClinicalEncounter $clinicalEncounter, PrescriptionOrder $prescriptionOrder, PrescriptionOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $prescriptionOrder);

        $this->authorize('cancel', $prescriptionOrder);

        $service->cancel($prescriptionOrder, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Prescription cancelled.');
    }

    public function cancelItem(
        CancelPrescriptionOrderItemRequest $request,
        ClinicalEncounter $clinicalEncounter,
        PrescriptionOrder $prescriptionOrder,
        PrescriptionOrderItem $item,
        PrescriptionOrderService $service,
    ): RedirectResponse {
        $this->assertBelongsToEncounter($clinicalEncounter, $prescriptionOrder);
        $this->assertItemBelongsToOrder($prescriptionOrder, $item);

        // Removing a medication modifies the order's own content, so it is
        // authorized against the order's 'update' ability, not a separate
        // item-level permission.
        $this->authorize('update', $prescriptionOrder);

        $service->cancelItem($item, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Medication removed from prescription.');
    }

    /**
     * IDOR guard: an order accessed through
     * /clinical-encounters/{a}/prescription-orders/{order} must actually
     * belong to encounter {a}, not some other encounter whose id a client
     * substituted in the URL.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, PrescriptionOrder $prescriptionOrder): void
    {
        abort_if($prescriptionOrder->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }

    /**
     * IDOR guard: an item accessed through .../prescription-orders/{order}/
     * items/{item} must actually belong to that order.
     */
    protected function assertItemBelongsToOrder(PrescriptionOrder $prescriptionOrder, PrescriptionOrderItem $item): void
    {
        abort_if($item->prescription_order_id !== $prescriptionOrder->id, 404);
    }
}
