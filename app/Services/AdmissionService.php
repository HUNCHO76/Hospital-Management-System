<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\NhifAdmission;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * @param  array{hospital_id: int, patient_id: int, service_id?: int|null, department_id?: int|null, practitioner_id?: int|null, clinical_encounter_id?: int|null, patient_insurance_id?: int|null, admission_type?: string|null, ward_type?: string|null, room_type?: string|null, diagnosis_at_admission?: string|null, notes?: string|null}  $data
     */
    public function create(array $data, Request $request): Admission
    {
        $admission = DB::transaction(function () use ($data, $request) {
            $admission = Admission::create([
                'hospital_id' => $data['hospital_id'],
                'patient_id' => $data['patient_id'],
                'clinical_encounter_id' => $data['clinical_encounter_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'patient_insurance_id' => $data['patient_insurance_id'] ?? null,
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

            $this->nhifPreauth->assertPreauthorizedForSources(Admission::class, [$admission->id]);
            $this->financialAuth->assertAuthorizedForSources(
                Admission::class,
                [$admission->id],
                FinancialAuthorizationService::STATUS_AUTHORIZED,
            );

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
        $admission->loadMissing('hospital', 'practitioner');

        $payload = [
            'admissionNo' => $admission->admission_number,
            'facilityCode' => $admission->hospital?->code,
            'practitionerNo' => $admission->practitioner?->nhif_practitioner_no,
            'admissionDate' => optional($admission->admitted_at)->toDateString(),
            'wardType' => $admission->ward_type,
            'roomType' => $admission->room_type,
            'diagnosis' => $admission->diagnosis_at_admission,
        ];

        $result = $this->nhifClient->submitAdmission($payload);

        $nhifAdmission = NhifAdmission::updateOrCreate(
            ['admission_id' => $admission->id],
            [
                'nhif_facility_code' => $admission->hospital?->code,
                'nhif_practitioner_no' => $admission->practitioner?->nhif_practitioner_no,
                'nhif_status' => $result['success'] ? NhifAdmission::STATUS_SUBMITTED : NhifAdmission::STATUS_FAILED,
                'http_status' => $result['http_status'] ?? null,
                'raw_request' => json_encode($payload),
                'raw_response' => json_encode($result['data'] ?? []),
                'error_message' => $result['error'] ?? null,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ],
        );

        $this->auditLog->record(
            $result['success'] ? 'admission_nhif_submitted' : 'admission_nhif_submission_failed',
            $admission,
            null,
            ['success' => $result['success'], 'error' => $result['error'] ?? null],
            $request,
        );

        return $admission->fresh();
    }
}
