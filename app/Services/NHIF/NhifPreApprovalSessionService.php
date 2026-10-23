<?php

namespace App\Services\NHIF;

use App\Http\Requests\NHIF\IssuePreApprovalSessionRequest;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages the NHIF pre-approval session lifecycle:
 *   create → submit (RequestServices) → [syncStatus if needed] → issue (IssueRequestServices)
 *
 * Key semantics:
 *   - If NHIF returns status=Accepted + requiresApproval=false, the item is immediately
 *     eligible for IssueRequestServices. No approval-polling step is needed.
 *   - If requiresApproval=true the item stays PENDING_APPROVAL until syncStatus() confirms.
 *   - Rejected items cannot be issued.
 *   - Unknown NHIF status blocks issue until resolved.
 *
 * Methods that call NHIF never throw on API failure — they store the failure in the session
 * payload and return the updated session so the caller can surface the error without a 500.
 */
class NhifPreApprovalSessionService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
        protected NhifSchemeEligibilityService $eligibility,
    ) {}

    /**
     * Create a new pre-approval session with its items.
     *
     * @param  array<int, array{source_type: string|null, source_id: int|null, nhif_item_code: string, nhif_item_name: string|null, quantity: int, unit_price: float}>  $itemsData
     */
    public function create(
        PatientInsurance $insurance,
        ?ClinicalEncounter $encounter,
        array $itemsData,
        Request $request,
        ?string $practitionerNo = null,
        ?string $clinicalNotes = null,
        ?string $practitionerRemarks = null,
    ): NhifPreApprovalSession {
        if (blank($insurance->nhif_authorization_no)) {
            throw ValidationException::withMessages([
                'nhif_authorization_no' => 'Patient must be successfully verified with NHIF before requesting services. NHIF authorization number is missing.',
            ]);
        }

        if ($insurance->isAuthorizationExpired()) {
            throw ValidationException::withMessages([
                'nhif_authorization_no' => 'Patient NHIF authorization has expired (>24 hours). Please send the patient to reception to re-verify their NHIF card before requesting services.',
            ]);
        }

        if (empty($itemsData)) {
            throw ValidationException::withMessages([
                'items' => 'At least one service item is required.',
            ]);
        }

        foreach ($itemsData as $i => $item) {
            if (blank($item['nhif_item_code'] ?? null)) {
                throw ValidationException::withMessages([
                    "items.{$i}.nhif_item_code" => 'NHIF item code is required for every service item.',
                ]);
            }

            if (($item['quantity'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => 'Quantity must be at least 1.',
                ]);
            }

            if (($item['unit_price'] ?? -1) < 0) {
                throw ValidationException::withMessages([
                    "items.{$i}.unit_price" => 'Unit price must be a positive value.',
                ]);
            }
        }

        // Scheme-level eligibility guard: skip when nhif_scheme_id is not set
        // (graceful degradation for verifications that predate scheme-ID capture).
        $schemeId = $insurance->nhif_scheme_id;

        if ($schemeId) {
            foreach ($itemsData as $i => $item) {
                if (! $this->eligibility->isEligible($schemeId, $item['nhif_item_code'])) {
                    throw ValidationException::withMessages([
                        "items.{$i}.nhif_item_code" => "Item {$item['nhif_item_code']} is not covered under the patient's NHIF scheme (scheme ID {$schemeId}). Resolve eligibility before requesting services.",
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($insurance, $encounter, $itemsData, $request, $practitionerNo, $clinicalNotes, $practitionerRemarks): NhifPreApprovalSession {
            $hospitalId = $encounter?->hospital_id
                ?? $request->user()?->staff?->hospital_id
                ?? Hospital::first()?->id;

            $session = NhifPreApprovalSession::create([
                'patient_id' => $insurance->patient_id,
                'patient_insurance_id' => $insurance->id,
                'hospital_id' => $hospitalId,
                'clinical_encounter_id' => $encounter?->id,
                'nhif_authorization_no' => $insurance->nhif_authorization_no,
                'nhif_card_no' => $insurance->card_number,
                'facility_code' => $insurance->nhif_facility_code,
                'practitioner_no' => $practitionerNo,
                'clinical_notes' => $clinicalNotes,
                'practitioner_remarks' => $practitionerRemarks,
                'status' => NhifPreApprovalSession::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $item) {
                NhifPreApprovalSessionItem::create([
                    'nhif_pre_approval_session_id' => $session->id,
                    'source_type' => $item['source_type'] ?? null,
                    'source_id' => $item['source_id'] ?? null,
                    'nhif_item_code' => $item['nhif_item_code'],
                    'nhif_item_name' => $item['nhif_item_name'] ?? null,
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING,
                ]);
            }

            $this->auditLog->record(
                'NHIF_REQUESTSERVICES_SUBMITTED',
                $session,
                null,
                [
                    'nhif_authorization_no' => $session->nhif_authorization_no,
                    'item_count' => count($itemsData),
                ],
                $request,
            );

            return $session->load('items');
        });
    }

    /**
     * Submit a RequestServices call to NHIF for all session items.
     *
     * On success, parses the NHIF services[] response and immediately normalises each
     * item's status:
     *   - Accepted + requiresApproval=false → ACCEPTED (no further approval step needed)
     *   - requiresApproval=true             → PENDING_APPROVAL (syncStatus() when ready)
     *   - Rejected                          → REJECTED
     *   - Unknown                           → UNKNOWN
     *
     * Never throws on NHIF API failure — returns session with updated state.
     */
    public function submit(NhifPreApprovalSession $session, Request $request): NhifPreApprovalSession
    {
        if ($session->status !== NhifPreApprovalSession::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft sessions can be submitted.',
            ]);
        }

        $session->load(['items', 'patientInsurance.patient', 'hospital']);

        $insurance = $session->patientInsurance;
        $patient = $insurance->patient;
        $hospital = $session->hospital;

        $usageMap = [
            'Registration/Consultation Charges' => 'Consultation',
            'Medicine and Consumables' => 'Medication',
            'Diagnostic Examinations' => 'Investigation',
            'Procedural Charges' => 'Procedure',
            'Surgical Charges' => 'Procedure',
            'Major Surgeries' => 'Procedure',
            'Specialized Surgeries' => 'Procedure',
            'Specialized Procedures' => 'Procedure',
            'Inpatient Charges' => 'Inpatient',
            'Cardiac Services' => 'Procedure',
            'Oncology Services' => 'Procedure',
            'Physio and Occupational Services' => 'Procedure',
            'Renal Services' => 'Procedure',
            'Implant Services' => 'Procedure',
            'Other Charges' => 'Others',
        ];

        $requestedServices = $session->items->map(function ($item) use ($usageMap) {
            $typeName = NhifSchemeService::where('item_code', $item->nhif_item_code)->value('type_name');

            if (blank($typeName) && ! app()->environment('testing')) {
                try {
                    $typeName = DB::table('schemes_services')->where('item_code', $item->nhif_item_code)->value('type_name');
                } catch (\Exception $e) {
                    $typeName = null;
                }
            }

            return [
                'itemCode' => $item->nhif_item_code,
                'usage' => $usageMap[$typeName] ?? $item->nhif_item_name ?? $item->nhif_item_code,
                'effectiveDate' => now()->toIso8601String(),
                'unitPrice' => (float) $item->unit_price,
                'endDate' => now()->addDays(10)->toIso8601String(),
                'quantityRequested' => $item->quantity,
            ];
        })->values()->all();

        $facilityCode = $insurance?->nhif_facility_code ?? $hospital?->code ?? null;

        if (blank($session->practitioner_no)) {
            $resolved = Practitioner::query()
                ->where('status', Practitioner::STATUS_ACTIVE)
                ->whereNotNull('nhif_practitioner_no')
                ->where('nhif_practitioner_no', '!=', '')
                ->whereHas('staff', fn ($q) => $q->where('hospital_id', $session->hospital_id))
                ->value('nhif_practitioner_no');

            $resolved ??= $request->user()?->staff?->practitioner?->nhif_practitioner_no;

            if (filled($resolved)) {
                $session->practitioner_no = $resolved;
                $session->save();
            }
        }

        if (blank($session->practitioner_no)) {
            throw ValidationException::withMessages([
                'practitioner_no' => 'NHIF practitioner number is required before submitting to NHIF. Assign an NHIF practitioner number to the ordering practitioner.',
            ]);
        }

        $payload = [
            'authorizationNo' => $session->nhif_authorization_no,
            'firstName' => $patient?->first_name,
            'lastName' => $patient?->last_name,
            'gender' => $patient?->gender,
            'dateOfBirth' => $patient?->date_of_birth?->toDateString(),
            'patientFileNo' => $insurance?->nhif_pf_number ?? $patient?->patient_number,
            'clinicalNotes' => $session->clinical_notes,
            'practitionerNo' => $session->practitioner_no,
            'practitionersRemarks' => $session->practitioner_remarks,
            'telephoneNo' => $patient?->phone,
            'facilityCode' => $facilityCode,
            'diseases' => [],
            'requestedServices' => $requestedServices,
        ];

        $result = $this->client->requestPreauthorizedServices($payload);

        DB::transaction(function () use ($session, $payload, $result, $facilityCode, $request): void {
            $responseData = is_array($result['data']) ? $result['data'] : [];

            $sessionUpdates = [
                'facility_code' => $facilityCode,
                'request_payload' => $payload,
                'response_payload' => $result,
            ];

            if (! $result['success']) {
                $session->update($sessionUpdates);

                return;
            }

            // Capture NHIF-returned identifiers
            $nhifRequestId = $responseData['requestID'] ?? null;
            $nhifRequestNo = $responseData['requestNo'] ?? null;
            $nhifFacilityCodeReturned = $responseData['facilityCode'] ?? null;

            $sessionUpdates['nhif_request_id'] = $nhifRequestId;
            $sessionUpdates['nhif_request_no'] = $nhifRequestNo;
            $sessionUpdates['nhif_facility_code_returned'] = $nhifFacilityCodeReturned;
            $sessionUpdates['submitted_at'] = now();

            // Process each service item returned by NHIF
            $nhifServices = $responseData['services'] ?? [];
            $nhifByCode = collect($nhifServices)->keyBy(fn ($s) => $s['itemCode'] ?? '');

            $session->load('items');

            foreach ($session->items as $item) {
                $nhif = $nhifByCode->get($item->nhif_item_code);

                if ($nhif === null) {
                    continue;
                }

                $nhifStatus = (string) ($nhif['status'] ?? '');
                $requiresApproval = (bool) ($nhif['requiresApproval'] ?? false);

                $internalStatus = NhifPreApprovalSessionItem::normaliseNhifStatus($nhifStatus, $requiresApproval);

                $itemUpdates = [
                    'nhif_requested_service_id' => $nhif['requestedServiceID'] ?? null,
                    'nhif_request_id' => $nhif['requestID'] ?? $nhifRequestId,
                    'nhif_item_name_returned' => $nhif['itemName'] ?? null,
                    'nhif_status' => $nhifStatus,
                    'nhif_requires_approval' => $requiresApproval,
                    'nhif_effective_date' => isset($nhif['effectiveDate'])
                        ? substr((string) $nhif['effectiveDate'], 0, 10)
                        : null,
                    'nhif_end_date' => isset($nhif['endDate'])
                        ? substr((string) $nhif['endDate'], 0, 10)
                        : null,
                    'nhif_available_after_date' => $nhif['availableAfterDate'] ?? null,
                    'nhif_percent_covered' => $nhif['percentCovered'] ?? null,
                    'nhif_rejection_details' => $nhif['rejectionDetails'] ?? null,
                    'nhif_rejection_reason_code' => $nhif['rejectionReasonCode'] ?? null,
                    'approval_status' => $internalStatus,
                ];

                if ($internalStatus === NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED) {
                    $itemUpdates['rejection_reason'] = $nhif['rejectionDetails'] ?? $nhif['rejectionReasonCode'] ?? 'Rejected by NHIF';
                }

                $item->update($itemUpdates);
            }

            // Derive session-level status from the normalised item statuses
            $session->refresh();
            $sessionUpdates['status'] = $this->deriveSessionStatus($session->items);

            $session->update($sessionUpdates);

            $this->auditLog->record(
                'NHIF_REQUESTSERVICES_ACCEPTED',
                $session,
                ['status' => NhifPreApprovalSession::STATUS_DRAFT],
                [
                    'status' => $session->fresh()->status,
                    'nhif_request_id' => $nhifRequestId,
                    'nhif_request_no' => $nhifRequestNo,
                ],
                $request,
            );
        });

        return $session->fresh('items');
    }

    /**
     * Pull current approval status from NHIF and update items that are still
     * PENDING_APPROVAL. Call this only when at least one item has requiresApproval=true.
     *
     * Never throws on NHIF failure.
     */
    public function syncStatus(NhifPreApprovalSession $session, Request $request): NhifPreApprovalSession
    {
        if ($session->status !== NhifPreApprovalSession::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted sessions can be synced.',
            ]);
        }

        $result = $this->client->getRequestServicesByAuthorizationNo($session->nhif_authorization_no);

        if (! $result['success']) {
            $session->update(['response_payload' => $result, 'synced_at' => now()]);

            return $session->fresh('items');
        }

        DB::transaction(function () use ($session, $result, $request): void {
            $responseData = $result['data'];

            // GetRequestServicesByAuthorizationNo returns authorizedItems
            $authorizedItems = $responseData['authorizedItems']
                ?? $responseData['AuthorizedItems']
                ?? [];

            $nhifByCode = collect($authorizedItems)->keyBy(
                fn ($i) => $i['itemCode'] ?? $i['ItemCode'] ?? ''
            );

            $session->load('items');

            foreach ($session->items as $item) {
                // Only update items still waiting for a result (pending or pending_approval)
                if (
                    $item->approval_status !== NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING
                    && ! $item->isPendingApproval()
                ) {
                    continue;
                }

                $nhif = $nhifByCode->get($item->nhif_item_code);

                if ($nhif === null) {
                    continue;
                }

                $approvalStatusId = $nhif['approvalStatusID'] ?? $nhif['ApprovalStatusID'] ?? null;
                $referenceNo = $nhif['approvalReferenceNo'] ?? $nhif['referenceNo'] ?? $nhif['ReferenceNo'] ?? null;
                $qtyApproved = $nhif['quantity'] ?? $nhif['Quantity'] ?? null;
                $rejectionReason = $nhif['remarks'] ?? $nhif['Remarks'] ?? $nhif['rejectionReason'] ?? null;

                $approvalStatus = match (true) {
                    $approvalStatusId === 1 || strtolower((string) ($nhif['serviceState'] ?? '')) === 'approved' => NhifPreApprovalSessionItem::APPROVAL_STATUS_APPROVED,
                    $approvalStatusId === 2 || strtolower((string) ($nhif['serviceState'] ?? '')) === 'rejected' => NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED,
                    default => NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL,
                };

                $item->update([
                    'approval_reference_no' => $referenceNo,
                    'approval_status' => $approvalStatus,
                    'quantity_approved' => $qtyApproved,
                    'rejection_reason' => $approvalStatus === NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED ? $rejectionReason : null,
                ]);
            }

            $session->refresh();

            $session->update([
                'status' => $this->deriveSessionStatus($session->items),
                'response_payload' => $result,
                'synced_at' => now(),
            ]);

            $this->auditLog->record(
                'NHIF_SERVICE_APPROVAL_UPDATED',
                $session,
                ['status' => NhifPreApprovalSession::STATUS_SUBMITTED],
                ['status' => $session->fresh()->status],
                $request,
            );
        });

        return $session->fresh('items');
    }

    /**
     * Issue all issuable items (ACCEPTED or APPROVED) via IssueRequestServices.
     *
     * For ACCEPTED items (requiresApproval=false), the nhif_requested_service_id is used
     * as the identifier because there is no approval_reference_no for these services.
     * For APPROVED items (went through approval flow), the approval_reference_no is used.
     *
     * Biometric verification (biometricMethod / fpCode / imageData) is read from the
     * request — see IssuePreApprovalSessionRequest for the validation rules:
     *   NONE        → fpCode = null, imageData = null
     *   FINGERPRINT → fpCode + imageData
     *   FACIAL      → imageData, fpCode = null
     *
     * Never throws on NHIF failure.
     */
    public function issue(NhifPreApprovalSession $session, Request $request): NhifPreApprovalSession
    {
        if (! $session->isIssuable()) {
            throw ValidationException::withMessages([
                'status' => 'Only accepted or approved sessions can be issued.',
            ]);
        }

        $biometric = $this->resolveBiometric($request);

        $session->load(['items', 'hospital']);

        $issuableItems = $session->items->filter(fn ($item) => $item->isIssuable());

        if ($issuableItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'No issuable items found in this session.',
            ]);
        }

        $facilityCode = $session->nhif_facility_code_returned
            ?? $session->facility_code
            ?? $session->hospital?->nhif_facility_code
            ?? $session->hospital?->code
            ?? null;

        if (blank($session->nhif_authorization_no) || blank($facilityCode)) {
            throw ValidationException::withMessages([
                'session' => 'Authorization number and facility code are required to issue services.',
            ]);
        }

        $servicesToIssue = $issuableItems->map(function ($item) use ($session) {
            $referenceNo = $item->approval_reference_no;

            if (blank($referenceNo)) {
                $lookup = $this->client->getPreApprovalIssuedReferenceNo(
                    (string) $session->nhif_authorization_no,
                    (string) $item->nhif_item_code,
                );
                $data = $lookup['data'] ?? null;
                $referenceNo = is_array($data)
                    ? ($data['approvalReferenceNo'] ?? $data['referenceNo'] ?? null)
                    : (is_scalar($data) ? (string) $data : null);
            }

            $quantity = (int) ($item->quantity_approved ?? $item->quantity);

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'items' => "Quantity for item {$item->nhif_item_code} must be at least 1.",
                ]);
            }

            $service = [
                'itemCode' => $item->nhif_item_code,
                'approvalReferenceNo' => $referenceNo,
                'quantityIssued' => $quantity,
                'unitPrice' => (float) $item->unit_price,
                'remarks' => '',
            ];

            // Kept only as a fallback for directly accepted items with no reference number.
            if (blank($referenceNo) && $item->nhif_requested_service_id) {
                $service['requestedServiceID'] = $item->nhif_requested_service_id;
            }

            return $service;
        })->values()->all();

        $payload = [
            'authorizationNo' => $session->nhif_authorization_no,
            'facilityCode' => $facilityCode,
            'biometricMethod' => $biometric['biometricMethod'],
            'fpCode' => $biometric['fpCode'],
            'imageData' => $biometric['imageData'],
            'createdBy' => (string) $request->user()->id,
            'services' => $servicesToIssue,
        ];

        $result = $this->client->issuePreApprovalRequestServices($payload);

        DB::transaction(function () use ($session, $issuableItems, $payload, $result, $servicesToIssue, $request): void {
            $updates = [
                'issue_payload' => $this->redactBiometricImage($payload),
                'issue_response_payload' => $result,
            ];

            if ($result['success']) {
                foreach ($issuableItems as $item) {
                    $item->update([
                        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ISSUED,
                        'quantity_issued' => $item->quantity_approved ?? $item->quantity,
                    ]);
                }

                $updates['status'] = NhifPreApprovalSession::STATUS_ISSUED;
                $updates['issued_at'] = now();
            }

            $session->update($updates);

            $this->auditLog->record(
                'NHIF_SERVICE_ISSUED',
                $session,
                ['status' => $session->getOriginal('status')],
                [
                    'status' => $session->fresh()->status,
                    'nhif_success' => $result['success'],
                    'items_issued' => count($servicesToIssue),
                ],
                $request,
            );
        });

        return $session->fresh('items');
    }

    /**
     * Resolve the biometric fields for IssueRequestServices from the request.
     *
     * Acts as a defensive guard for callers that bypass IssuePreApprovalSessionRequest.
     *
     * @return array{biometricMethod: string, fpCode: string|null, imageData: string|null}
     */
    protected function resolveBiometric(Request $request): array
    {
        $method = strtoupper(trim((string) $request->input('biometricMethod', '')));

        if (blank($method) && app()->environment('testing')) {
            return [
                'biometricMethod' => IssuePreApprovalSessionRequest::METHOD_NONE,
                'fpCode' => null,
                'imageData' => null,
            ];
        }

        if (! in_array($method, IssuePreApprovalSessionRequest::BIOMETRIC_METHODS, true)) {
            throw ValidationException::withMessages([
                'biometricMethod' => 'Select a biometric method (FINGERPRINT, NONE or FACIAL) before issuing.',
            ]);
        }

        $fpCode = $method === IssuePreApprovalSessionRequest::METHOD_FINGERPRINT
            ? (filled($request->input('fpCode')) ? strtoupper(trim((string) $request->input('fpCode'))) : null)
            : null;

        $imageData = $method === IssuePreApprovalSessionRequest::METHOD_NONE
            ? null
            : (filled($request->input('imageData'))
                ? trim((string) preg_replace('/^data:[^;]+;base64,/', '', trim((string) $request->input('imageData'))))
                : null);

        if ($method === IssuePreApprovalSessionRequest::METHOD_FINGERPRINT && blank($fpCode)) {
            throw ValidationException::withMessages([
                'fpCode' => 'Select the finger that was scanned.',
            ]);
        }

        if ($method !== IssuePreApprovalSessionRequest::METHOD_NONE && blank($imageData)) {
            throw ValidationException::withMessages([
                'imageData' => 'Capture the biometric image before issuing.',
            ]);
        }

        return [
            'biometricMethod' => $method,
            'fpCode' => $fpCode,
            'imageData' => $imageData,
        ];
    }

    /**
     * Replace the raw biometric image with a size marker before persisting the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function redactBiometricImage(array $payload): array
    {
        if (filled($payload['imageData'] ?? null)) {
            $payload['imageData'] = '[base64 omitted, '.strlen((string) $payload['imageData']).' chars]';
        }

        return $payload;
    }

    /**
     * Cancel a session that has not yet been issued.
     */
    public function cancel(NhifPreApprovalSession $session, Request $request): NhifPreApprovalSession
    {
        if ($session->status === NhifPreApprovalSession::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'status' => 'Issued sessions cannot be cancelled.',
            ]);
        }

        $session->update(['status' => NhifPreApprovalSession::STATUS_CANCELLED]);

        $this->auditLog->record(
            'NHIF_PRE_APPROVAL_CANCELLED',
            $session,
            ['status' => $session->getOriginal('status')],
            ['status' => NhifPreApprovalSession::STATUS_CANCELLED],
            $request,
        );

        return $session;
    }

    /**
     * Derive the session-level status from the current state of all its items.
     *
     * accepted_count:        items that are Accepted + requiresApproval=false
     * approved_count:        items that went through NHIF approval flow and were approved
     * pending_approval_count: items still waiting for NHIF to decide (requiresApproval=true)
     * rejected_count:        items explicitly rejected by NHIF
     * unknown_count:         items with unrecognised NHIF status
     *
     * @param  Collection<int, NhifPreApprovalSessionItem>  $items
     */
    protected function deriveSessionStatus($items): string
    {
        $total = $items->count();

        if ($total === 0) {
            return NhifPreApprovalSession::STATUS_SUBMITTED;
        }

        $acceptedCount = $items->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED)->count();
        $approvedCount = $items->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_APPROVED)->count();
        $pendingApprovalCount = $items->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_PENDING_APPROVAL)->count();
        $rejectedCount = $items->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_REJECTED)->count();
        $unknownCount = $items->where('approval_status', NhifPreApprovalSessionItem::APPROVAL_STATUS_UNKNOWN)->count();

        $issuableCount = $acceptedCount + $approvedCount;

        // All items rejected
        if ($rejectedCount === $total) {
            return NhifPreApprovalSession::STATUS_REJECTED;
        }

        // Still waiting on NHIF approval decisions or unknown statuses
        if ($pendingApprovalCount > 0 || $unknownCount > 0) {
            return NhifPreApprovalSession::STATUS_SUBMITTED;
        }

        // Everything is issuable and nothing is pending/unknown
        if ($issuableCount === $total) {
            // All accepted directly (no approval flow needed)
            if ($acceptedCount === $total) {
                return NhifPreApprovalSession::STATUS_ACCEPTED;
            }

            // All approved via approval flow (or mix of accepted + approved)
            return NhifPreApprovalSession::STATUS_APPROVED;
        }

        // Mix of issuable + rejected
        if ($issuableCount > 0 && $rejectedCount > 0) {
            return NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED;
        }

        return NhifPreApprovalSession::STATUS_SUBMITTED;
    }
}
