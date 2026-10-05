<?php

namespace App\Services\NHIF;

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\NhifServiceMapping;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Throwable;

/**
 * NHIF Service Authorization Gate for consultation charges (Phase 8).
 *
 * Bridges the existing NhifPreApprovalSessionService (RequestServices) with
 * FinancialAuthorizationService's ChargeInsuranceAllocation hook so that an
 * NHIF-accepted consultation charge is financially cleared without cash payment.
 *
 * Flow:
 *   1. Resolve active NHIF insurance with authorizationNo.
 *   2. Look up NhifServiceMapping for the charge's service + hospital.
 *   3. Idempotency: reuse an existing NhifPreApprovalSessionItem for this source.
 *   4. If none: call NhifPreApprovalSessionService::create() + submit().
 *   5. On ACCEPTED: create ChargeInsuranceAllocation (patient_responsibility = 0
 *      when 100% covered, or partial when nhif_percent_covered < 100).
 *   6. Return a typed NhifAuthorizationResult.
 *
 * Never throws — all NHIF API failures are caught and returned as status=error
 * so the calling service can degrade gracefully to the cash path.
 */
class NhifConsultationAuthorizationService
{
    public function __construct(
        protected NhifPreApprovalSessionService $sessions,
        protected NhifPriceResolutionService $priceResolution,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Pre-authorize an NHIF consultation BEFORE the walk-in visit exists.
     *
     * This is the NHIF registration gate: it submits RequestServices to NHIF
     * and returns the result. If accepted, the caller proceeds to create the
     * walk-in visit and then calls attachToVisit() to link the authorization.
     * If rejected/pending/error, the caller must NOT create the visit.
     *
     * The pre-approval session item is created with null source (no visit yet).
     * attachToVisit() updates it after the visit is created.
     *
     * @return array{
     *   status: string,
     *   nhif_request_no: string|null,
     *   rejection_reason: string|null,
     *   session_item_id: int|null,
     *   insurance_id: int|null,
     *   nhif_item_code: string|null,
     *   unit_price: float|null,
     *   percent_covered: float|null,
     *   error: string|null,
     * }
     */
    public function preAuthorize(
        int $patientId,
        int $hospitalId,
        int $serviceId,
        ?int $practitionerId,
        string $nhifItemCode,
        Request $request,
    ): array {
        try {
            return $this->doPreAuthorize($patientId, $hospitalId, $serviceId, $practitionerId, $nhifItemCode, $request);
        } catch (Throwable $e) {
            $this->auditLog->record(
                'NHIF_PREAUTH_WALKIN_ERROR',
                null,
                null,
                ['patient_id' => $patientId, 'nhif_item_code' => $nhifItemCode, 'error' => $e->getMessage()],
                $request,
            );

            return $this->preAuthResult('error', null, $e->getMessage(), null, null, $nhifItemCode, null, null, $e->getMessage());
        }
    }

    /**
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, session_item_id: int|null, insurance_id: int|null, nhif_item_code: string|null, unit_price: float|null, percent_covered: float|null, error: string|null}
     */
    private function doPreAuthorize(
        int $patientId,
        int $hospitalId,
        int $serviceId,
        ?int $practitionerId,
        string $nhifItemCode,
        Request $request,
    ): array {
        $insurance = PatientInsurance::query()
            ->where('patient_id', $patientId)
            ->where('status', PatientInsurance::STATUS_ACTIVE)
            ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_authorization_no')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();

        if (! $insurance) {
            return $this->preAuthResult('not_covered', null, 'Patient has no active, verified NHIF insurance on file. Please verify the patient\'s NHIF card first.', null, null, $nhifItemCode, null, null, null);
        }

        // Resolve item name: prefer NhifServiceMapping, fall back to the NHIF catalog
        $mapping = NhifServiceMapping::query()
            ->where('hospital_id', $hospitalId)
            ->where('nhif_item_code', $nhifItemCode)
            ->where('coverage_status', NhifServiceMapping::COVERAGE_COVERED)
            ->first();

        $itemName = $mapping?->nhif_item_name
            ?? NhifSchemeService::where('item_code', $nhifItemCode)->value('item_name')
            ?? $nhifItemCode;

        $practitionerNo = null;
        if ($practitionerId) {
            $practitionerNo = Practitioner::query()
                ->whereKey($practitionerId)
                ->value('nhif_practitioner_no');
        }

        $resolved = $this->priceResolution->resolve($insurance, $nhifItemCode, 0.0);
        $unitPrice = $resolved['unit_price'];

        if ($unitPrice <= 0.0) {
            return $this->preAuthResult('error', null, null, null, $insurance->id, $nhifItemCode, null, null, 'Could not determine the NHIF price for item code "'.$nhifItemCode.'". Ensure the facility NHIF price catalog is synced.');
        }

        $this->auditLog->record(
            'NHIF_PREAUTH_WALKIN_REQUESTED',
            null,
            null,
            [
                'patient_id' => $patientId,
                'nhif_authorization_no' => $insurance->nhif_authorization_no,
                'nhif_item_code' => $nhifItemCode,
                'practitioner_no' => $practitionerNo,
                'resolved_price' => $unitPrice,
            ],
            $request,
        );

        $session = $this->sessions->create(
            $insurance,
            null,
            [[
                'source_type' => null,
                'source_id' => null,
                'nhif_item_code' => $nhifItemCode,
                'nhif_item_name' => $itemName,
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ]],
            $request,
            $practitionerNo,
            $request->input('clinical_notes'),
            $request->input('practitioner_remarks'),
        );

        $session = $this->sessions->submit($session, $request);
        $item = $session->items->first();

        if (! $item) {
            return $this->preAuthResult('error', $session->nhif_request_no, null, null, $insurance->id, $nhifItemCode, $unitPrice, null, 'NHIF returned an empty response with no service items. Please retry or contact NHIF support.');
        }

        $item->loadMissing('session');
        $requestNo = $item->session?->nhif_request_no;

        if ($item->isAccepted() || $item->isApproved()) {
            $percentCovered = $item->nhif_percent_covered !== null ? (float) $item->nhif_percent_covered : 100.0;

            return $this->preAuthResult('authorized', $requestNo, null, $item->id, $insurance->id, $nhifItemCode, $unitPrice, $percentCovered, null);
        }

        if ($item->isPendingApproval()) {
            return $this->preAuthResult('pending', $requestNo, null, $item->id, $insurance->id, $nhifItemCode, $unitPrice, null, null);
        }

        if ($item->isRejected()) {
            return $this->preAuthResult('rejected', $requestNo, $item->rejection_reason, $item->id, $insurance->id, $nhifItemCode, $unitPrice, null, null);
        }

        return $this->preAuthResult('error', $requestNo, null, $item->id, $insurance->id, $nhifItemCode, $unitPrice, null, "NHIF returned an unexpected status: \"{$item->approval_status}\". Please contact NHIF support.");
    }

    /**
     * Link a pre-authorized NHIF session item to the newly created walk-in
     * visit and charge, and create the ChargeInsuranceAllocation.
     */
    public function attachToVisit(
        int $sessionItemId,
        ReceptionVisit $visit,
        Charge $charge,
        float $percentCovered,
        int $insuranceId,
        Request $request,
    ): void {
        $item = NhifPreApprovalSessionItem::find($sessionItemId);

        if ($item) {
            $item->update([
                'source_type' => ReceptionVisit::class,
                'source_id' => $visit->id,
            ]);
        }

        $chargeAmount = (float) $charge->net_amount;
        $coveredAmount = round($chargeAmount * ($percentCovered / 100.0), 2);
        $patientResponsibility = max(0.0, round($chargeAmount - $coveredAmount, 2));

        $allocationStatus = $patientResponsibility <= 0.0
            ? ChargeInsuranceAllocation::STATUS_FULLY_COVERED
            : ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED;

        $charge->refresh();

        ChargeInsuranceAllocation::updateOrCreate(
            ['charge_id' => $charge->id],
            [
                'hospital_id' => $charge->hospital_id,
                'patient_id' => $charge->patient_id,
                'invoice_id' => $charge->invoice_id,
                'patient_insurance_id' => $insuranceId,
                'coverage_percentage_applied' => $percentCovered,
                'eligible_amount' => $chargeAmount,
                'covered_amount' => $coveredAmount,
                'patient_responsibility' => $patientResponsibility,
                'currency' => $charge->currency ?? 'TZS',
                'status' => $allocationStatus,
                'calculated_at' => now(),
                'calculated_by' => $request->user()?->id,
            ],
        );
    }

    /**
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, session_item_id: int|null, insurance_id: int|null, nhif_item_code: string|null, unit_price: float|null, percent_covered: float|null, error: string|null}
     */
    private function preAuthResult(
        string $status,
        ?string $requestNo,
        ?string $rejectionReason,
        ?int $sessionItemId,
        ?int $insuranceId,
        ?string $nhifItemCode,
        ?float $unitPrice,
        ?float $percentCovered,
        ?string $error,
    ): array {
        return [
            'status' => $status,
            'nhif_request_no' => $requestNo,
            'rejection_reason' => $rejectionReason,
            'session_item_id' => $sessionItemId,
            'insurance_id' => $insuranceId,
            'nhif_item_code' => $nhifItemCode,
            'unit_price' => $unitPrice,
            'percent_covered' => $percentCovered,
            'error' => $error,
        ];
    }

    /**
     * Attempt NHIF authorization for a reception-visit consultation charge.
     * Used for appointment check-ins where the visit already exists.
     *
     * @return array{
     *   status: string,
     *   nhif_request_no: string|null,
     *   rejection_reason: string|null,
     *   patient_responsibility: float,
     *   session_item_id: int|null,
     * }
     */
    public function authorizeConsultation(ReceptionVisit $visit, Charge $charge, Request $request): array
    {
        try {
            return $this->doAuthorize($visit, $charge, $request);
        } catch (Throwable $e) {
            $this->auditLog->record(
                'NHIF_SERVICE_AUTHORIZATION_ERROR',
                $visit,
                null,
                ['error' => $e->getMessage(), 'charge_id' => $charge->id],
                $request,
            );

            return $this->result('error', null, $e->getMessage(), (float) $charge->net_amount, null);
        }
    }

    /**
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, patient_responsibility: float, session_item_id: int|null}
     */
    private function doAuthorize(ReceptionVisit $visit, Charge $charge, Request $request): array
    {
        // 1. Resolve active NHIF insurance with authorizationNo.
        $insurance = PatientInsurance::query()
            ->where('patient_id', $visit->patient_id)
            ->where('status', PatientInsurance::STATUS_ACTIVE)
            ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_authorization_no')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();

        if (! $insurance) {
            return $this->result('not_covered', null, null, (float) $charge->net_amount, null);
        }

        // 2. Service mapping: must have an active, non-excluded mapping.
        $mapping = NhifServiceMapping::query()
            ->where('service_id', $charge->service_id)
            ->where('hospital_id', $charge->hospital_id)
            ->where('is_active', true)
            ->whereNotIn('coverage_status', [NhifServiceMapping::COVERAGE_EXCLUDED, NhifServiceMapping::COVERAGE_NOT_MAPPED])
            ->first();

        if (! $mapping) {
            return $this->result('not_covered', null, null, (float) $charge->net_amount, null);
        }

        // 3. Idempotency: reuse an existing session item for this source.
        $existingItem = NhifPreApprovalSessionItem::query()
            ->where('source_type', ReceptionVisit::class)
            ->where('source_id', $visit->id)
            ->whereHas('session', fn ($q) => $q->whereNotIn('status', [
                NhifPreApprovalSession::STATUS_CANCELLED,
            ]))
            ->latest()
            ->first();

        if ($existingItem) {
            return $this->resultFromItem($existingItem, $charge, $insurance, $request);
        }

        // 4. Call RequestServices.
        // Resolve the practitioner's NHIF practitioner number (never trust client-submitted).
        $practitionerNo = null;
        if ($visit->practitioner_id) {
            $practitionerNo = Practitioner::query()
                ->whereKey($visit->practitioner_id)
                ->value('nhif_practitioner_no');
        }

        $resolved = $this->priceResolution->resolve($insurance, $mapping->nhif_item_code, (float) $charge->unit_price);
        $unitPrice = $resolved['unit_price'];

        $this->auditLog->record(
            'NHIF_SERVICE_AUTHORIZATION_REQUESTED',
            $visit,
            null,
            [
                'nhif_authorization_no' => $insurance->nhif_authorization_no,
                'nhif_item_code' => $mapping->nhif_item_code,
                'charge_id' => $charge->id,
                'practitioner_no' => $practitionerNo,
            ],
            $request,
        );

        $session = $this->sessions->create(
            $insurance,
            null,
            [[
                'source_type' => ReceptionVisit::class,
                'source_id' => $visit->id,
                'nhif_item_code' => $mapping->nhif_item_code,
                'nhif_item_name' => $mapping->nhif_item_name,
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ]],
            $request,
            $practitionerNo,
            $request->input('clinical_notes'),
            $request->input('practitioner_remarks'),
        );

        $session = $this->sessions->submit($session, $request);
        $item = $session->items->first();

        if (! $item) {
            return $this->result('error', $session->nhif_request_no, 'No item in NHIF response', (float) $charge->net_amount, null);
        }

        return $this->resultFromItem($item, $charge, $insurance, $request);
    }

    /**
     * Derive a result from an existing or freshly-submitted session item and,
     * when accepted/approved, create/update the ChargeInsuranceAllocation.
     *
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, patient_responsibility: float, session_item_id: int|null}
     */
    private function resultFromItem(NhifPreApprovalSessionItem $item, Charge $charge, PatientInsurance $insurance, Request $request): array
    {
        $item->loadMissing('session');
        $requestNo = $item->session?->nhif_request_no;

        if ($item->isAccepted() || $item->isApproved()) {
            $percentCovered = $item->nhif_percent_covered !== null
                ? (float) $item->nhif_percent_covered
                : 100.0;

            $chargeAmount = (float) $charge->net_amount;
            $coveredAmount = round($chargeAmount * ($percentCovered / 100.0), 2);
            $patientResponsibility = max(0.0, round($chargeAmount - $coveredAmount, 2));

            $allocationStatus = $patientResponsibility <= 0.0
                ? ChargeInsuranceAllocation::STATUS_FULLY_COVERED
                : ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED;

            $charge->refresh();

            ChargeInsuranceAllocation::updateOrCreate(
                ['charge_id' => $charge->id],
                [
                    'hospital_id' => $charge->hospital_id,
                    'patient_id' => $charge->patient_id,
                    'invoice_id' => $charge->invoice_id,
                    'patient_insurance_id' => $insurance->id,
                    'coverage_percentage_applied' => $percentCovered,
                    'eligible_amount' => $chargeAmount,
                    'covered_amount' => $coveredAmount,
                    'patient_responsibility' => $patientResponsibility,
                    'currency' => $charge->currency ?? 'TZS',
                    'status' => $allocationStatus,
                    'calculated_at' => now(),
                    'calculated_by' => $request->user()?->id,
                ],
            );

            return $this->result('authorized', $requestNo, null, $patientResponsibility, $item->id);
        }

        if ($item->isPendingApproval()) {
            return $this->result('pending', $requestNo, null, (float) $charge->net_amount, $item->id);
        }

        if ($item->isRejected()) {
            return $this->result('rejected', $requestNo, $item->rejection_reason, (float) $charge->net_amount, $item->id);
        }

        return $this->result('error', $requestNo, "NHIF returned unknown status: {$item->approval_status}", (float) $charge->net_amount, $item->id);
    }

    /**
     * @return array{status: string, nhif_request_no: string|null, rejection_reason: string|null, patient_responsibility: float, session_item_id: int|null}
     */
    private function result(string $status, ?string $requestNo, ?string $rejectionReason, float $patientResponsibility, ?int $sessionItemId): array
    {
        return [
            'status' => $status,
            'nhif_request_no' => $requestNo,
            'rejection_reason' => $rejectionReason,
            'patient_responsibility' => $patientResponsibility,
            'session_item_id' => $sessionItemId,
        ];
    }
}
