<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrescriptionOrder\ReturnPrescriptionOrderRequest;
use App\Http\Requests\PrescriptionOrder\VerifyPrescriptionOrderRequest;
use App\Http\Resources\PrescriptionVerificationResource;
use App\Models\Charge;
use App\Models\PatientAccount;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use App\Services\FinancialAuthorizationService;
use App\Services\NHIF\NhifOrderPreApprovalService;
use App\Services\PharmacySearchService;
use App\Services\PrescriptionVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pharmacy verification queue (Phase 6, Step 47) — independent review of
 * an already-submitted PrescriptionOrder -> VERIFIED, or -> RETURNED for
 * correction. Kept as its own controller, distinct from
 * EncounterPrescriptionOrderController/PharmacyController, mirroring
 * RadiologyVerificationController's own separation from
 * RadiologyReportController: authoring/browsing and verifying are different
 * audiences with different eager-load shapes, even though both operate on
 * the same PrescriptionOrder model and the same PrescriptionOrderPolicy/
 * PrescriptionVerificationService (no separate verification model/policy/
 * service duplicate was created beyond the one genuinely new pharmacy-owned
 * entity, PrescriptionVerification, that spec §8 itself requires).
 */
class PrescriptionVerificationController extends Controller
{
    protected function eagerLoads(): array
    {
        return [
            'items.medication:id,generic_name,category,status',
            'items.cancelledBy:id,UserName',
            'prescriber:id,UserName',
            'verification.submittedBy:id,UserName',
            'verification.verifiedBy:id,UserName',
            'verification.returnedBy:id,UserName',
            'clinicalEncounter:id,encounter_number,patient_id,department_id',
            'clinicalEncounter.patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender',
            'clinicalEncounter.department:id,name',
        ];
    }

    public function index(
        Request $request,
        PharmacySearchService $search,
        FinancialAuthorizationService $financialAuth,
        NhifOrderPreApprovalService $nhifPreApproval,
    ): Response {
        $this->authorize('viewVerificationQueue', PrescriptionOrder::class);

        $filters = $request->only(['search', 'status', 'date_from', 'date_to']);

        $query = $search->verificationQueue($filters)->with($this->eagerLoads());
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $paginator = $query->paginate(20)->withQueryString();
        $financialStatuses = $this->batchPrescriptionFinancialStatuses($paginator->getCollection(), $financialAuth, $nhifPreApproval);

        $orders = $paginator->through(fn (PrescriptionOrder $order) => array_merge(
            (new PrescriptionVerificationResource($order))->resolve($request),
            ['financial_status' => $financialStatuses[$order->id] ?? FinancialAuthorizationService::STATUS_AUTHORIZED],
        ));

        return Inertia::render('pharmacy/verification/index', [
            'prescriptions' => $orders,
            'filters' => $filters,
            'statuses' => PrescriptionVerification::STATUSES,
        ]);
    }

