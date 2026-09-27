<?php

namespace App\Services\NHIF;

use App\Models\NhifEligibilityRecord;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * Orchestrates NHIF eligibility checks for a PatientInsurance record
 * (Phase 8, Step 66).
 *
 * The eligibility check is always run after a successful member verification.
 * Results are persisted as append-only NhifEligibilityRecord rows; the latest
 * outcome is also cached on PatientInsurance for fast billing lookups.
 */
class NhifEligibilityService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Check eligibility for the given PatientInsurance record and persist the
     * result as an append-only NhifEligibilityRecord row.
     *
     * The card number used for the check is taken from the insurance record's
     * card_number field (set during member verification). If it is absent, the
     * check is skipped and an error record is returned.
     */
    public function check(PatientInsurance $insurance, Request $request): NhifEligibilityRecord
    {
        $cardNumber = $insurance->card_number ?? '';

        if (blank($cardNumber)) {
            return $this->errorRecord($insurance, 'No card number available for eligibility check.', $request);
        }

        $result = $this->client->checkEligibility($cardNumber);
        $body = $result['data'] ?? [];

        if ($result['success'] && filled($body)) {
            $status = $this->resolveEligibilityStatus($body);
            $coveragePct = $this->extractCoverage($body);
            $patientPct = $this->extractPatientResponsibility($body);
            // GetCardDetails uses ExpiryDate; a dedicated eligibility endpoint would use ValidFrom/ValidTo.
            $validFrom = $body['ValidFrom'] ?? $body['CoverFrom'] ?? null;
            $validTo = $body['ValidTo'] ?? $body['CoverTo'] ?? $body['ExpiryDate'] ?? $body['CardExpiryDate'] ?? null;
            $remarks = $body['Remarks'] ?? $body['StatusRemarks'] ?? $body['SchemeName'] ?? null;
        } else {
            $status = NhifEligibilityRecord::STATUS_ERROR;
            $coveragePct = null;
            $patientPct = null;
            $validFrom = null;
            $validTo = null;
            $remarks = $result['error'];
        }

        $record = NhifEligibilityRecord::create([
            'patient_insurance_id' => $insurance->id,
            'card_no' => $cardNumber,
            'nhif_operation' => 'CheckEligibility',
            'eligibility_status' => $status,
            'nhif_coverage_percent' => $coveragePct,
            'nhif_patient_responsibility_percent' => $patientPct,
            'nhif_valid_from' => $validFrom,
            'nhif_valid_to' => $validTo,
            'nhif_remarks' => $remarks,
            'http_status' => $result['http_status'] ?: null,
            'error_message' => $result['error'],
            'correlation_id' => $result['correlation_id'],
            'request_data' => ['card_no' => $cardNumber],
            'response_data' => $body,
            'eligibility_checked_at' => now(),
            'checked_by' => $request->user()?->id,
        ]);

        // Cache the result on PatientInsurance for fast billing reads.
        $insurance->update([
            'nhif_eligibility_status' => $status,
            'nhif_coverage_percent' => $coveragePct,
            'nhif_eligibility_checked_at' => now(),
        ]);

        $this->auditLog->record(
            'nhif_eligibility_checked',
            $insurance,
            ['nhif_eligibility_status' => $insurance->getOriginal('nhif_eligibility_status')],
            ['nhif_eligibility_status' => $status, 'nhif_coverage_percent' => $coveragePct],
            $request,
        );

        return $record;
    }

    protected function resolveEligibilityStatus(array $body): string
    {
        // GetCardDetails uses IsActive/IsValidCard booleans; a dedicated eligibility
        // endpoint would use EligibilityStatus/Status string fields.
        if (array_key_exists('IsActive', $body) || array_key_exists('IsValidCard', $body)) {
            $isActive = (bool) ($body['IsActive'] ?? true);
            $isValid = (bool) ($body['IsValidCard'] ?? true);

            if (! $isActive || ! $isValid) {
                return NhifEligibilityRecord::STATUS_INELIGIBLE;
            }

            return NhifEligibilityRecord::STATUS_ELIGIBLE;
        }

        $raw = strtolower(
            $body['EligibilityStatus']
            ?? $body['Status']
            ?? $body['status']
            ?? $body['CardStatus']
            ?? '',
        );

        return match ($raw) {
            'eligible', 'active', 'valid' => NhifEligibilityRecord::STATUS_ELIGIBLE,
            'ineligible', 'not eligible', 'inactive' => NhifEligibilityRecord::STATUS_INELIGIBLE,
            'expired' => NhifEligibilityRecord::STATUS_EXPIRED,
            'suspended', 'cancelled' => NhifEligibilityRecord::STATUS_SUSPENDED,
            default => NhifEligibilityRecord::STATUS_UNKNOWN,
        };
    }

    protected function extractCoverage(array $body): ?float
    {
        $raw = $body['CoveragePercent'] ?? $body['NHIFCoverPercent'] ?? $body['FacilityPercent'] ?? null;

        return $raw !== null ? (float) $raw : null;
    }

    protected function extractPatientResponsibility(array $body): ?float
    {
        $raw = $body['PatientResponsibilityPercent'] ?? $body['PatientPercent'] ?? null;

        return $raw !== null ? (float) $raw : null;
    }

    protected function errorRecord(PatientInsurance $insurance, string $error, Request $request): NhifEligibilityRecord
    {
        return NhifEligibilityRecord::create([
            'patient_insurance_id' => $insurance->id,
            'card_no' => '',
            'nhif_operation' => 'CheckEligibility',
            'eligibility_status' => NhifEligibilityRecord::STATUS_ERROR,
            'error_message' => $error,
            'eligibility_checked_at' => now(),
            'checked_by' => $request->user()?->id,
        ]);
    }
}
