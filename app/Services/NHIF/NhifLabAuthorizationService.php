<?php

namespace App\Services\NHIF;

use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifServiceMapping;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * Bridges laboratory order items to NHIF pre-approval sessions.
 *
 * For each item in the order whose HMS service has an NHIF mapping, a
 * pre-approval session is created and submitted via NhifAuthorizationService.
 * Items with no NHIF mapping are silently skipped (no mapping = not covered).
 */
class NhifLabAuthorizationService
{
    public function __construct(
        protected NhifAuthorizationService $authorization,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Attempt NHIF authorization for each item in the laboratory order.
     * Resolves the patient's primary active verified NHIF insurance from the encounter.
     * Returns an empty array when the patient has no active NHIF insurance (cash patients bypass entirely).
     *
     * @return array<int, array{item_id: int, status: string, nhif_request_no: string|null, rejection_reason: string|null, error: string|null}>
     */
    public function authorizeOrder(
        LaboratoryOrder $order,
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
            ->where('status', PatientInsurance::STATUS_ACTIVE)
            ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_authorization_no')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Attempt NHIF authorization for a single lab order item.
     * Returns null when the item has no NHIF service mapping (not covered — skip silently).
     *
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, session_item_id: int|null, error: string|null}|null
     */
    private function authorizeItem(
        PatientInsurance $insurance,
        LaboratoryOrderItem $item,
        ?ClinicalEncounter $encounter,
        Request $request,
    ): ?array {
        $charge = Charge::query()
            ->where('source_type', LaboratoryOrderItem::class)
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

        $this->auditLog->record('NHIF_LAB_AUTHORIZATION_REQUESTED', $item, null, [
            'nhif_item_code' => $mapping->nhif_item_code,
            'laboratory_order_id' => $item->laboratory_order_id,
        ], $request);

        return $this->authorization->authorize(
            $insurance,
            NhifPreApprovalSessionItem::SOURCE_LAB,
            $item->id,
            $mapping->nhif_item_code,
            $mapping->nhif_item_name ?? $item->test_name,
            (float) $charge->unit_price,
            1,
            $encounter,
            $request,
        );
    }
}
