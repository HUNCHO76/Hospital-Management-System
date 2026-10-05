<?php

namespace App\Services\NHIF;

use App\Models\Charge;
use App\Models\NhifPreauthorization;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Services\AuditLogService;
use App\Services\InsuranceBillingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * NHIF preauthorization lifecycle (Phase 8, Step 69) — the "requested
 * service check": request NHIF's approval for a specific service before it
 * is performed, later sync NHIF's decision, and gate clinical services on
 * that decision only when it's actually required.
 *
 * A NHIF/network problem is never a reason to block a clinical action —
 * request()/syncStatus() never throw; they log and leave the record
 * unresolved, mirroring ChargeCaptureService's own catch-and-audit
 * precedent. assertPreauthorizedForSources() is the one method that does
 * throw, and only when a service is genuinely both NHIF-covered and marked
 * as requiring preauthorization.
 */
class NHIFPreauthorizationService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected NhifPriceResolutionService $priceResolution,
        protected AuditLogService $auditLog,
        protected InsuranceBillingService $insuranceBilling,
    ) {}

    /**
     * Send a preauthorization request to NHIF for the given record's source
     * service, storing the raw request/response and moving the record to
     * SUBMITTED on success. Never throws — a NHIF outage leaves the record
     * PENDING and is only recorded in the audit log.
     */
    public function request(NhifPreauthorization $preauth, Request $request): NhifPreauthorization
    {
        $payload = $this->buildRequestPayload($preauth);

        if ($payload === null) {
            $this->auditLog->record('preauthorization_request_skipped', $preauth, null, [
                'reason' => 'Skipped: No billable charge is associated, or a valid NHIF practitioner number is missing.',
            ], $request);

            return $preauth;
        }

        $result = $this->client->requestPreauthorizedServices($payload);

        $preauth->update([
            'request_data' => $payload,
            'response_data' => $result['data'] ?? null,
            'status' => $result['success']
                ? NhifPreauthorization::STATUS_SUBMITTED
                : $preauth->status,
            'submitted_at' => $result['success'] ? now() : $preauth->submitted_at,
        ]);

        $this->auditLog->record(
            $result['success'] ? 'preauthorization_requested' : 'preauthorization_request_failed',
            $preauth,
            null,
            ['success' => $result['success'], 'error' => $result['error'] ?? null],
            $request,
        );

        return $preauth->fresh();
    }

    /**
     * Pull NHIF's current decision for this preauthorization's authorization
     * number and update the record's status/approved_amount/rejection_reason
     * accordingly. Never throws — a NHIF outage leaves the record unchanged.
     */
    public function syncStatus(NhifPreauthorization $preauth, Request $request): NhifPreauthorization
    {
        if (! $preauth->authorization_no) {
            return $preauth;
        }

        $result = $this->client->getRequestServicesByAuthorizationNo($preauth->authorization_no);

        if (! $result['success']) {
            $this->auditLog->record('preauthorization_status_sync_failed', $preauth, null, [
                'error' => $result['error'] ?? null,
            ], $request);

            return $preauth;
        }

        $outcome = $this->resolveOutcome($result['data']);

        if ($outcome === null) {
            return $preauth;
        }

        $oldValues = $preauth->only(['status', 'approved_amount', 'rejection_reason']);

        $preauth->update([
            'status' => $outcome['status'],
            'approved_amount' => $outcome['approved_amount'],
            'rejection_reason' => $outcome['rejection_reason'],
            'response_data' => $result['data'],
            'resolved_at' => now(),
        ]);

        $this->auditLog->record('preauthorization_status_synced', $preauth, $oldValues, $preauth->only([
            'status', 'approved_amount', 'rejection_reason',
        ]), $request);

        return $preauth->fresh();
    }

    /**
     * Assert that every source in $sourceIds either doesn't require NHIF
     * preauthorization, or has an approved one. Only enforces for sources
     * that (a) have a billable charge, (b) whose mapped service is flagged
     * `requires_preauth` at this hospital, and (c) whose patient is actually
     * covered by an API-enabled (NHIF) insurance provider right now — a cash
     * patient, or one covered by a non-NHIF insurer, is never blocked here.
     *
     * @param  list<int>  $sourceIds
     *
     * @throws ValidationException
     */
    public function assertPreauthorizedForSources(string $sourceType, array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        $charges = Charge::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->whereNotNull('service_id')
            ->with('patient')
            ->get()
            ->keyBy('source_id');

        if ($charges->isEmpty()) {
            return;
        }

        $hospitalId = $charges->first()->hospital_id;
        $serviceIds = $charges->pluck('service_id')->unique()->values()->all();

        $servicesRequiringPreauth = NhifServiceMapping::query()
            ->where('hospital_id', $hospitalId)
            ->whereIn('service_id', $serviceIds)
            ->where('requires_preauth', true)
            ->where('is_active', true)
            ->pluck('service_id')
            ->flip();

        if ($servicesRequiringPreauth->isEmpty()) {
            return;
        }

        foreach ($sourceIds as $sourceId) {
            $charge = $charges->get($sourceId);

            if ($charge === null || ! $servicesRequiringPreauth->has($charge->service_id)) {
                continue;
            }

            if (! $this->patientHasActiveNhifCoverage($charge->patient)) {
                continue;
            }

            $approved = NhifPreauthorization::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', NhifPreauthorization::STATUS_APPROVED)
                ->exists();

            if (! $approved) {
                throw ValidationException::withMessages([
                    'nhif_preauthorization' => 'NHIF pre-authorization is required before this service can proceed.',
                ]);
            }
        }
    }

    protected function patientHasActiveNhifCoverage(?Patient $patient): bool
    {
        if ($patient === null) {
            return false;
        }

        $coverage = $this->insuranceBilling->primaryCoverage($patient);

        if ($coverage === null || ! $this->insuranceBilling->isCoverageValidOn($coverage, now())) {
            return false;
        }

        $coverage->loadMissing('insuranceScheme.insuranceProvider');

        return (bool) $coverage->insuranceScheme?->insuranceProvider?->api_enabled;
    }

    /**
     * Build the exact NHIF RequestServices payload for this preauthorization's
     * source charge. Returns null when there's nothing billable to request
     * preauthorization for yet.
     *
     * @return array<string, mixed>|null
     */
    protected function buildRequestPayload(NhifPreauthorization $preauth): ?array
    {
        $charge = Charge::query()
            ->where('source_type', $preauth->source_type)
            ->where('source_id', $preauth->source_id)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->whereNotNull('service_id')
            ->with(['patient', 'service', 'hospital'])
            ->first();

        if ($charge === null || $charge->patient === null || $charge->hospital === null) {
            return null;
        }

        $mapping = NhifServiceMapping::query()
            ->where('hospital_id', $charge->hospital_id)
            ->where('service_id', $charge->service_id)
            ->where('is_active', true)
            ->first();

        $patient = $charge->patient;
        $practitioner = null;
        if ($charge->encounter_id) {
            $practitioner = \App\Models\ClinicalEncounter::find($charge->encounter_id)?->practitioner;
        }

        if (! $practitioner || blank($practitioner->nhif_practitioner_no)) {
            $practitioner = Practitioner::query()
                ->whereHas('staff.user', fn ($q) => $q->whereKey($preauth->created_by))
                ->whereNotNull('nhif_practitioner_no')
                ->where('nhif_practitioner_no', '!=', '')
                ->first();
        }

        if (! $practitioner || blank($practitioner->nhif_practitioner_no)) {
            $practitioner = Practitioner::query()
                ->whereHas('staff', fn ($q) => $q->where('hospital_id', $charge->hospital_id))
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->whereNotNull('nhif_practitioner_no')
                ->where('nhif_practitioner_no', '!=', '')
                ->first();
        }

        $practitionerNo = $practitioner?->nhif_practitioner_no;

        if (blank($practitionerNo)) {
            return null; // Triggers 'preauthorization_request_skipped' audit log in request()
        }

        $insurance = $patient->activeNhifInsurance();
        $unitPrice = (float) $charge->unit_price;
        if ($insurance && $mapping?->nhif_item_code) {
            $resolved = $this->priceResolution->resolve($insurance, $mapping->nhif_item_code, $unitPrice);
            $unitPrice = $resolved['unit_price'];
        }

        return [
            'authorizationNo' => $preauth->authorization_no,
            'firstName' => $patient->first_name,
            'lastName' => $patient->last_name,
            'gender' => $patient->gender,
            'dateOfBirth' => optional($patient->date_of_birth)->toDateString(),
            'patientFileNo' => $insurance?->nhif_pf_number ?? $patient->patient_number,
            'clinicalNotes' => null,
            'practitionerNo' => $practitionerNo,
            'practitionersRemarks' => null,
            'telephoneNo' => $patient->phone,
            'facilityCode' => $charge->hospital->code,
            'diseases' => [],
            'requestedServices' => [[
                'itemCode' => $mapping?->nhif_item_code,
                'usage' => $charge->description ?? $charge->service_code_snapshot,
                'effectiveDate' => now()->toDateString(),
                'unitPrice' => $unitPrice,
                'endDate' => now()->toDateString(),
                'quantityRequested' => (int) $charge->quantity,
                'remarks' => null,
            ]],
        ];
    }

    /**
     * Map NHIF's GetRequestServicesByAuthorizationNo response for a single
     * requested item into this record's outcome — null when the response
     * doesn't (yet) contain a resolvable status.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: string, approved_amount: float|null, rejection_reason: string|null}|null
     */
    protected function resolveOutcome(array $data): ?array
    {
        $items = $data['requestedServices'] ?? $data['RequestedServices'] ?? $data['items'] ?? $data['Items'] ?? null;
        $item = is_array($items) ? ($items[0] ?? null) : null;
        $status = strtolower((string) ($item['status'] ?? $item['Status'] ?? $data['status'] ?? $data['Status'] ?? ''));

        if (str_contains($status, 'approve') || str_contains($status, 'issue')) {
            return [
                'status' => NhifPreauthorization::STATUS_APPROVED,
                'approved_amount' => isset($item['unitPrice']) ? (float) $item['unitPrice'] : null,
                'rejection_reason' => null,
            ];
        }

        if (str_contains($status, 'reject') || str_contains($status, 'declin')) {
            return [
                'status' => NhifPreauthorization::STATUS_REJECTED,
                'approved_amount' => null,
                'rejection_reason' => $item['rejectionDetails'] ?? $item['RejectionDetails'] ?? $data['message'] ?? 'Rejected by NHIF.',
            ];
        }

        return null;
    }
}
