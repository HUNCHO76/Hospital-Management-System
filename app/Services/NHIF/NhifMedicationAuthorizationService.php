<?php

namespace App\Services\NHIF;

use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifServiceMapping;
use App\Models\PatientInsurance;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bridges prescription order items to NHIF pre-approval sessions.
 *
 * For each item in the order whose HMS service has an NHIF mapping, a
 * pre-approval session is created and submitted via NhifAuthorizationService.
 * Items with no NHIF mapping are silently skipped (no mapping = not covered).
 */
class NhifMedicationAuthorizationService
{
    public function __construct(
        protected NhifAuthorizationService $authorization,
        protected NhifPriceResolutionService $priceResolution,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Attempt NHIF authorization for each item in the prescription order.
     * Resolves the patient's primary active verified NHIF insurance from the encounter.
     * Returns an empty array when the patient has no active NHIF insurance (cash patients bypass entirely).
     *
     * @return array<int, array{item_id: int, status: string, nhif_request_no: string|null, rejection_reason: string|null, error: string|null}>
     */
    public function authorizeOrder(
        PrescriptionOrder $order,
        ClinicalEncounter $encounter,
        Request $request,
    ): array {
        $insurance = $this->resolveNhifInsurance($encounter->patient_id);

        if (! $insurance) {
            return [];
        }

        $order->loadMissing('items');
        $results = [];

        foreach ($order->items as $item) {
            $result = $this->authorizeItem($insurance, $item, $encounter, $request);

            if ($result !== null) {
                $results[] = array_merge(['item_id' => $item->id], $result);
            }
        }

        return $results;
    }

    private function resolveNhifInsurance(int $patientId): ?PatientInsurance
    {
        return PatientInsurance::query()
            ->where('patient_id', $patientId)
            ->withValidAuthorization()
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Attempt NHIF authorization for a single prescription item.
     * Returns null when the item has no NHIF service mapping (not covered — skip silently).
     *
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, session_item_id: int|null, error: string|null}|null
     */
    private function authorizeItem(
        PatientInsurance $insurance,
        PrescriptionOrderItem $item,
        ?ClinicalEncounter $encounter,
        Request $request,
    ): ?array {
        $charge = Charge::query()
            ->where('source_type', PrescriptionOrderItem::class)
            ->where('source_id', $item->id)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->first();

        if (! $charge || ! $charge->service_id) {
            return null;
        }

        $mapping = NhifServiceMapping::query()
            ->where('service_id', $charge->service_id)
            ->where('hospital_id', $charge->hospital_id)
            ->where('is_active', true)
            ->whereNotIn('coverage_status', [
                NhifServiceMapping::COVERAGE_EXCLUDED,
                NhifServiceMapping::COVERAGE_NOT_MAPPED,
            ])
            ->first();

        if (! $mapping) {
            return null;
        }

        $medicineName = $item->medicine_name ?? $item->medication_name ?? (string) $item->id;
        $requestedQty = (int) ($item->quantity ?? 1);

        $resolved = $this->priceResolution->resolve($insurance, $mapping->nhif_item_code, (float) $charge->unit_price);
        $maxQty = $resolved['maximum_quantity'];

        $submittedQty = $requestedQty;
        if ($maxQty !== null && $maxQty > 0 && $requestedQty > $maxQty) {
            Log::info('NHIF medication quantity capped', [
                'item_id' => $item->id,
                'nhif_item_code' => $mapping->nhif_item_code,
                'requested' => $requestedQty,
                'maximum' => $maxQty,
            ]);
            $submittedQty = $maxQty;
        }

        $this->auditLog->record('NHIF_MEDICATION_AUTHORIZATION_REQUESTED', $item, null, [
            'nhif_item_code' => $mapping->nhif_item_code,
            'prescription_order_id' => $item->prescription_order_id,
            'requested_quantity' => $requestedQty,
            'submitted_quantity' => $submittedQty,
            'nhif_maximum_quantity' => $maxQty,
        ], $request);

        return $this->authorization->authorize(
            $insurance,
            NhifPreApprovalSessionItem::SOURCE_MEDICATION,
            $item->id,
            $mapping->nhif_item_code,
            $mapping->nhif_item_name ?? $medicineName,
            (float) $charge->unit_price,
            $submittedQty,
            $encounter,
            $request,
        );
    }
}
