<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dispensing\CancelDispensingRequest;
use App\Http\Requests\Dispensing\CompleteDispensingRequest;
use App\Http\Resources\PrescriptionDispensingResource;
use App\Models\Charge;
use App\Models\Dispensing;
use App\Models\PatientAccount;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\DispensingSearchService;
use App\Services\DispensingService;
use App\Services\FinancialAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pharmacy dispensing queue and workflow (Phase 6, Step 48) — the actual
 * fulfillment of an already-VERIFIED PrescriptionOrder. Kept as its own
 * controller, distinct from PharmacyController/PrescriptionVerificationController,
 * mirroring their own separation: authoring/browsing, verifying, and
 * dispensing are different audiences with different eager-load shapes, even
 * though all three operate on the same PrescriptionOrder model. Every
 * action here (except start/complete/cancel targeting the order's own
 * single in-progress Dispensing) reads that Dispensing off the order itself
 * rather than taking a separate route parameter, since only one may ever be
 * in progress at a time (DispensingService::start()'s own guarantee).
 */
class DispensingController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'items.medication:id,generic_name,category',
            'items.dispensingItems.dispensing:id,status',
            'prescriber:id,UserName',
            'verification.verifiedBy:id,UserName',
            'dispensings.dispensedBy:id,UserName',
            'dispensings.cancelledBy:id,UserName',
            'dispensings.items',
            'clinicalEncounter:id,encounter_number,patient_id,department_id',
            'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'clinicalEncounter.department:id,name',
        ];
    }

    public function index(Request $request, DispensingSearchService $search, FinancialAuthorizationService $financialAuth): Response
    {
        $this->authorize('viewDispensingQueue', PrescriptionOrder::class);

        $filters = $request->only(['search', 'status', 'date_from', 'date_to']);

        $query = $search->query($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $paginator = $query->paginate(20)->withQueryString();
        $financialStatuses = $this->batchPrescriptionFinancialStatuses($paginator->getCollection(), $financialAuth);

        $prescriptions = $paginator->through(fn (PrescriptionOrder $order) => array_merge(
            (new PrescriptionDispensingResource($order))->resolve($request),
            ['financial_status' => $financialStatuses[$order->id] ?? FinancialAuthorizationService::STATUS_AUTHORIZED],
        ));

        return Inertia::render('pharmacy/dispensing/index', [
            'prescriptions' => $prescriptions,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  Collection<int, PrescriptionOrder>  $orders
     * @return array<int, string>
     */
    private function batchPrescriptionFinancialStatuses(Collection $orders, FinancialAuthorizationService $financialAuth): array
    {
        $itemIdsByOrderId = [];
        foreach ($orders as $order) {
            $itemIdsByOrderId[$order->id] = $order->items
                ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
                ->pluck('id')
                ->all();
        }

        $allItemIds = collect(array_values($itemIdsByOrderId))->flatten()->all();

        if (empty($allItemIds)) {
            return array_fill_keys(array_keys($itemIdsByOrderId), FinancialAuthorizationService::STATUS_AUTHORIZED);
        }

        $chargesByItemId = Charge::query()
            ->where('source_type', PrescriptionOrderItem::class)
            ->whereIn('source_id', $allItemIds)
            ->get()
            ->keyBy('source_id');

        $result = [];
        foreach ($itemIdsByOrderId as $orderId => $itemIds) {
            $status = FinancialAuthorizationService::STATUS_AUTHORIZED;
            foreach ($itemIds as $itemId) {
                $charge = $chargesByItemId->get($itemId);
                if ($charge === null || $financialAuth->statusForCharge($charge) !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                    $status = FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED;
                    break;
                }
            }
            $result[$orderId] = $status;
        }

        return $result;
    }

    public function show(Request $request, PrescriptionOrder $prescriptionOrder, DispensingService $service): Response
    {
        $this->authorize('viewDispensing', $prescriptionOrder);

        $prescriptionOrder->load($this->eagerLoads());

        $financialAuth = app(FinancialAuthorizationService::class);
        $activeItemIds = $prescriptionOrder->items
            ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
            ->pluck('id')
            ->all();

        $prescriptionFinancialStatus = FinancialAuthorizationService::STATUS_AUTHORIZED;
        foreach ($activeItemIds as $itemId) {
            if ($financialAuth->statusForSourceRequired(PrescriptionOrderItem::class, $itemId) !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                $prescriptionFinancialStatus = FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED;
                break;
            }
        }

        $patientAccountId = null;
        if ($prescriptionOrder->clinicalEncounter?->patient_id && $prescriptionOrder->clinicalEncounter?->department?->hospital_id) {
            $patientAccountId = PatientAccount::query()
                ->where('patient_id', $prescriptionOrder->clinicalEncounter->patient_id)
                ->where('hospital_id', $prescriptionOrder->clinicalEncounter->department->hospital_id)
                ->value('id');
        }

        return Inertia::render('pharmacy/dispensing/show', [
            'prescription' => (new PrescriptionDispensingResource($prescriptionOrder))->resolve($request),
            'prescriptionFinancialStatus' => $prescriptionFinancialStatus,
            'patientAccountId' => $patientAccountId,
            'canStart' => $request->user()->can('startDispensing', $prescriptionOrder),
            'canComplete' => $request->user()->can('completeDispensing', $prescriptionOrder),
            'canCancel' => $request->user()->can('cancelDispensing', $prescriptionOrder),
        ]);
    }

    public function start(Request $request, PrescriptionOrder $prescriptionOrder, DispensingService $service): RedirectResponse
    {
        $this->authorize('startDispensing', $prescriptionOrder);

        $service->start($prescriptionOrder, $request);

        return redirect()->route('pharmacy-dispensing.show', $prescriptionOrder)->with('success', 'Dispensing started.');
    }

    public function complete(CompleteDispensingRequest $request, PrescriptionOrder $prescriptionOrder, DispensingService $service): RedirectResponse
    {
        $this->authorize('completeDispensing', $prescriptionOrder);

        $dispensing = $this->currentDispensing($prescriptionOrder);

        $service->complete($dispensing, $request->validated(), $request);

        return redirect()->route('pharmacy-dispensing.show', $prescriptionOrder)->with('success', 'Dispensing completed.');
    }

    public function cancel(CancelDispensingRequest $request, PrescriptionOrder $prescriptionOrder, DispensingService $service): RedirectResponse
    {
        $this->authorize('cancelDispensing', $prescriptionOrder);

        $dispensing = $this->currentDispensing($prescriptionOrder);

        $service->cancel($dispensing, $request->validated()['cancellation_reason'], $request);

        return redirect()->route('pharmacy-dispensing.show', $prescriptionOrder)->with('success', 'Dispensing cancelled.');
    }

    /**
     * The prescription's own single in-progress dispensing event — never a
     * client-supplied dispensing id, so a request can never be pointed at
     * another order's event.
     *
     * @throws ValidationException
     */
    protected function currentDispensing(PrescriptionOrder $prescriptionOrder): Dispensing
    {
        $dispensing = $prescriptionOrder->dispensings()->where('status', Dispensing::STATUS_IN_PROGRESS)->first();

        if (! $dispensing) {
            throw ValidationException::withMessages([
                'status' => 'No dispensing event is currently in progress for this prescription.',
            ]);
        }

        return $dispensing;
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
