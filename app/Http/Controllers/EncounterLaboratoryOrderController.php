<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryOrder\CancelLaboratoryOrderItemRequest;
use App\Http\Requests\LaboratoryOrder\CancelLaboratoryOrderRequest;
use App\Http\Requests\LaboratoryOrder\StoreLaboratoryOrderRequest;
use App\Http\Requests\LaboratoryOrder\UpdateLaboratoryOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Services\LaboratoryOrderService;
use Illuminate\Http\RedirectResponse;

/**
 * Laboratory orders are presented as part of the clinical encounter screen,
 * not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 * Laboratory is its own sibling branch off the encounter (Phase 4, Step 30),
 * so it gets its own controller rather than living under
 * CarePlanItemController.
 */
class EncounterLaboratoryOrderController extends Controller
{
    public function store(StoreLaboratoryOrderRequest $request, ClinicalEncounter $clinicalEncounter, LaboratoryOrderService $service): RedirectResponse
    {
        $this->authorize('create', [LaboratoryOrder::class, $clinicalEncounter]);

        $service->create($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Laboratory order placed.');
    }

    public function update(UpdateLaboratoryOrderRequest $request, ClinicalEncounter $clinicalEncounter, LaboratoryOrder $laboratoryOrder, LaboratoryOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $laboratoryOrder);

        $this->authorize('update', $laboratoryOrder);

        $service->update($laboratoryOrder, $request->validated(), $request);

        return back()->with('success', 'Laboratory order updated.');
    }

    public function cancel(CancelLaboratoryOrderRequest $request, ClinicalEncounter $clinicalEncounter, LaboratoryOrder $laboratoryOrder, LaboratoryOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $laboratoryOrder);

        $this->authorize('cancel', $laboratoryOrder);

        $service->cancel($laboratoryOrder, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Laboratory order cancelled.');
    }

    public function cancelItem(CancelLaboratoryOrderItemRequest $request, ClinicalEncounter $clinicalEncounter, LaboratoryOrder $laboratoryOrder, LaboratoryOrderItem $item, LaboratoryOrderService $service): RedirectResponse
    {
        $this->assertBelongsToEncounter($clinicalEncounter, $laboratoryOrder);
        $this->assertItemBelongsToOrder($laboratoryOrder, $item);

        // Removing a test modifies the order's own content, so it is
        // authorized against the order's 'update' ability, not a separate
        // item-level permission.
        $this->authorize('update', $laboratoryOrder);

        $service->cancelItem($item, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Laboratory order item removed.');
    }

    /**
     * IDOR guard: an order accessed through
     * /clinical-encounters/{a}/laboratory-orders/{order} must actually
     * belong to encounter {a}, not some other encounter whose id a client
     * substituted in the URL.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, LaboratoryOrder $laboratoryOrder): void
    {
        abort_if($laboratoryOrder->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }

    /**
     * IDOR guard: an item accessed through .../laboratory-orders/{order}/
     * items/{item} must actually belong to that order.
     */
    protected function assertItemBelongsToOrder(LaboratoryOrder $laboratoryOrder, LaboratoryOrderItem $item): void
    {
        abort_if($item->laboratory_order_id !== $laboratoryOrder->id, 404);
    }
}
