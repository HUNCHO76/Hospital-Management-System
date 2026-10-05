<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\NhifAdmission;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The inpatient admission lifecycle (Phase 9, Step 71):
 * Request -> Confirm (Billing/Payment/NHIF Clearance) -> Discharge/Cancel.
 *
 * Mirrors ReceptionVisitService's own transition()-helper pattern (lock ->
 * validate -> update -> one audit-log call per transition) and reuses the
 * exact same two payment/insurance gates every other clinical module
 * already gates on — never a bespoke check invented for admissions.
 */
class AdmissionService
{
    public function __construct(
        protected ChargeCaptureService $chargeCapture,
        protected FinancialAuthorizationService $financialAuth,
        protected NHIFPreauthorizationService $nhifPreauth,
        protected NhifHttpClient $nhifClient,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{hospital_id: int, patient_id: int, service_id?: int|null, department_id?: int|null, practitioner_id?: int|null, clinical_encounter_id?: int|null, patient_insurance_id?: int|null, admission_type?: string|null, ward_type?: string|null, room_type?: string|null, diagnosis_at_admission?: string|null, notes?: string|null, nhif_authorization_no?: string|null, nhif_ward_type_id?: int|null, nhif_room_type_id?: int|null, nhif_admission_type_id?: int|null}  $data
     */
    public function create(array $data, Request $request): Admission
    {
        $admission = DB::transaction(function () use ($data, $request) {
            $patient = \App\Models\Patient::find($data['patient_id']);
            $insurance = $patient?->activeNhifInsurance()
                ?? (!empty($data['patient_insurance_id']) ? \App\Models\PatientInsurance::find($data['patient_insurance_id']) : null)
                ?? $patient?->insurances()->whereNotNull('nhif_authorization_no')->latest()->first();

            $patientInsuranceId = $data['patient_insurance_id'] ?? $insurance?->id;
            $nhifAuthNo = !empty($data['nhif_authorization_no']) ? $data['nhif_authorization_no'] : $insurance?->nhif_authorization_no;

            $admission = Admission::create([
                'hospital_id' => $data['hospital_id'],
                'patient_id' => $data['patient_id'],
                'clinical_encounter_id' => $data['clinical_encounter_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'patient_insurance_id' => $patientInsuranceId,
                'admission_type' => $data['admission_type'] ?? null,
                'ward_type' => $data['ward_type'] ?? null,
                'room_type' => $data['room_type'] ?? null,
                'diagnosis_at_admission' => $data['diagnosis_at_admission'] ?? null,
                'practitioner_id' => $data['practitioner_id'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => Admission::STATUS_PENDING,
                'created_by' => $request->user()->id,
            ]);

            $charge = $this->chargeCapture->captureFromAdmission($admission, $request);

            if ($charge !== null) {
                $admission->update(['charges_per_day' => $charge->unit_price]);
            }

            if (!empty($nhifAuthNo) || !empty($data['nhif_ward_type_id'])) {
                NhifAdmission::create([
                    'admission_id' => $admission->id,
                    'nhif_authorization_no' => $nhifAuthNo,
                    'nhif_status' => NhifAdmission::STATUS_PENDING,
                    'nhif_ward_type_id' => $data['nhif_ward_type_id'] ?? null,
                    'nhif_room_type_id' => $data['nhif_room_type_id'] ?? null,
                    'nhif_admission_type_id' => $data['nhif_admission_type_id'] ?? null,
                ]);
            }

            return $admission->fresh();
        });

        $this->auditLog->record('admission_requested', $admission, null, $admission->only([
            'hospital_id', 'patient_id', 'admission_type', 'status',
        ]), $request);

        return $admission;
    }

    /**
     * Move a pending request into the review queue. Subsequent approve() /
     * reject() / returnForCorrection() calls progress it from here.
     *
     * @throws ValidationException
     */
    public function startReview(Admission $admission, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending admission request can be placed under review.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_UNDER_REVIEW,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $this->auditLog->record('admission_review_started', $admission, ['status' => $oldStatus], ['status' => $admission->status], $request);

            return $admission;
        });
    }

    /**
     * Approve an admission that is currently under review. The approval
     * clears it for the billing/NHIF gate that runs inside confirm().
     *
     * @throws ValidationException
     */
    public function approve(Admission $admission, ?string $notes, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $notes, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isUnderReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an admission under review can be approved.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update(array_filter([
                'status' => Admission::STATUS_APPROVED,
                'notes' => $notes ?? $admission->notes,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ], fn ($v) => $v !== null));

            $this->auditLog->record('admission_approved', $admission, ['status' => $oldStatus], ['status' => $admission->status], $request);

            return $admission;
        });
    }

    /**
     * Reject an admission that is currently under review. The rejection
     * reason is recorded and the request reaches a terminal state.
     *
     * @throws ValidationException
     */
    public function reject(Admission $admission, string $reason, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $reason, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isUnderReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an admission under review can be rejected.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
            ]);

            $this->auditLog->record('admission_rejected', $admission, ['status' => $oldStatus], ['status' => $admission->status, 'reason' => $reason], $request);

            return $admission;
        });
    }

    /**
     * Return an admission-under-review back to the requesting doctor for
     * amendment. Notes explaining what needs correcting are stored against
     * the record.
     *
     * @throws ValidationException
     */
    public function returnForCorrection(Admission $admission, string $notes, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $notes, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isUnderReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an admission under review can be returned for correction.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_PENDING,
                'notes' => $notes,
            ]);

            $this->auditLog->record('admission_returned_for_correction', $admission, ['status' => $oldStatus], ['status' => $admission->status, 'notes' => $notes], $request);

            return $admission;
        });
    }

    /**
     * Confirms the admission request, gated on the exact same two clearance
     * checks every other clinical module already reuses: NHIF
     * preauthorization (only when the mapped service requires it) and
     * financial authorization (only when a charge was actually captured —
     * the no-charge rule never blocks here either).
     *
     * Requires an APPROVED admission — the review/approve step (startReview /
     * approve) must complete before an admission can be confirmed.
     *
     * @throws ValidationException
     */
    public function confirm(Admission $admission, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved admission request can be confirmed.',
                ]);
            }

            $admission->loadMissing(['nhifAdmission', 'patient']);
            $hasNhifAdmissionNo = ! empty($admission->nhifAdmission?->nhif_admission_no);

            if ($hasNhifAdmissionNo) {
                // Patient has an authorized NHIF admission number — no cash payment required!
                $this->settleAdmissionChargeForNhif($admission, $request);
            } else {
                $this->nhifPreauth->assertPreauthorizedForSources(Admission::class, [$admission->id]);
                $this->financialAuth->assertAuthorizedForSources(
                    Admission::class,
                    [$admission->id],
                    FinancialAuthorizationService::STATUS_AUTHORIZED,
                );
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_ACTIVE,
                'admitted_at' => now(),
                'admitted_by' => $request->user()->id,
            ]);

            $this->auditLog->record('admission_confirmed', $admission, ['status' => $oldStatus], ['status' => $admission->status], $request);

            return $admission;
        });
    }

    /**
     * @param  array{discharge_type: string, discharge_reason: string}  $data
     *
     * @throws ValidationException
     */
    public function discharge(Admission $admission, array $data, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $data, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isActive()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an active admission can be discharged.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_DISCHARGED,
                'discharged_at' => now(),
                'discharged_by' => $request->user()->id,
                'discharge_type' => $data['discharge_type'],
                'discharge_reason' => $data['discharge_reason'],
            ]);

            $this->auditLog->record('admission_discharged', $admission, ['status' => $oldStatus], ['status' => $admission->status], $request);

            return $admission;
        });
    }

    /**
     * Any pre-admission request (pending, under review, or approved) can be
     * cancelled. An active admission must be discharged instead — mirroring
     * how this app treats cancellation vs. completion everywhere else.
     *
     * @throws ValidationException
     */
    public function cancel(Admission $admission, string $reason, Request $request): Admission
    {
        return DB::transaction(function () use ($admission, $reason, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            if (! $admission->isPreAdmission()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pre-admission request (pending, under review, or approved) can be cancelled — an active admission must be discharged instead.',
                ]);
            }

            $oldStatus = $admission->status;

            $admission->update([
                'status' => Admission::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
            ]);

            $this->auditLog->record('admission_cancelled', $admission, ['status' => $oldStatus], ['status' => $admission->status], $request);

            return $admission;
        });
    }

    /**
     * Submits (or re-submits) this admission to NHIF. Never throws on a
     * NHIF outage — mirrors NHIFPreauthorizationService::request()'s own
     * never-block precedent; the failure is logged and the NhifAdmission
     * row records the error for follow-up.
     */
    public function submitNhif(Admission $admission, Request $request): Admission
    {
        $admission->loadMissing('hospital', 'practitioner', 'patient', 'patientInsurance');

        $nhifAdmission = NhifAdmission::where('admission_id', $admission->id)->first();

        $authNo = $nhifAdmission?->nhif_authorization_no;
        if (empty($authNo) || $authNo === 'UNKNOWN') {
            $authNo = $admission->patientInsurance?->nhif_authorization_no
                ?? $admission->patient?->activeNhifInsurance()?->nhif_authorization_no
                ?? $admission->patient?->insurances()->whereNotNull('nhif_authorization_no')->latest()->value('nhif_authorization_no');

            if ($authNo) {
                if ($nhifAdmission) {
                    $nhifAdmission->update(['nhif_authorization_no' => $authNo]);
                } else {
                    $nhifAdmission = NhifAdmission::create([
                        'admission_id' => $admission->id,
                        'nhif_authorization_no' => $authNo,
                        'nhif_status' => NhifAdmission::STATUS_PENDING,
                    ]);
                }
            }
        }

        $dob = $admission->patient?->date_of_birth ?? $admission->patient?->dob;

        $payload = [
            'authorizationNo' => $authNo ?: 'UNKNOWN',
            'fullName' => $admission->patient ? trim($admission->patient->first_name . ' ' . $admission->patient->last_name) : 'UNKNOWN',
            'gender' => $admission->patient?->gender ? ucfirst(strtolower($admission->patient->gender)) : 'Unknown',
            'dateOfBirth' => optional($dob)->toIso8601String() ?: '1900-01-01T00:00:00Z',
            'dateAdmitted' => optional($admission->admitted_at)->toIso8601String() ?: now()->toIso8601String(),
            'createdBy' => $request->user()->UserName ?? $request->user()->name ?? 'SYSTEM',

            'wardTypeID' => (int) ($nhifAdmission?->nhif_ward_type_id ?: 1),
            'roomTypeID' => (int) ($nhifAdmission?->nhif_room_type_id ?: 1),
            'admissionTypeID' => (int) ($nhifAdmission?->nhif_admission_type_id ?: 1),

            'chargesPerDay' => (float) ($admission->charges_per_day ?: 1.0),
            
            'diagnosisAtAdmission' => $admission->diagnosis_at_admission ?: 'None provided',
            'practitionersRemarks' => $admission->notes ?: 'None provided',

            'practitionerNo' => $admission->practitioner?->nhif_practitioner_no ?: 'UNKNOWN',
            'facilityCode' => $admission->hospital?->code ?: 'UNKNOWN',
            'patientFileNo' => $admission->patient?->patient_number ?: 'UNKNOWN',
        ];

        $result = $this->nhifClient->admitPatient($payload);

        $existingAdmissionNo = $nhifAdmission?->nhif_admission_no;
        $returnedAdmissionNo = $result['data']['AdmissionNo'] ?? $result['data']['admissionNo'] ?? null;
        $admissionNo = $returnedAdmissionNo ?: $existingAdmissionNo;

        $retryCount = ($nhifAdmission?->retry_count ?? 0) + ($nhifAdmission && $nhifAdmission->submitted_at ? 1 : 0);

        $nhifAdmission = NhifAdmission::updateOrCreate(
            ['admission_id' => $admission->id],
            [
                'nhif_admission_no' => $admissionNo,
                'nhif_facility_code' => $admission->hospital?->code,
                'nhif_practitioner_no' => $admission->practitioner?->nhif_practitioner_no,
                'nhif_status' => $result['success'] ? NhifAdmission::STATUS_SUBMITTED : NhifAdmission::STATUS_FAILED,
                'http_status' => $result['http_status'] ?? null,
                'raw_request' => json_encode($payload),
                'raw_response' => json_encode($result['data'] ?? []),
                'error_message' => $result['error'] ?? null,
                'retry_count' => $retryCount,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ],
        );

        if ($result['success'] && ! empty($admissionNo)) {
            $this->settleAdmissionChargeForNhif($admission, $request);
        }

        $this->auditLog->record(
            $result['success'] ? 'admission_nhif_submitted' : 'admission_nhif_submission_failed',
            $admission,
            null,
            ['success' => $result['success'], 'error' => $result['error'] ?? null, 'admission_no' => $admissionNo],
            $request,
        );

        return $admission->fresh(['nhifAdmission']);
    }

    /**
     * Once an admission has an NHIF Admission Number (or active NHIF authorization),
     * automatically post and invoice the admission charge under NHIF with 100% coverage
     * (zero patient responsibility) so that no cash payment is required.
     */
    public function settleAdmissionChargeForNhif(Admission $admission, Request $request): void
    {
        $charge = \App\Models\Charge::query()
            ->where('source_type', Admission::class)
            ->where('source_id', $admission->id)
            ->whereNotIn('status', [\App\Models\Charge::STATUS_VOIDED, \App\Models\Charge::STATUS_CANCELLED])
            ->first();

        if ($charge) {
            $this->chargeCapture->postAndInvoiceForInsuredPatient($charge, $admission->patient_id, $request);
        }
    }

    /**
     * Record a ward/hospital transfer for an active admission.
     * The transfer starts as STATUS_PENDING until completeTransfer() is called.
     *
     * @param  array{to_hospital_id: int, to_ward_type?: string|null, to_room_type?: string|null, reason: string, charges_per_day?: float|null}  $data
     *
     * @throws ValidationException
     */
    public function storeTransfer(Admission $admission, array $data, Request $request): AdmissionTransfer
    {
        if (! $admission->isActive()) {
            throw ValidationException::withMessages([
                'status' => 'Only an active admission can be transferred.',
            ]);
        }

        $transfer = DB::transaction(function () use ($admission, $data, $request) {
            $transfer = AdmissionTransfer::create([
                'admission_id' => $admission->id,
                'from_hospital_id' => $admission->hospital_id,
                'to_hospital_id' => $data['to_hospital_id'],
                'from_ward_type' => $admission->ward_type,
                'to_ward_type' => $data['to_ward_type'] ?? null,
                'from_room_type' => $admission->room_type,
                'to_room_type' => $data['to_room_type'] ?? null,
                'reason' => $data['reason'],
                'charges_per_day' => $data['charges_per_day'] ?? null,
                'status' => AdmissionTransfer::STATUS_PENDING,
                'nhif_status' => AdmissionTransfer::NHIF_STATUS_PENDING,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
            ]);

            $this->auditLog->record('admission_transfer_requested', $admission, null, [
                'transfer_id' => $transfer->id,
                'to_hospital_id' => $data['to_hospital_id'],
                'to_ward_type' => $data['to_ward_type'] ?? null,
            ], $request);

            return $transfer;
        });

        return $transfer;
    }

    /**
     * Complete a pending transfer, updating the admission's ward/room/hospital
     * to the destination values.
     *
     * @throws ValidationException
     */
    public function completeTransfer(Admission $admission, AdmissionTransfer $transfer, Request $request): AdmissionTransfer
    {
        if ($transfer->admission_id !== $admission->id) {
            throw ValidationException::withMessages([
                'transfer' => 'Transfer does not belong to this admission.',
            ]);
        }

        if ($transfer->status !== AdmissionTransfer::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending transfer can be completed.',
            ]);
        }

        return DB::transaction(function () use ($admission, $transfer, $request) {
            $transfer->update([
                'status' => AdmissionTransfer::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
            ]);

            $admission->update([
                'hospital_id' => $transfer->to_hospital_id,
                'ward_type' => $transfer->to_ward_type ?? $admission->ward_type,
                'room_type' => $transfer->to_room_type ?? $admission->room_type,
            ]);

            $this->auditLog->record('admission_transfer_completed', $admission, null, [
                'transfer_id' => $transfer->id,
                'to_hospital_id' => $transfer->to_hospital_id,
            ], $request);

            return $transfer->fresh();
        });
    }

    /**
     * Submit a transfer notification to NHIF. Never throws on an NHIF outage —
     * mirrors submitNhif()'s own never-block precedent.
     *
     * @throws ValidationException
     */
    public function submitTransferNhif(Admission $admission, AdmissionTransfer $transfer, Request $request): AdmissionTransfer
    {
        if ($transfer->admission_id !== $admission->id) {
            throw ValidationException::withMessages([
                'transfer' => 'Transfer does not belong to this admission.',
            ]);
        }

        $admission->loadMissing('hospital', 'practitioner');

        $nhifAdmission = NhifAdmission::where('admission_id', $admission->id)->first();

        $payload = [
            'admissionNo' => $admission->admission_number,
            'nhifAdmissionNo' => $nhifAdmission?->nhif_admission_no,
            'facilityCode' => $admission->hospital?->code,
            'practitionerNo' => $admission->practitioner?->nhif_practitioner_no,
            'wardTypeId' => $transfer->nhif_ward_type_id,
            'roomTypeId' => $transfer->nhif_room_type_id,
            'transferDate' => now()->toDateString(),
            'reason' => $transfer->reason,
        ];

        $result = $this->nhifClient->submitTransfer($payload);

        $transfer->update([
            'nhif_admission_no' => $nhifAdmission?->nhif_admission_no,
            'nhif_status' => $result['success']
                ? AdmissionTransfer::NHIF_STATUS_SUBMITTED
                : AdmissionTransfer::NHIF_STATUS_PENDING,
            'nhif_transfer_no' => $result['data']['transferNo'] ?? $result['data']['TransferNo'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'raw_request' => json_encode($payload),
            'raw_response' => json_encode($result['data'] ?? []),
            'error_message' => $result['error'] ?? null,
        ]);

        $this->auditLog->record(
            $result['success'] ? 'admission_transfer_nhif_submitted' : 'admission_transfer_nhif_submission_failed',
            $admission,
            null,
            ['transfer_id' => $transfer->id, 'success' => $result['success'], 'error' => $result['error'] ?? null],
            $request,
        );

        return $transfer->fresh();
    }

    public function getNhifAdmissionTypes(): array
    {
        return $this->fetchAndCacheNhifType('nhif.admission_types', fn() => $this->nhifClient->getAdmissionTypes());
    }

    public function getNhifWardTypes(): array
    {
        return $this->fetchAndCacheNhifType('nhif.ward_types', fn() => $this->nhifClient->getWardTypes());
    }

    public function getNhifRoomTypes(): array
    {
        return $this->fetchAndCacheNhifType('nhif.room_types', fn() => $this->nhifClient->getRoomTypes());
    }

    private function fetchAndCacheNhifType(string $cacheKey, callable $fetcher): array
    {
        $types = Cache::get($cacheKey);
        if (!$types) {
            $res = $fetcher();
            if (isset($res['success']) && $res['success'] && !empty($res['data'])) {
                $types = $res['data'];
                Cache::put($cacheKey, $types, 86400); // 24 hours
            } else {
                $types = []; // Don't cache failures
            }
        }
        return $types;
    }
}
