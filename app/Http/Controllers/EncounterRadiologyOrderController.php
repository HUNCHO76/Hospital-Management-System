<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiologyOrder\CancelRadiologyOrderItemRequest;
use App\Http\Requests\RadiologyOrder\CancelRadiologyOrderRequest;
use App\Http\Requests\RadiologyOrder\StoreRadiologyOrderRequest;
use App\Http\Requests\RadiologyOrder\UpdateRadiologyOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\NHIF\NhifEncounterPreApprovalService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Radiology orders are presented as part of the clinical encounter screen,
 * not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 * Radiology is its own sibling branch off the encounter (Phase 5, Step 36),
 * mirroring EncounterLaboratoryOrderController exactly.
 */
class EncounterRadiologyOrderController extends Controller
{
    public function store(
        StoreRadiologyOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        RadiologyOrderService $service,
        NhifEncounterPreApprovalService $nhifPreApproval,
    ): RedirectResponse {
        $this->authorize('create', [RadiologyOrder::class, $clinicalEncounter]);

        $order = $service->create($clinicalEncounter, $request->validated(), $request);

        $insurance = $clinicalEncounter->patient?->currentNhifInsurance();
        $autoPreApproval = $request->boolean('send_preapproval', true);
        $preApprovalMessage = null;

        if ($insurance && $autoPreApproval) {
            try {
                $itemIds = $order->items->where('status', '!=', RadiologyOrderItem::STATUS_CANCELLED)->pluck('id')->all();
                $session = $nhifPreApproval->createAndSubmitFromSelection(
                    $insurance,
                    $clinicalEncounter,
                    [],
                    [],
                    $request,
                    $order->clinical_indication ?? $order->notes,
                    $clinicalEncounter->practitioner?->nhif_practitioner_no,
                    $itemIds,
                );
                $preApprovalMessage = "NHIF Pre-Approval request submitted (#{$session->id}). Please issue the pre-approval request.";
            } catch (\Throwable $e) {
                Log::warning('Automatic NHIF pre-approval submission failed for radiology', [
                    'radiology_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $preApprovalMessage ? "Radiology order placed. {$preApprovalMessage}" : 'Radiology order placed.';

        return back()->with('success', $message);
    }

    public function update(UpdateRadiologyOrderRequest $request, ClinicalEncounter $clinicalEncounter, RadiologyOrder $radiologyOrder, RadiologyOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $radiologyOrder);

        $this->authorize('update', $radiologyOrder);

        $service->update($radiologyOrder, $request->validated(), $request);

        return back()->with('success', 'Radiology order updated.');
    }

    public function cancel(
        CancelRadiologyOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        RadiologyOrder $radiologyOrder,
        RadiologyOrderService $service,
        RadiologySchedulingService $schedulingService,
    ): RedirectResponse {
        $this->assertBelongsToEncounter($clinicalEncounter, $radiologyOrder);

        $this->authorize('cancel', $radiologyOrder);

        $reason = $request->validated()['cancellation_reason'];

        $service->cancel($radiologyOrder, $reason, $request);

        // An appointment must never outlive the order it was scheduled for
        // (Phase 5, Step 39) — cancel every active appointment belonging to
        // this order's items so none is left occupying the worklist.
        $schedulingService->cancelActiveAppointmentsForOrder($radiologyOrder, $reason, $request);

        return back()->with('success', 'Radiology order cancelled.');
    }

    public function cancelItem(
        CancelRadiologyOrderItemRequest $request,
        ClinicalEncounter $clinicalEncounter,
        RadiologyOrder $radiologyOrder,
        RadiologyOrderItem $item,
        RadiologyOrderService $service,
        RadiologySchedulingService $schedulingService,
    ): RedirectResponse {
        $this->assertBelongsToEncounter($clinicalEncounter, $radiologyOrder);
        $this->assertItemBelongsToOrder($radiologyOrder, $item);

        // Removing a procedure modifies the order's own content, so it is
        // authorized against the order's 'update' ability, not a separate
        // item-level permission.
        $this->authorize('update', $radiologyOrder);

        $reason = $request->validated()['cancellation_reason'];

        $service->cancelItem($item, $reason, $request);

        // An appointment must never outlive the item it was scheduled for
        // (Phase 5, Step 39).
        $schedulingService->cancelActiveAppointmentForItem($item, $reason, $request);

        return back()->with('success', 'Radiology order item removed.');
    }

    /**
     * IDOR guard: an order accessed through
     * /clinical-encounters/{a}/radiology-orders/{order} must actually
     * belong to encounter {a}, not some other encounter whose id a client
     * substituted in the URL.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, RadiologyOrder $radiologyOrder): void
    {
        abort_if($radiologyOrder->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }

    /**
     * IDOR guard: an item accessed through .../radiology-orders/{order}/
     * items/{item} must actually belong to that order.
     */
    protected function assertItemBelongsToOrder(RadiologyOrder $radiologyOrder, RadiologyOrderItem $item): void
    {
        abort_if($item->radiology_order_id !== $radiologyOrder->id, 404);
    }
}