    /**
     * Open a pending prescription for the verifier's review (spec §8/§15).
     * Fires the PRESCRIPTION_REVIEW_STARTED audit event when it is actually
     * pending a decision.
     */
    public function review(
        Request $request,
        PrescriptionOrder $prescriptionOrder,
        PrescriptionVerificationService $service,
        NhifOrderPreApprovalService $nhifPreApproval,
    ): Response {
        $this->authorize('review', $prescriptionOrder);

        $prescriptionOrder->load($this->eagerLoads());

        $service->recordReviewStarted($prescriptionOrder->verification, $request);

        $financialAuth = app(FinancialAuthorizationService::class);
        $activeItemIds = $prescriptionOrder->items
            ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
            ->pluck('id')
            ->all();

        $nhifStatus = $nhifPreApproval->statusForPrescriptionOrder($prescriptionOrder);
        $prescriptionFinancialStatus = FinancialAuthorizationService::STATUS_AUTHORIZED;

        if ($nhifStatus !== null && $nhifStatus['status'] !== NhifOrderPreApprovalService::STATUS_AUTHORIZED) {
            $prescriptionFinancialStatus = $nhifStatus['status'];
        } else {
            foreach ($activeItemIds as $itemId) {
                if ($financialAuth->statusForSource(PrescriptionOrderItem::class, $itemId) !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                    $prescriptionFinancialStatus = FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED;
                    break;
                }
            }
        }

        $patientAccountId = null;
        if ($prescriptionOrder->clinicalEncounter?->patient_id && $prescriptionOrder->clinicalEncounter?->department?->hospital_id) {
            $patientAccountId = PatientAccount::query()
                ->where('patient_id', $prescriptionOrder->clinicalEncounter->patient_id)
                ->where('hospital_id', $prescriptionOrder->clinicalEncounter->department->hospital_id)
                ->value('id');
        }

        return Inertia::render('pharmacy/verification/show', [
            'prescription' => (new PrescriptionVerificationResource($prescriptionOrder))->resolve($request),
            'canVerify' => $request->user()->can('verify', $prescriptionOrder),
            'canReturn' => $request->user()->can('returnForCorrection', $prescriptionOrder),
            'canResubmit' => $request->user()->can('update', $prescriptionOrder),
            'prescriptionFinancialStatus' => $prescriptionFinancialStatus,
            'nhifPreApprovalStatus' => $nhifStatus,
            'patientAccountId' => $patientAccountId,
        ]);
    }

    public function verify(VerifyPrescriptionOrderRequest $request, PrescriptionOrder $prescriptionOrder, PrescriptionVerificationService $service): RedirectResponse
    {
        $this->authorize('verify', $prescriptionOrder);

        $service->verify($prescriptionOrder, $request->validated(), $request);

        return redirect()->route('pharmacy-verification.review', $prescriptionOrder)
            ->with('success', 'Prescription verified successfully.');
    }

    public function returnForCorrection(ReturnPrescriptionOrderRequest $request, PrescriptionOrder $prescriptionOrder, PrescriptionVerificationService $service): RedirectResponse
    {
        $this->authorize('returnForCorrection', $prescriptionOrder);

        $service->returnForCorrection($prescriptionOrder, $request->validated(), $request);

        return redirect()->route('pharmacy-verification.index')->with('success', 'Prescription returned for correction.');
    }

    /**
     * Resubmit a returned prescription (spec §14) — the prescriber's own
     * action once they have corrected the order through the ordinary
     * PrescriptionOrderService::update() path (via
     * clinical-encounters.prescription-orders.update). Authorized against
     * the order's own 'update' ability (ownership/hospital scope), not a
     * pharmacy-verification permission — resubmitting is not a pharmacy
     * review action.
     */
    public function resubmit(Request $request, PrescriptionOrder $prescriptionOrder, PrescriptionVerificationService $service): RedirectResponse
    {
        $this->authorize('update', $prescriptionOrder);

        $service->resubmit($prescriptionOrder, $request);

        return back()->with('success', 'Prescription resubmitted for verification.');
    }

    /**
     * @param  Collection<int, PrescriptionOrder>  $orders
     * @return array<int, string>
     */
    private function batchPrescriptionFinancialStatuses(
        Collection $orders,
        FinancialAuthorizationService $financialAuth,
        NhifOrderPreApprovalService $nhifPreApproval,
    ): array {
        $nhifStatuses = $nhifPreApproval->batchPrescriptionOrderStatuses($orders);
        $result = [];
        $itemIdsByOrderId = [];

        foreach ($orders as $order) {
            if (isset($nhifStatuses[$order->id])) {
                $result[$order->id] = $nhifStatuses[$order->id]['status'];

                continue;
            }

            $itemIdsByOrderId[$order->id] = $order->items
                ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
                ->pluck('id')
                ->all();
        }

        $allItemIds = collect(array_values($itemIdsByOrderId))->flatten()->all();

        if (empty($allItemIds)) {
            foreach ($itemIdsByOrderId as $orderId => $itemIds) {
                $result[$orderId] = FinancialAuthorizationService::STATUS_AUTHORIZED;
            }

            return $result;
        }

        $chargesByItemId = Charge::query()
            ->where('source_type', PrescriptionOrderItem::class)
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
