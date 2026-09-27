<?php

namespace App\Services\NHIF;

use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\NhifMonthlySubmission;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
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
     * Retrieve submitted claims from NHIF OCS for the given period.
     * Server-side only — never exposed directly to React.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null}
     */
    public function getSubmittedClaims(Hospital $hospital, int $year, int $month, Request $request): array
    {
        $facilityCode = $hospital->nhif_facility_code ?? $hospital->code ?? '';

        return $this->client->getSubmittedClaims($facilityCode, $year, $month);
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
        $facilityCode = $hospital->nhif_facility_code ?? $hospital->code ?? '';

        if (blank($facilityCode)) {
            throw ValidationException::withMessages([
                'facility_code' => 'Hospital NHIF facility code is not configured.',
            ]);
        }

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

        // Calculate totals server-side from submitted local folios
        $folios = NhifClaim::where('hospital_id', $hospital->id)
            ->where('folio_year', $year)
            ->where('folio_month', $month)
            ->where('status', NhifClaim::STATUS_SUBMITTED)
            ->get(['id', 'nhif_allowable_total']);

        if ($folios->isEmpty()) {
            throw ValidationException::withMessages([
                'folios' => 'No submitted folios found for the selected period.',
            ]);
        }

        $foliosCount = $folios->count();
        $totalAmount = $folios->sum(fn ($f) => (float) $f->nhif_allowable_total);

        $payload = [
            'FacilityCode' => $facilityCode,
            'ClaimYear' => $year,
            'ClaimMonth' => $month,
            'FoliosSubmitted' => $foliosCount,
            'TotalAmountClaimed' => $totalAmount,
            'SubmissionRemarks' => $remarks,
        ];

        $result = $this->client->submitMonthlyClaim($payload);

        return DB::transaction(function () use ($hospital, $facilityCode, $year, $month, $foliosCount, $totalAmount, $remarks, $result, $request): NhifMonthlySubmission {
            $submission = NhifMonthlySubmission::updateOrCreate(
                ['hospital_id' => $hospital->id, 'claim_year' => $year, 'claim_month' => $month],
                [
                    'facility_code' => $facilityCode,
                    'folios_submitted' => $foliosCount,
                    'total_amount_claimed' => $totalAmount,
                    'submission_remarks' => $remarks,
                    'nhif_response' => $result,
                    'status' => $result['success']
                        ? NhifMonthlySubmission::STATUS_SUBMITTED
                        : NhifMonthlySubmission::STATUS_FAILED,
                    'submitted_at' => now(),
                    'submitted_by' => $request->user()->id,
                ],
            );

            $this->auditLog->record(
                'NHIF_MONTHLY_CLAIM_SUBMITTED',
                $submission,
                null,
                [
                    'period' => "{$year}-{$month}",
                    'folios' => $foliosCount,
                    'total_amount' => $totalAmount,
                    'nhif_success' => $result['success'],
                ],
                $request,
            );

            return $submission;
        });
    }
}
