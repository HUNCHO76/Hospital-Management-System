<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\NhifMonthlySubmission;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles the NHIF OCS monthly consolidated claim submission.
 *
 * Totals are ALWAYS calculated server-side from submitted folios.
 * The browser never supplies amounts.
 */
class NhifMonthlyClaimService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Resolve the active NHIF facility code for monthly claim submission.
     *
     * Fallback order:
     * 1. Hospital's explicit nhif_facility_code
     * 2. Folio's facility_code from the submitted batch
     * 3. Configured NHIF client ID (nhif.client_id)
     * 4. Hospital internal code
     *
     * @param  Collection<int, mixed>|null  $folios
     */
    public function resolveFacilityCode(Hospital $hospital, $folios = null): string
    {
        $folioFacilityCode = $folios?->first(fn ($f) => filled($f->facility_code ?? null))?->facility_code;

        return $hospital->nhif_facility_code
            ?: ($folioFacilityCode
                ?: (config('nhif.client_id')
                    ?: ($hospital->code ?: '')));
    }

    /**
     * Retrieve submitted claims from NHIF OCS for the given period.
     * Server-side only — never exposed directly to React.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null}
     */
    public function getSubmittedClaims(Hospital $hospital, int $year, int $month, Request $request): array
    {
        $facilityCode = $this->resolveFacilityCode($hospital);

        return $this->client->getSubmittedClaims($facilityCode, $year, $month);
    }

    /**
     * Calculate monthly claimable statistics and preview data for a facility period.
     *
     * @return array{
     *   facility_code: string,
     *   hospital_name: string,
     *   claim_year: int,
     *   claim_month: int,
     *   folios_submitted: int,
     *   total_amount_claimed: float,
     *   formatted_total: string,
     *   can_submit: bool,
     *   is_already_submitted: bool,
     *   existing_submission: array<string, mixed>|null,
     *   folios_sample: list<array<string, mixed>>
     * }
     */
    public function calculateMonthlyClaim(Hospital $hospital, int $year, int $month): array
    {
        $existing = NhifMonthlySubmission::where('hospital_id', $hospital->id)
            ->where('claim_year', $year)
            ->where('claim_month', $month)
            ->first();

        $isAlreadySubmitted = $existing && $existing->status === NhifMonthlySubmission::STATUS_SUBMITTED;

        $folios = NhifClaim::where('hospital_id', $hospital->id)
            ->where('folio_year', $year)
            ->where('folio_month', $month)
            ->whereIn('status', [
                NhifClaim::STATUS_SUBMITTED,
                NhifClaim::STATUS_BILL_CONFIRMATION_REQUESTED,
                NhifClaim::STATUS_CONFIRMED,
                NhifClaim::STATUS_FINALIZED,
                NhifClaim::STATUS_ACCEPTED,
                NhifClaim::STATUS_RECONCILED,
            ])
            ->whereNotIn('status', [
                NhifClaim::STATUS_DRAFT,
                NhifClaim::STATUS_VALIDATED,
                NhifClaim::STATUS_READY,
                NhifClaim::STATUS_SIGNED,
                NhifClaim::STATUS_SIGNING_FAILED,
                NhifClaim::STATUS_SUBMISSION_FAILED,
                NhifClaim::STATUS_REJECTED,
                NhifClaim::STATUS_CANCELLED,
            ])
            ->with(['patient'])
            ->get(['id', 'claim_number', 'folio_no', 'patient_id', 'card_no', 'attendance_date', 'status', 'nhif_allowable_total', 'hms_total', 'nhif_submission_no', 'facility_code']);

        $facilityCode = $this->resolveFacilityCode($hospital, $folios);

        $foliosCount = $folios->count();
        $totalAmount = (float) $folios->sum(fn ($f) => (float) ($f->nhif_allowable_total ?? $f->hms_total ?? 0));

        return [
            'facility_code' => $facilityCode,
            'hospital_name' => $hospital->name,
            'claim_year' => $year,
            'claim_month' => $month,
            'folios_submitted' => $foliosCount,
            'total_amount_claimed' => round($totalAmount, 2),
            'formatted_total' => number_format($totalAmount, 2),
            'can_submit' => $foliosCount > 0 && $totalAmount > 0 && ! $isAlreadySubmitted && filled($facilityCode),
            'is_already_submitted' => $isAlreadySubmitted,
            'existing_submission' => $existing ? [
                'id' => $existing->id,
                'status' => $existing->status,
                'folios_submitted' => $existing->folios_submitted,
                'total_amount_claimed' => (float) $existing->total_amount_claimed,
                'submitted_at' => $existing->submitted_at?->toDateTimeString(),
                'error_message' => $existing->error_message,
                'retry_count' => $existing->retry_count,
            ] : null,
            'folios_sample' => $folios->take(10)->map(fn ($f) => [
                'id' => $f->id,
                'claim_number' => $f->claim_number,
                'folio_no' => $f->folio_no,
                'patient_name' => $f->patient ? trim($f->patient->first_name.' '.$f->patient->last_name) : 'Patient',
                'card_no' => $f->card_no,
                'amount' => (float) ($f->nhif_allowable_total ?? $f->hms_total ?? 0),
                'status' => $f->status,
                'submission_no' => $f->nhif_submission_no,
            ])->values()->all(),
        ];
    }

    /**
     * Submit the monthly consolidated claim to NHIF OCS.
     * Calculates folios_submitted and total_amount_claimed from local submitted folios.
     * Prevents duplicate submission for the same period.
     */
    public function submitMonthlyClaim(
        Hospital $hospital,
        int $year,
        int $month,
        ?string $remarks,
        Request $request,
    ): NhifMonthlySubmission {
        // Prevent duplicate monthly submission
        $existing = NhifMonthlySubmission::where('hospital_id', $hospital->id)
            ->where('claim_year', $year)
            ->where('claim_month', $month)
            ->where('status', NhifMonthlySubmission::STATUS_SUBMITTED)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'period' => "Monthly claim for {$year}-{$month} has already been submitted.",
            ]);
        }

        // Calculate totals server-side strictly from successfully submitted local folios
        $folios = NhifClaim::where('hospital_id', $hospital->id)
            ->where('folio_year', $year)
            ->where('folio_month', $month)
            ->whereIn('status', [
                NhifClaim::STATUS_SUBMITTED,
                NhifClaim::STATUS_BILL_CONFIRMATION_REQUESTED,
                NhifClaim::STATUS_CONFIRMED,
                NhifClaim::STATUS_FINALIZED,
                NhifClaim::STATUS_ACCEPTED,
                NhifClaim::STATUS_RECONCILED,
            ])
            ->whereNotIn('status', [
                NhifClaim::STATUS_DRAFT,
                NhifClaim::STATUS_VALIDATED,
                NhifClaim::STATUS_READY,
                NhifClaim::STATUS_SIGNED,
                NhifClaim::STATUS_SIGNING_FAILED,
                NhifClaim::STATUS_SUBMISSION_FAILED,
                NhifClaim::STATUS_REJECTED,
                NhifClaim::STATUS_CANCELLED,
            ])
            ->get(['id', 'nhif_allowable_total', 'hms_total', 'facility_code']);

        $foliosCount = $folios->count();
        $totalAmount = (float) $folios->sum(fn ($f) => (float) ($f->nhif_allowable_total ?? $f->hms_total ?? 0));

        if ($foliosCount === 0 || $totalAmount <= 0) {
            throw ValidationException::withMessages([
                'folios' => 'No eligible submitted folios found for the selected period.',
            ]);
        }

        $facilityCode = $this->resolveFacilityCode($hospital, $folios);

        if (blank($facilityCode)) {
            throw ValidationException::withMessages([
                'facility_code' => 'Hospital NHIF facility code is not configured.',
            ]);
        }

        $formattedMonth = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $payload = [
            'FacilityCode' => $facilityCode,
            'ClaimYear' => $year,
            'ClaimMonth' => $month,
            'FoliosSubmitted' => $foliosCount,
            'TotalAmountClaimed' => round($totalAmount, 2),
            'SubmissionRemarks' => $remarks ?: "Monthly NHIF claim submission for {$year}-{$formattedMonth}",
        ];

        $existingRecord = NhifMonthlySubmission::where('hospital_id', $hospital->id)
            ->where('claim_year', $year)
            ->where('claim_month', $month)
            ->first();

        $currentRetryCount = ($existingRecord?->retry_count ?? 0) + 1;

        $result = $this->client->submitMonthlyClaim($payload);

        $isSuccess = ($result['success'] ?? false) === true;
        $errorMessage = ! $isSuccess ? ($result['error'] ?? 'NHIF rejected monthly claim submission') : null;

        return DB::transaction(function () use (
            $hospital,
            $facilityCode,
            $year,
            $month,
            $foliosCount,
            $totalAmount,
            $payload,
            $result,
            $isSuccess,
            $errorMessage,
            $currentRetryCount,
            $request
        ): NhifMonthlySubmission {
            $submission = NhifMonthlySubmission::updateOrCreate(
                ['hospital_id' => $hospital->id, 'claim_year' => $year, 'claim_month' => $month],
                [
                    'facility_code' => $facilityCode,
                    'folios_submitted' => $foliosCount,
                    'total_amount_claimed' => round($totalAmount, 2),
                    'submission_remarks' => $payload['SubmissionRemarks'],
                    'nhif_request_payload' => $payload,
                    'nhif_response' => $result,
                    'error_message' => $errorMessage,
                    'status' => $isSuccess
                        ? NhifMonthlySubmission::STATUS_SUBMITTED
                        : NhifMonthlySubmission::STATUS_FAILED,
                    'retry_count' => $currentRetryCount,
                    'submitted_at' => now(),
                    'submitted_by' => $request->user()?->id ?? 1,
                ],
            );

            $this->auditLog->record(
                'NHIF_MONTHLY_CLAIM_SUBMITTED',
                $submission,
                null,
                [
                    'facility_code' => $facilityCode,
                    'period' => "{$year}-{$month}",
                    'folios_submitted' => $foliosCount,
                    'total_amount_claimed' => round($totalAmount, 2),
                    'nhif_success' => $isSuccess,
                    'error_message' => $errorMessage,
                    'retry_count' => $currentRetryCount,
                    'request_payload' => $payload,
                    'response' => $result,
                ],
                $request,
            );

            return $submission;
        });
    }
}
