<?php

namespace App\Services\NHIF;

use App\Models\ClinicalEncounter;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Generic NHIF authorization for any HMS service request.
 *
 * Handles the RequestServices → status resolution flow for any source
 * (consultation, lab, medication). Source-specific concerns (ChargeInsuranceAllocation,
 * billing warnings, etc.) are handled by the calling bridge service.
 *
 * Return statuses:
 *   authorized  — NHIF accepted/approved; ready to proceed
 *   pending     — NHIF returned PENDING_APPROVAL; poll via syncStatus
 *   rejected    — NHIF rejected the item
 *   not_covered — no NHIF authorization number on the insurance record
 *   not_eligible — item not in patient's scheme services (local eligibility check)
 *   error       — API or unexpected failure
 */
class NhifAuthorizationService
{
    public function __construct(
        protected NhifPreApprovalSessionService $sessions,
        protected NhifSchemeEligibilityService $eligibility,
        protected NhifPriceResolutionService $priceResolution,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Attempt NHIF authorization for a single service item.
     *
     * @return array{
     *   status: string,
     *   nhif_request_no: string|null,
     *   rejection_reason: string|null,
     *   session_item_id: int|null,
     *   error: string|null,
     *   maximum_quantity: int|null,
     * }
     */
    public function authorize(
        PatientInsurance $insurance,
        string $sourceType,
        int $sourceId,
        string $nhifItemCode,
        string $nhifItemName,
        float $unitPrice,
        int $quantity,
        ?ClinicalEncounter $encounter,
        Request $request,
    ): array {
        try {
            return $this->doAuthorize(
                $insurance, $sourceType, $sourceId,
                $nhifItemCode, $nhifItemName, $unitPrice, $quantity,
                $encounter, $request,
            );
        } catch (Throwable $e) {
            $this->auditLog->record('NHIF_AUTHORIZATION_ERROR', $insurance, null, [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'nhif_item_code' => $nhifItemCode,
                'error' => $e->getMessage(),
            ], $request);

            return $this->result('error', null, null, null, $e->getMessage(), null);
        }
    }

    private function doAuthorize(
        PatientInsurance $insurance,
        string $sourceType,
        int $sourceId,
        string $nhifItemCode,
        string $nhifItemName,
        float $unitPrice,
        int $quantity,
        ?ClinicalEncounter $encounter,
        Request $request,
    ): array {
        if (blank($insurance->nhif_authorization_no)) {
            return $this->result('not_covered', null, null, null, null, null);
        }

        if ($insurance->isAuthorizationExpired()) {
            return $this->result('not_covered', null, 'Patient NHIF authorization has expired (>24 hours). Re-verification required.', null, 'Authorization expired (>24h).', null);
        }

        // Scheme eligibility check (skipped when nhif_scheme_id is null — graceful degradation).
        $schemeId = $insurance->nhif_scheme_id;

        if ($schemeId && ! $this->eligibility->isEligible($schemeId, $nhifItemCode)) {
            $this->auditLog->record('NHIF_SCHEME_ELIGIBILITY_CHECKED', $insurance, null, [
                'nhif_item_code' => $nhifItemCode,
                'scheme_id' => $schemeId,
                'eligible' => false,
            ], $request);

            return $this->result('not_eligible', null, null, null,
                "Item {$nhifItemCode} is not covered under scheme {$schemeId}.", null);
        }

        // Idempotency: reuse any existing non-cancelled session item for this source.
        $existingItem = NhifPreApprovalSessionItem::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereHas('session', fn ($q) => $q->whereNotIn('status', [
                NhifPreApprovalSession::STATUS_CANCELLED,
            ]))
            ->latest()
            ->first();

        if ($existingItem) {
            return $this->resultFromItem($existingItem);
        }

        $resolved = $this->priceResolution->resolve($insurance, $nhifItemCode, $unitPrice);

        $this->auditLog->record('NHIF_AUTHORIZATION_REQUESTED', $insurance, null, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'nhif_item_code' => $nhifItemCode,
            'price_source' => $resolved['source'],
            'resolved_unit_price' => $resolved['unit_price'],
        ], $request);

        $session = $this->sessions->create(
            $insurance,
            $encounter,
            [[
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'nhif_item_code' => $nhifItemCode,
                'nhif_item_name' => $nhifItemName,
                'quantity' => $quantity,
                'unit_price' => $resolved['unit_price'],
            ]],
            $request,
        );

        $session = $this->sessions->submit($session, $request);
        $item = $session->items->first();

        if (! $item) {
            return $this->result('error', $session->nhif_request_no, null, null, 'No item in NHIF response.', null);
        }

        return $this->resultFromItem($item, $resolved['maximum_quantity']);
    }

    private function resultFromItem(NhifPreApprovalSessionItem $item, ?int $maximumQuantity = null): array
    {
        $item->loadMissing('session');
        $requestNo = $item->session?->nhif_request_no;

        if ($item->isAccepted() || $item->isApproved()) {
            return $this->result('authorized', $requestNo, null, $item->id, null, $maximumQuantity);
        }

        if ($item->isPendingApproval()) {
            return $this->result('pending', $requestNo, null, $item->id, null, $maximumQuantity);
        }

        if ($item->isRejected()) {
            return $this->result('rejected', $requestNo, $item->rejection_reason, $item->id, null, $maximumQuantity);
        }

        return $this->result('error', $requestNo, null, $item->id,
            "NHIF returned unknown status: {$item->approval_status}", $maximumQuantity);
    }

    /**
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, session_item_id: int|null, error: string|null, maximum_quantity: int|null}
     */
    private function result(
        string $status,
        ?string $requestNo,
        ?string $rejectionReason,
        ?int $sessionItemId,
        ?string $error,
        ?int $maximumQuantity,
    ): array {
        return [
            'status' => $status,
            'nhif_request_no' => $requestNo,
            'rejection_reason' => $rejectionReason,
            'session_item_id' => $sessionItemId,
            'error' => $error,
            'maximum_quantity' => $maximumQuantity,
        ];
    }
}
