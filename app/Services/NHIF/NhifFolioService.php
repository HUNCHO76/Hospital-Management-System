<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifBillConfirmation;
use App\Models\NhifClaim;
use App\Models\NhifClaimSubmissionAttempt;
use App\Models\NhifFolioDisease;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles the NHIF OCS folio lifecycle:
 *   prepare → sign → submit → receipt
 *
 * The local NhifClaim model IS the folio — no separate folio table.
 * This service adds OCS API calls and duplicate-submission protection
 * on top of the claim state managed by NhifClaimController.
 *
 * Never throws on NHIF API failure — stores the failure and returns
 * the updated claim so callers can surface the error without a 500.
 */
class NhifFolioService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Map HMS diagnosis codes to FolioDisease records on the claim.
     * Replaces any existing diseases for this folio.
     *
     * @param  array<int, array{disease_code: string, status?: string, remarks?: string, is_main?: bool}>  $diseases
     */
    public function mapDiseases(NhifClaim $claim, array $diseases): void
    {
        DB::transaction(function () use ($claim, $diseases): void {
            $claim->diseases()->delete();

            foreach ($diseases as $d) {
                NhifFolioDisease::create([
                    'nhif_claim_id' => $claim->id,
                    'disease_code' => $d['disease_code'],
                    'status' => $d['status'] ?? null,
                    'remarks' => $d['remarks'] ?? null,
                    'is_main' => (bool) ($d['is_main'] ?? false),
                ]);
            }
        });
    }

    /**
     * Sign the folio via NHIF OCS SignFolio endpoint.
     * Records signature metadata regardless of NHIF outcome.
     *
     * @param  array{card_no?: string, authorization_no?: string, amount_claimed?: float, signature_data?: string, fp_code?: string, signature_method?: string, signed_by?: string, signed_by_name?: string, remarks?: string}  $signatureData
     */
    public function sign(NhifClaim $claim, array $signatureData, Request $request): NhifClaim
    {
        if (! $claim->isSubmittable()) {
            throw ValidationException::withMessages([
                'status' => 'Only finalized (ready) claims can be signed.',
            ]);
        }

        $payload = [
            'CardNo' => $signatureData['card_no'] ?? $claim->card_no,
            'AuthorizationNo' => $signatureData['authorization_no'] ?? $claim->authorization_no,
            'AmountClaimed' => (float) ($signatureData['amount_claimed'] ?? $claim->nhif_allowable_total),
            'SignatureData' => $signatureData['signature_data'] ?? null,
            'FpCode' => $signatureData['fp_code'] ?? null,
            'SignatureMethod' => $signatureData['signature_method'] ?? 'Manual',
            'SignedBy' => $signatureData['signed_by'] ?? (string) $request->user()?->id,
            'SignedByName' => $signatureData['signed_by_name'] ?? $request->user()?->name,
            'Remarks' => $signatureData['remarks'] ?? null,
        ];

        $result = $this->client->signFolio($payload);

        DB::transaction(function () use ($claim, $payload, $result, $signatureData, $request): void {
            $claim->update([
                'signed_at' => now(),
                'signed_by' => $request->user()?->id,
                'signature_method' => $signatureData['signature_method'] ?? 'Manual',
                'signature_payload' => $payload,
                'signature_response' => $result,
            ]);

            $this->auditLog->record(
                'NHIF_FOLIO_SIGNED',
                $claim,
                ['signed_at' => null],
                ['signed_at' => now()->toIso8601String(), 'nhif_success' => $result['success']],
                $request,
            );
        });

        return $claim->fresh();
    }

    /**
     * Submit the folio to NHIF OCS SubmitFolio endpoint.
     * Prevents duplicate submission via submission_key uniqueness.
     * Never throws on NHIF failure.
     */
    public function submit(NhifClaim $claim, Request $request): NhifClaim
    {
        if (! $claim->isSubmittable()) {
            throw ValidationException::withMessages([
                'status' => 'Claim cannot be submitted in its current state ('.$claim->status.').',
            ]);
        }

        // Already submitted successfully — block retry
        if ($claim->status === NhifClaim::STATUS_SUBMITTED && filled($claim->nhif_claim_reference)) {
            throw ValidationException::withMessages([
                'status' => 'This folio has already been submitted to NHIF (submission ref: '.$claim->nhif_claim_reference.').',
            ]);
        }

        $submissionKey = 'nhif_claim_'.$claim->id.'_'.Str::uuid();
        $attemptNumber = $claim->submissionAttempts()->count() + 1;

        $claim->load(['patient', 'patientInsurance', 'hospital', 'activeItems', 'diseases']);

        $folioPayload = $this->buildFolioPayload($claim);

        $result = $this->client->submitFolio($folioPayload);

        DB::transaction(function () use ($claim, $submissionKey, $attemptNumber, $result, $request): void {
            $submissionStatus = $result['success']
                ? NhifClaimSubmissionAttempt::STATUS_SUBMITTED
                : NhifClaimSubmissionAttempt::STATUS_FAILED;

            $attempt = NhifClaimSubmissionAttempt::create([
                'nhif_claim_id' => $claim->id,
                'hospital_id' => $claim->hospital_id,
                'submission_key' => $submissionKey,
                'attempt_number' => $attemptNumber,
                'submission_status' => $submissionStatus,
                'nhif_response_status' => $result['data']['Status'] ?? $result['data']['status'] ?? null,
                'nhif_claim_reference' => $result['data']['SubmissionNo'] ?? $result['data']['SubmissionID'] ?? null,
                'http_status' => $result['http_status'],
                'error_message' => $result['error'],
                'raw_response' => $result,
                'submitted_at' => now(),
                'submitted_by' => $request->user()?->id,
            ]);

            if ($result['success']) {
                $responseData = $result['data'];
                $claim->update([
                    'status' => NhifClaim::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submitted_by' => $request->user()?->id,
                    'nhif_claim_reference' => $responseData['SubmissionID'] ?? $responseData['submissionID'] ?? null,
                    'nhif_submission_no' => $responseData['SubmissionNo'] ?? $responseData['submissionNo'] ?? null,
                    'nhif_hash_code' => $responseData['HashCode'] ?? $responseData['hashCode'] ?? null,
                    'nhif_submission_remarks' => $responseData['Remarks'] ?? $responseData['remarks'] ?? null,
                    'updated_by' => $request->user()?->id,
                ]);

                $this->auditLog->record(
                    'NHIF_FOLIO_SUBMITTED',
                    $claim,
                    ['status' => NhifClaim::STATUS_READY],
                    [
                        'status' => NhifClaim::STATUS_SUBMITTED,
                        'nhif_submission_no' => $claim->nhif_submission_no,
                        'nhif_claim_reference' => $claim->nhif_claim_reference,
                    ],
                    $request,
                );
            } else {
                $this->auditLog->record(
                    'NHIF_CLAIM_SUBMISSION_FAILED',
                    $claim,
                    ['status' => $claim->status],
                    ['error' => $result['error'], 'http_status' => $result['http_status']],
                    $request,
                );
            }
        });

        return $claim->fresh();
    }

    /**
     * Retrieve the receipt for a submitted folio from NHIF OCS.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null}
     */
    public function getReceipt(NhifClaim $claim, Request $request): array
    {
        if ($claim->status !== NhifClaim::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Receipt can only be retrieved for submitted folios.',
            ]);
        }

        $result = $this->client->getReceipt(
            (string) $claim->facility_code,
            (int) $claim->folio_year,
            (int) $claim->folio_month,
            (string) $claim->folio_no,
        );

        $this->auditLog->record(
            'NHIF_FOLIO_RECEIPT_RETRIEVED',
            $claim,
            null,
            ['nhif_success' => $result['success'], 'folio_no' => $claim->folio_no],
            $request,
        );

        return $result;
    }

    /**
     * Request bill confirmation from NHIF OCS before folio creation.
     * Sends OTP / confirmation code to the patient.
     */
    public function requestBillConfirmation(
        PatientInsurance $insurance,
        Hospital $hospital,
        Carbon $attendanceDate,
        float $totalAmount,
        Request $request,
    ): NhifBillConfirmation {
        $facilityCode = $hospital->nhif_facility_code ?? $hospital->code ?? '';

        $payload = [
            'FacilityCode' => $facilityCode,
            'CardNo' => $insurance->card_number,
            'AuthorizationNo' => $insurance->nhif_authorization_no,
            'AttendanceDate' => $attendanceDate->toDateString(),
            'TotalAmount' => $totalAmount,
        ];

        $result = $this->client->requestBillConfirmation($payload);

        $confirmation = DB::transaction(function () use ($insurance, $hospital, $facilityCode, $attendanceDate, $totalAmount, $result, $request): NhifBillConfirmation {
            $conf = NhifBillConfirmation::create([
                'hospital_id' => $hospital->id,
                'patient_insurance_id' => $insurance->id,
                'nhif_authorization_no' => $insurance->nhif_authorization_no ?? '',
                'card_no' => $insurance->card_number,
                'facility_code' => $facilityCode,
                'attendance_date' => $attendanceDate,
                'total_amount' => $totalAmount,
                'confirmation_code' => null,
                'confirmation_status' => $result['success']
                    ? NhifBillConfirmation::STATUS_PENDING
                    : NhifBillConfirmation::STATUS_FAILED,
                'nhif_response' => $result,
                'created_by' => $request->user()->id,
            ]);

            $this->auditLog->record(
                'NHIF_BILL_CONFIRMATION_REQUESTED',
                $conf,
                null,
                ['nhif_success' => $result['success'], 'total_amount' => $totalAmount],
                $request,
            );

            return $conf;
        });

        return $confirmation;
    }

    /**
     * Confirm a bill by supplying the confirmation code returned by NHIF.
     */
    public function confirmBill(NhifBillConfirmation $confirmation, string $confirmationCode, Request $request): NhifBillConfirmation
    {
        $payload = [
            'FacilityCode' => $confirmation->facility_code,
            'CardNo' => $confirmation->card_no,
            'AuthorizationNo' => $confirmation->nhif_authorization_no,
            'AttendanceDate' => $confirmation->attendance_date->toDateString(),
            'TotalAmount' => (float) $confirmation->total_amount,
        ];

        $result = $this->client->sendConfirmationCode(array_merge($payload, [
            'ConfirmationCode' => $confirmationCode,
        ]));

        DB::transaction(function () use ($confirmation, $confirmationCode, $result, $request): void {
            $updates = [
                'confirmation_code' => $confirmationCode,
                'nhif_response' => $result,
            ];

            if ($result['success']) {
                $updates['confirmation_status'] = NhifBillConfirmation::STATUS_CONFIRMED;
                $updates['confirmed_at'] = now();
            } else {
                $updates['confirmation_status'] = NhifBillConfirmation::STATUS_FAILED;
            }

            $confirmation->update($updates);

            $this->auditLog->record(
                'NHIF_BILL_CONFIRMED',
                $confirmation,
                ['confirmation_status' => NhifBillConfirmation::STATUS_PENDING],
                ['confirmation_status' => $confirmation->fresh()->confirmation_status],
                $request,
            );
        });

        return $confirmation->fresh();
    }

    /**
     * Build the OCS Folio payload from the local NhifClaim.
     * Uses OCS field names exactly as specified.
     *
     * @return array<string, mixed>
     */
    public function buildFolioPayload(NhifClaim $claim): array
    {
        $claim->load(['patient', 'patientInsurance', 'hospital', 'activeItems', 'diseases']);

        $patient = $claim->patient;
        $insurance = $claim->patientInsurance;

        $folioItems = $claim->activeItems->map(fn ($item) => [
            'ItemCode' => $item->nhif_item_code,
            'ItemName' => $item->nhif_item_name ?? $item->description,
            'ItemTypeID' => $item->item_type_id,
            'UnitPrice' => (float) $item->hms_unit_price,
            'ItemQuantity' => $item->quantity,
            'AmountClaimed' => (float) ($item->nhif_allowable_amount ?? $item->hms_amount),
            'OtherDetails' => $item->other_details,
            'ApprovalRefNo' => $item->approval_ref_no ?? $item->nhif_authorization_no,
        ])->values()->all();

        $folioDiseases = $claim->diseases->map(fn ($d) => [
            'DiseaseCode' => $d->disease_code,
            'Status' => $d->status,
            'Remarks' => $d->remarks,
        ])->values()->all();

        return [
            'FacilityCode' => $claim->facility_code,
            'ClaimYear' => $claim->folio_year,
            'ClaimMonth' => $claim->folio_month,
            'FolioNo' => $claim->folio_no,
            'CardNo' => $claim->card_no ?? $insurance?->card_number,
            'FirstName' => $patient?->first_name,
            'LastName' => $patient?->last_name,
            'Gender' => $patient?->gender,
            'DateOfBirth' => $patient?->date_of_birth?->toDateString(),
            'TelephoneNo' => $patient?->phone,
            'PatientFileNo' => $patient?->patient_number,
            'BillNo' => $claim->bill_no,
            'ClinicalNotes' => $claim->clinical_notes,
            'AuthorizationNo' => $claim->authorization_no,
            'AttendanceDate' => $claim->attendance_date?->toDateString(),
            'VisitTypeID' => $claim->visit_type_id,
            'PatientTypeCode' => $claim->patient_type_code,
            'DateAdmitted' => $claim->admitted_at?->toDateString(),
            'DateDischarged' => $claim->discharged_at?->toDateString(),
            'AttendingPractitioners' => $claim->attending_practitioners ?? [],
            'LateSubmissionReason' => $claim->late_submission_reason,
            'AmountClaimed' => (float) $claim->nhif_allowable_total,
            'MainDiagnosisCode' => $claim->primary_diagnosis_code,
            'ConfirmationCode' => $claim->confirmation_code,
            'FolioDiseases' => $folioDiseases,
            'FolioItems' => $folioItems,
            'Signatures' => $claim->signature_response ? [$claim->signature_response] : [],
        ];
    }
}
