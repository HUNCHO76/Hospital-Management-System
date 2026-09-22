<?php

namespace App\Services\NHIF;

use App\Models\NhifMemberVerification;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * Orchestrates NHIF member/card verification for a PatientInsurance record
 * (Phase 8, Step 65).
 *
 * Architecture contract:
 *  - NHIF credentials never leave this service or NhifHttpClient.
 *  - Controllers call this service; React never calls NHIF directly.
 *  - Records are append-only (never edited after creation).
 *  - Identity matching is enforced here before the insurance record is
 *    marked verified.
 */
class NhifMemberVerificationService
{
    public function __construct(
        protected NhifHttpClient $client,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Verify an NHIF member for the given PatientInsurance record and persist
     * the result as an append-only NhifMemberVerification row.
     *
     * Returns the verification record regardless of outcome so the caller can
     * surface the result to the user.
     */
    public function verify(
        PatientInsurance $insurance,
        string $identifierType,
        string $identifierValue,
        Request $request,
    ): NhifMemberVerification {
        $insurance->load('patient');

        [$apiResult, $operation] = $this->callApi($identifierType, $identifierValue);

        $memberData = $apiResult['data'] ?? [];

        if ($apiResult['success'] && filled($memberData)) {
            [$status, $memberName] = $this->resolveVerificationStatus($insurance, $memberData);
        } else {
            $status = $this->statusFromHttpFailure($apiResult);
            $memberName = null;
        }

        $nhifCardNo = $memberData['CardNo'] ?? $memberData['CardNumber'] ?? null;

        $record = NhifMemberVerification::create([
            'patient_insurance_id' => $insurance->id,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
            'nhif_operation' => $operation,
            'verification_status' => $status,
            'nhif_member_name' => $memberName ?? ($memberData['MemberName'] ?? $memberData['FullName'] ?? null),
            'nhif_card_no' => $nhifCardNo,
            'nhif_expiry_date' => $memberData['ExpiryDate'] ?? $memberData['CardExpiryDate'] ?? null,
            'nhif_membership_no' => $memberData['MembershipNo'] ?? $memberData['MembershipNumber'] ?? null,
            'nhif_plan_name' => $memberData['PlanName'] ?? $memberData['SchemeName'] ?? null,
            'http_status' => $apiResult['http_status'] ?: null,
            'error_message' => $apiResult['error'],
            'correlation_id' => $apiResult['correlation_id'],
            'request_data' => ['identifier_type' => $identifierType],
            'response_data' => $memberData,
            'verified_at' => $status === NhifMemberVerification::STATUS_VERIFIED ? now() : null,
            'verified_by' => $request->user()?->id,
        ]);

        // Update the PatientInsurance verification status and card number.
        // Card number is propagated from the NHIF response so that a subsequent
        // eligibility check (which needs the card number) has it available.
        $newVerificationStatus = $status === NhifMemberVerification::STATUS_VERIFIED
            ? PatientInsurance::VERIFICATION_VERIFIED
            : PatientInsurance::VERIFICATION_FAILED;

        $insuranceUpdates = ['verification_status' => $newVerificationStatus, 'verified_at' => now()];

        if ($nhifCardNo && blank($insurance->card_number)) {
            $insuranceUpdates['card_number'] = $nhifCardNo;
        }

        $insurance->update($insuranceUpdates);

        $this->auditLog->record(
            'nhif_member_verification',
            $insurance,
            ['verification_status' => PatientInsurance::VERIFICATION_PENDING],
            ['verification_status' => $newVerificationStatus, 'nhif_status' => $status],
            $request,
        );

        return $record;
    }

    /**
     * Call the correct NHIF API endpoint based on the identifier type.
     *
     * @return array{0: array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string, operation: string}, 1: string}
     */
    protected function callApi(string $identifierType, string $identifierValue): array
    {
        if ($identifierType === NhifMemberVerification::TYPE_NIN) {
            $result = $this->client->getMemberByNin($identifierValue);
        } else {
            $result = $this->client->getMemberByCard($identifierValue);
        }

        return [$result, $result['operation']];
    }

    /**
     * Map an API failure (non-2xx or connection error) to a NhifMemberVerification status.
     */
    protected function statusFromHttpFailure(array $apiResult): string
    {
        $http = $apiResult['http_status'] ?? 0;

        if ($http === 0) {
            return NhifMemberVerification::STATUS_ERROR;
        }

        if ($http === 404) {
            return NhifMemberVerification::STATUS_NOT_FOUND;
        }

        return NhifMemberVerification::STATUS_ERROR;
    }

    /**
     * Determine verification status and extracted member name from a successful
     * NHIF API response, enforcing identity matching before returning "verified".
     *
     * Identity matching rules (at least one must pass):
     *  1. Patient national_id matches the NIN returned by NHIF.
     *  2. Patient first+last name is substantially contained in the NHIF member name.
     *
     * @param  array<string, mixed>  $memberData
     * @return array{0: string, 1: string|null}
     */
    protected function resolveVerificationStatus(PatientInsurance $insurance, array $memberData): array
    {
        $patient = $insurance->patient;
        $nhifName = $memberData['MemberName'] ?? $memberData['FullName'] ?? '';
        $nhifNin = $memberData['NIN'] ?? $memberData['Nin'] ?? null;
        $nhifStatus = strtolower($memberData['Status'] ?? $memberData['MemberStatus'] ?? 'active');

        if (in_array($nhifStatus, ['inactive', 'expired', 'suspended', 'cancelled'], true)) {
            return [NhifMemberVerification::STATUS_INACTIVE, $nhifName ?: null];
        }

        // Hard identity check: NIN returned by NHIF must match patient NIN (when present).
        if ($nhifNin && $patient?->national_id && strtoupper(trim($nhifNin)) !== strtoupper(trim($patient->national_id))) {
            return [NhifMemberVerification::STATUS_INVALID, $nhifName ?: null];
        }

        // Name-based identity check when no NIN is available.
        if (! $nhifNin && $patient && ! $this->nameMatches($patient->first_name, $patient->last_name, $nhifName)) {
            return [NhifMemberVerification::STATUS_INVALID, $nhifName ?: null];
        }

        return [NhifMemberVerification::STATUS_VERIFIED, $nhifName ?: null];
    }

    /**
     * Fuzzy check that patient first+last names are substantially present in the
     * NHIF-returned name string. Case-insensitive; requires both first_name and
     * last_name to appear (order-independent).
     */
    protected function nameMatches(string $firstName, string $lastName, string $nhifName): bool
    {
        if (blank($nhifName)) {
            return false;
        }

        $haystack = strtolower(trim($nhifName));
        $first = strtolower(trim($firstName));
        $last = strtolower(trim($lastName));

        return str_contains($haystack, $first) && str_contains($haystack, $last);
    }
}
