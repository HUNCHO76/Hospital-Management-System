<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratorySample\CancelLaboratorySampleRequest;
use App\Http\Requests\LaboratorySample\CollectLaboratorySampleRequest;
use App\Http\Requests\LaboratorySample\RecollectLaboratorySampleRequest;
use App\Http\Requests\LaboratorySample\RejectLaboratorySampleRequest;
use App\Http\Requests\LaboratorySample\StoreLaboratorySampleRequest;
use App\Http\Resources\LaboratoryOrderResource;
use App\Http\Resources\LaboratorySampleResource;
use App\Models\Charge;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use App\Models\PatientAccount;
use App\Services\FinancialAuthorizationService;
use App\Services\LaboratoryOrderSearchService;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratorySampleService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The specimen collection workflow (Phase 4, Step 31) — its own standalone
 * surface under `laboratory/collection`, since collection is a laboratory-
 * department activity, not something performed from the doctor's clinical
 * encounter screen the way orders themselves are placed.
 */
class LaboratorySampleController extends Controller
{
    protected function sampleEagerLoads(): array
    {
        return [
            'items',
            'collectedBy:id,UserName',
            'receivedBy:id,UserName',
            'rejectedBy:id,UserName',
            'cancelledBy:id,UserName',
            'recollectionOf:id,sample_number',
            'recollections:id,recollection_of_sample_id,sample_number',
            'patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'laboratoryOrder:id,order_number,priority',
            // Needed so the collection/sample pages know whether a RECEIVED
            // specimen has already been accepted into processing (spec's
            // own "Accept" action lives on LaboratoryProcessing, not this
            // model) — without this the page cannot tell an unaccepted
            // specimen apart from an accepted one and would either hide the
            // Accept action entirely or offer it again after acceptance.
            // Only the bare status is needed for the rolled-up summary;
            // full per-test processing detail stays on the dedicated
            // Processing page (laboratory/processing/show) rather than
            // being duplicated here.
            'processingRecords:id,laboratory_sample_id,status',
        ];
    }

    /**
     * The collection worklist: orders that still have at least one item
     * needing a specimen, or awaiting one, is not fully collected (spec
     * §25). Never lists orders whose collection is already fully complete
     * or that were cancelled.
     */
    public function collectionIndex(
        Request $request,
        LaboratoryOrderSearchService $search,
        FinancialAuthorizationService $financialAuth,
        NhifOrderPreApprovalService $nhifPreApproval
    ): Response {
        $this->authorize('viewAny', LaboratorySample::class);

        $filters = $request->only(['search', 'priority', 'status', 'date_from', 'date_to']);

        $query = $search->query($filters)
            ->whereIn('status', [LaboratoryOrder::STATUS_ORDERED, LaboratoryOrder::STATUS_SAMPLE_PENDING])
            ->whereHas('items', fn ($q) => $q->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED))
            ->with(['items.laboratoryTest:id,specimen_type', 'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name', 'clinicalEncounter.patient.insurances']);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $paginator = $query->latest('ordered_at')->paginate(15)->withQueryString();
        $financialStatuses = $this->batchOrderFinancialStatuses($paginator->getCollection(), $financialAuth, $nhifPreApproval);

        $orders = $paginator->through(fn (LaboratoryOrder $order) => array_merge(
            (new LaboratoryOrderResource($order))->resolve($request),
            ['financial_status' => $financialStatuses[$order->id] ?? FinancialAuthorizationService::STATUS_AUTHORIZED],
        ));

