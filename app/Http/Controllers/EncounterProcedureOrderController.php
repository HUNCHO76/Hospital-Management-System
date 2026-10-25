<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcedureOrder\CancelProcedureOrderRequest;
use App\Http\Requests\ProcedureOrder\ExecuteProcedureOrderRequest;
use App\Http\Requests\ProcedureOrder\StoreEncounterProcedureOrderRequest;
use App\Models\ClinicalEncounter;
use App\Models\ProcedureOrder;
use App\Services\ProcedureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EncounterProcedureOrderController extends Controller
{
    /**
     * Store a doctor-prescribed clinical procedure order for an encounter.
     */
    public function store(
        StoreEncounterProcedureOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        ProcedureService $service,
    ): RedirectResponse {
        $this->authorize('create', ProcedureOrder::class);

        $order = $service->createForEncounter($clinicalEncounter, $request->validated(), $request);

        $message = "Procedure order {$order->order_number} ({$order->procedure_name}) placed successfully.";

        if ($order->payment_mode === ProcedureOrder::PAYMENT_MODE_CASH) {
            $message .= " Cash bill created (TZS {$order->total_price}). Patient must settle bill at the cashier.";
        } elseif ($order->isPendingAuthorization()) {
            $message .= " NHIF Pre-authorization requested. Awaiting approval before execution.";
        }

        return back()->with('success', $message);
    }

    /**
     * Record execution, clinical notes, and findings for a procedure order.
     */
    public function execute(
        ExecuteProcedureOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        ProcedureOrder $procedureOrder,
        ProcedureService $service,
    ): RedirectResponse {
        $this->assertBelongsToEncounter($clinicalEncounter, $procedureOrder);
        $this->authorize('execute', $procedureOrder);

        $service->recordExecution($procedureOrder, $request->validated(), $request);

        return back()->with('success', "Procedure {$procedureOrder->order_number} execution documented successfully.");
    }

    /**
     * Cancel an active procedure order.
     */
    public function cancel(
        CancelProcedureOrderRequest $request,
        ClinicalEncounter $clinicalEncounter,
        ProcedureOrder $procedureOrder,
        ProcedureService $service,
    ): RedirectResponse {
        $this->assertBelongsToEncounter($clinicalEncounter, $procedureOrder);
        $this->authorize('cancel', $procedureOrder);

        $service->cancelOrder($procedureOrder, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', "Procedure order {$procedureOrder->order_number} cancelled.");
    }

    /**
     * Search available procedures from the hospital catalogue with pricing and scheme eligibility.
     */
    public function searchCatalogue(
        Request $request,
        ClinicalEncounter $clinicalEncounter,
        ProcedureService $service,
    ): JsonResponse {
        $this->authorize('viewAny', ProcedureOrder::class);

        $search = $request->query('q');
        $procedures = $service->getAvailableProcedures($clinicalEncounter, $search);

        return response()->json($procedures);
    }

    /**
     * IDOR guard: an order accessed through
     * /clinical-encounters/{encounter}/procedure-orders/{procedureOrder}
     * must actually belong to this encounter.
     */
    protected function assertBelongsToEncounter(ClinicalEncounter $clinicalEncounter, ProcedureOrder $procedureOrder): void
    {
        abort_if($procedureOrder->clinical_encounter_id !== $clinicalEncounter->id, 404);
    }
}
