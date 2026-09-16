<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrescriptionOrder\CancelPrescriptionOrderItemRequest;
use App\Http\Requests\PrescriptionOrder\CancelPrescriptionOrderRequest;
use App\Http\Requests\PrescriptionOrder\StorePrescriptionOrderRequest;
use App\Http\Requests\PrescriptionOrder\UpdatePrescriptionOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\PrescriptionOrderService;
use Illuminate\Http\RedirectResponse;

/**
 * Prescription orders are presented as part of the clinical encounter
 * screen, not a standalone page — every action here redirects back to
 * clinical-encounters.show rather than rendering its own Inertia page.
 * Prescription is its own sibling branch off the encounter (Phase 6, Step
 * 46), mirroring EncounterRadiologyOrderController exactly.
 */
class EncounterPrescriptionOrderController extends Controller
{
    public function store(StorePrescriptionOrderRequest $request, ClinicalEncounter $clinicalEncounter, PrescriptionOrderService $service): RedirectResponse
    {
        $this->authorize('create', [PrescriptionOrder::class, $clinicalEncounter]);

        $service->create($clinicalEncounter, $request->validated(), $request);

        return back()->with('success', 'Prescription written.');
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