        return Inertia::render('laboratory/collection/index', [
            'orders' => $orders,
            'filters' => $filters,
            'priorities' => LaboratoryOrder::PRIORITIES,
        ]);
    }

    /**
     * Batch-compute the financial authorization / NHIF pre-approval status
     * for every order on a paginated page.
     *
     * @param  Collection<int, LaboratoryOrder>  $orders
     * @return array<int, string>
     */
    private function batchOrderFinancialStatuses(
        Collection $orders,
        FinancialAuthorizationService $financialAuth,
        NhifOrderPreApprovalService $nhifPreApproval
    ): array {
        $nhifStatuses = $nhifPreApproval->batchLaboratoryOrderStatuses($orders);
        $result = [];
        $itemIdsByOrderId = [];

        foreach ($orders as $order) {
            if ($order->status === LaboratoryOrder::STATUS_CANCELLED) {
                $result[$order->id] = 'cancelled';

                continue;
            }

            if (isset($nhifStatuses[$order->id])) {
                $result[$order->id] = $nhifStatuses[$order->id]['status'];

                continue;
            }

            $activeItems = $order->items->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED);
            if ($activeItems->isEmpty()) {
                $result[$order->id] = 'cancelled';

                continue;
            }

            $itemIdsByOrderId[$order->id] = $activeItems->pluck('id')->all();
        }

        $allItemIds = collect(array_values($itemIdsByOrderId))->flatten()->all();

        if (empty($allItemIds)) {
            return $result + array_fill_keys(array_keys($itemIdsByOrderId), FinancialAuthorizationService::STATUS_AUTHORIZED);
        }

        $chargesByItemId = Charge::query()
            ->where('source_type', LaboratoryOrderItem::class)
            ->whereIn('source_id', $allItemIds)
            ->get()
            ->keyBy('source_id');

        foreach ($itemIdsByOrderId as $orderId => $itemIds) {
            $status = FinancialAuthorizationService::STATUS_AUTHORIZED;
            foreach ($itemIds as $itemId) {
                $charge = $chargesByItemId->get($itemId);
                if ($financialAuth->statusForCharge($charge) !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                    $status = FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED;
                    break;
                }
            }
            $result[$orderId] = $status;
        }

        return $result;
    }

    /**
     * The collection detail for a single order: its tests with their
     * catalog specimen/container requirement, and every sample registered
     * against it so far (spec §26).
     */
    public function collectionShow(Request $request, LaboratoryOrder $laboratoryOrder): Response
    {
        $this->authorize('viewCollection', [LaboratorySample::class, $laboratoryOrder]);

        $laboratoryOrder->load([
            'items.laboratoryTest:id,specimen_type,specimen_container',
            'items.samples',
            'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'orderedBy:id,UserName',
        ]);

        $samples = $laboratoryOrder->samples()->with($this->sampleEagerLoads())->get();

        $financialAuth = app(FinancialAuthorizationService::class);
        $activeItemIds = $laboratoryOrder->items->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->pluck('id')->all();

        $nhifPreApproval = app(NhifOrderPreApprovalService::class);
        $nhifStatus = $nhifPreApproval->statusForLaboratoryOrder($laboratoryOrder);

        $orderFinancialStatus = FinancialAuthorizationService::STATUS_AUTHORIZED;
        if ($laboratoryOrder->status === LaboratoryOrder::STATUS_CANCELLED || empty($activeItemIds)) {
            $orderFinancialStatus = 'cancelled';
        } elseif ($nhifStatus !== null) {
            $orderFinancialStatus = $nhifStatus['status'];
        } else {
            foreach ($activeItemIds as $itemId) {
                if ($financialAuth->statusForSource(LaboratoryOrderItem::class, $itemId) !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                    $orderFinancialStatus = FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED;
                    break;
                }
            }
        }

        $patientAccountId = null;
        if ($laboratoryOrder->clinicalEncounter?->patient_id && $laboratoryOrder->clinicalEncounter?->department?->hospital_id) {
            $patientAccountId = PatientAccount::query()
                ->where('patient_id', $laboratoryOrder->clinicalEncounter->patient_id)
                ->where('hospital_id', $laboratoryOrder->clinicalEncounter->department->hospital_id)
                ->value('id');
        }

        return Inertia::render('laboratory/collection/show', [
            'order' => (new LaboratoryOrderResource($laboratoryOrder))->resolve($request),
            'samples' => LaboratorySampleResource::collection($samples)->resolve($request),
            'specimenTypes' => LaboratoryTest::SPECIMEN_TYPES,
            'specimenContainers' => LaboratoryTest::SPECIMEN_CONTAINERS,
            'orderFinancialStatus' => $orderFinancialStatus,
            'nhifPreApprovalStatus' => $nhifStatus,
            'patientAccountId' => $patientAccountId,
        ]);
    }

    public function store(StoreLaboratorySampleRequest $request, LaboratoryOrder $laboratoryOrder, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('create', [LaboratorySample::class, $laboratoryOrder]);

        $service->create($laboratoryOrder, $request->validated(), $request);

        return back()->with('success', 'Specimen registered.');
    }

    public function show(Request $request, LaboratorySample $sample): Response
    {
        $sample->load($this->sampleEagerLoads());

        $this->authorize('view', $sample);

        return Inertia::render('laboratory/samples/show', [
            'sample' => (new LaboratorySampleResource($sample))->resolve($request),
        ]);
    }

    public function labelView(Request $request, LaboratorySample $sample): Response
    {
        $sample->load(['items', 'patient:id,patient_number,first_name,middle_name,last_name', 'laboratoryOrder:id,order_number']);

        $this->authorize('view', $sample);

        return Inertia::render('laboratory/samples/label', [
            'sample' => (new LaboratorySampleResource($sample))->resolve($request),
        ]);
    }

    public function collect(CollectLaboratorySampleRequest $request, LaboratorySample $sample, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('collect', $sample);

        $service->collect($sample, $request->validated(), $request);

        return back()->with('success', 'Specimen collected.');
    }

    public function label(Request $request, LaboratorySample $sample, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('label', $sample);

        $service->label($sample, $request);

        return back()->with('success', 'Specimen label generated.');
    }

    public function receive(Request $request, LaboratorySample $sample, LaboratorySampleService $service, LaboratoryProcessingService $processing): RedirectResponse
    {
        $this->authorize('receive', $sample);

        $sample = $service->receive($sample, $request);
        $processing->initializeForSample($sample, $request);

        return back()->with('success', 'Specimen received.');
    }

    public function reject(RejectLaboratorySampleRequest $request, LaboratorySample $sample, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('reject', $sample);

        $service->reject($sample, $request->validated(), $request);

        return back()->with('success', 'Specimen rejected.');
    }

    public function recollect(RecollectLaboratorySampleRequest $request, LaboratorySample $sample, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('recollect', $sample);

        $newSample = $service->recollect($sample, $request->validated(), $request);

        return to_route('laboratory.collection.show', $sample->laboratory_order_id)->with('success', "Recollection {$newSample->sample_number} created.");
    }

    public function cancel(CancelLaboratorySampleRequest $request, LaboratorySample $sample, LaboratorySampleService $service): RedirectResponse
    {
        $this->authorize('cancel', $sample);

        $service->cancel($sample, $request->validated()['cancellation_reason'], $request);

        return back()->with('success', 'Specimen registration cancelled.');
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
