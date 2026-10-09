<?php

namespace App\Services\NHIF;

use App\Models\NhifMemberVerification;
use App\Models\PatientInsurance;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * Three-step flow:
     *  1. GetCardDetails/GetCardDetailsByNIN → STATUS_LOOKUP_FOUND on success
     *  2. validateVisitTypeRequirements() — server-side guard for RequiresRemarks/RequiresReferralNo
     *  3. VerifyCard → STATUS_VERIFIED on success, STATUS_VERIFICATION_FAILED on failure
     *
     * STATUS_VERIFIED is ONLY set after VerifyCard returns an authorizationNo.
     */
    public function verify(
        PatientInsurance $insurance,
        string $identifierType,
        string $identifierValue,
        Request $request,
        int $visitTypeId = 1,
        ?string $verifierId = null,
        ?string $cardTypeId = null,
        ?string $remarks = null,
        ?string $referralNo = null,
        string $biometricMethod = 'NONE',
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
        $rawAuthNo = $memberData['AuthorizationNo'] ?? $memberData['authorizationNo'] ?? $memberData['AuthNo'] ?? null;
        $nhifAuthorizationNo = (filled($rawAuthNo) && ! in_array(strtoupper(trim((string) $rawAuthNo)), ['N/A', 'NULL', 'NONE']))
            ? $rawAuthNo
            : null;

        $record = NhifMemberVerification::create([
            'patient_insurance_id' => $insurance->id,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
            'nhif_operation' => $operation,
            'verification_status' => $status,
            'nhif_member_name' => $memberName ?? ($memberData['FullName'] ?? $memberData['MemberName'] ?? null),
            'nhif_card_no' => $nhifCardNo,
            'nhif_expiry_date' => $memberData['ExpiryDate'] ?? $memberData['CardExpiryDate'] ?? null,
            'nhif_membership_no' => $memberData['MembershipNo'] ?? $memberData['MembershipNumber'] ?? null,
            'nhif_plan_name' => $memberData['SchemeName'] ?? $memberData['PlanName'] ?? null,
            'nhif_authorization_no' => $nhifAuthorizationNo,
            'http_status' => $apiResult['http_status'] ?: null,
            'error_message' => $apiResult['error'],
            'correlation_id' => $apiResult['correlation_id'],
            'request_data' => ['identifier_type' => $identifierType],
            'response_data' => $memberData,
            'verified_at' => null,
            'verified_by' => $request->user()?->id,
        ]);

        if ($status === NhifMemberVerification::STATUS_LOOKUP_FOUND) {
            $priorVerify = $insurance->nhifMemberVerifications()
                ->where('verification_status', NhifMemberVerification::STATUS_VERIFIED)
                ->latest()
                ->first();
            $priorReq = $priorVerify?->verify_card_request ?? [];

            $verifierId = $verifierId ?: ($priorReq['verifierID'] ?? null);
            $cardTypeId = $cardTypeId ?: ($priorReq['cardTypeID'] ?? null);
            if (blank($remarks) && ! empty($priorReq['remarks'])) {
                $remarks = $priorReq['remarks'];
            }
            if (blank($referralNo) && ! empty($priorReq['referralNo'])) {
                $referralNo = $priorReq['referralNo'];
            }

            if (blank($remarks) && $visitTypeId > 1) {
                $remarks = 'Patient verification';
            }

            $this->validateVisitTypeRequirements($visitTypeId, $remarks, $referralNo);
            $this->performVerifyCard($record, $insurance, $visitTypeId, $request, $verifierId, $cardTypeId, $remarks, $referralNo, $biometricMethod);
            $record->refresh();
        }

        // Propagate the final verification status (set by performVerifyCard) to PatientInsurance.
        $finalStatus = $record->verification_status;
        $isVerified = ($finalStatus === NhifMemberVerification::STATUS_VERIFIED);
        $newVerificationStatus = $isVerified
            ? PatientInsurance::VERIFICATION_VERIFIED
            : PatientInsurance::VERIFICATION_FAILED;

        $insuranceUpdates = ['verification_status' => $newVerificationStatus];

        if ($isVerified) {
            $insuranceUpdates['verified_at'] = now();
            if ($record->nhif_authorization_no && ! in_array(strtoupper(trim((string) $record->nhif_authorization_no)), ['N/A', 'NULL', 'NONE'])) {
                $insuranceUpdates['nhif_authorization_no'] = $record->nhif_authorization_no;
            }
        }

        if ($nhifCardNo && blank($insurance->card_number)) {
            $insuranceUpdates['card_number'] = $nhifCardNo;
        }

        if ($record->nhif_scheme_id) {
            $insuranceUpdates['nhif_scheme_id'] = $record->nhif_scheme_id;
        }

        if ($record->nhif_facility_code) {
            $insuranceUpdates['nhif_facility_code'] = $record->nhif_facility_code;
        }

        $pfNumber = $memberData['PFNumber'] ?? $memberData['pfNumber'] ?? null;
        if (filled($pfNumber) && blank($insurance->nhif_pf_number)) {
            $insuranceUpdates['nhif_pf_number'] = $pfNumber;
        }

        $insurance->update($insuranceUpdates);

        if ($record->nhif_scheme_id && blank($insurance->getOriginal('nhif_scheme_id'))) {
            $this->auditLog->record('NHIF_SCHEME_ID_CAPTURED', $insurance, null, [
                'nhif_scheme_id' => $record->nhif_scheme_id,
            ], $request);
        }

        $this->auditLog->record(
            'nhif_member_verification',
            $insurance,
            ['verification_status' => PatientInsurance::VERIFICATION_PENDING],
            ['verification_status' => $newVerificationStatus, 'nhif_status' => $finalStatus],
            $request,
        );

        return $record;
    }

    /**
     * Fetch the list of card verifiers from NHIF for the given card number.
     * Used by the pre-registration flow to populate the verifier selector.
     *
     * @return array{success: bool, data: array<mixed>, error: string|null}
     */
    public function fetchVerifiers(string $cardNo, Request $request): array
    {
        $result = $this->client->getCardVerifiers($cardNo);

        $this->auditLog->record('NHIF_CARD_VERIFIERS_RETRIEVED', null, null, [
            'card_no_prefix' => substr($cardNo, 0, 6),
        ], $request);

        return [
            'success' => $result['success'] ?? false,
            'data' => $result['data'] ?? [],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Fetch visit types from NHIF.
     * Used by the pre-registration flow to populate the visit type selector.
     *
     * @return array{success: bool, data: array<mixed>, error: string|null}
     */
    public function fetchVisitTypes(Request $request): array
    {
        $result = $this->client->getVisitTypes();

        $this->auditLog->record('NHIF_VISIT_TYPES_RETRIEVED', null, null, [], $request);

        return [
            'success' => $result['success'] ?? false,
            'data' => $result['data'] ?? [],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Look up an NHIF member by NIN or card number BEFORE a patient (or
     * PatientInsurance) record exists, for the NHIF-first registration
     * auto-fill flow. Unlike verify(), there is no PatientInsurance yet to
     * attach identity-matching or persistence to — this only calls NHIF,
     * audits the attempt, and returns a normalized result for the
     * registration form to pre-fill. The receptionist still reviews the
     * result before anything is saved, and the authoritative verify() call
     * still runs (against the real PatientInsurance record) once the
     * patient is actually registered — see PatientController::store().
     *
     * @return array{success: bool, status: string, data: array<string, mixed>|null, error: string|null}
     */
    public function lookup(string $identifierType, string $identifierValue, Request $request): array
    {
        $this->auditLog->record(
            'nhif_registration_lookup_requested',
            null,
            null,
            ['identifier_type' => $identifierType],
            $request,
        );

        [$apiResult, $operation] = $this->callApi($identifierType, $identifierValue);
        $memberData = $apiResult['data'] ?? [];

        if (! $apiResult['success'] || blank($memberData)) {
            $status = $this->statusFromHttpFailure($apiResult) === NhifMemberVerification::STATUS_NOT_FOUND
                ? 'not_found'
                : 'api_error';

            $this->auditLog->record('nhif_registration_lookup_failed', null, null, [
                'identifier_type' => $identifierType,
                'nhif_operation' => $operation,
                'status' => $status,
            ], $request);

            return [
                'success' => false,
                'status' => $status,
                'data' => null,
                'error' => $apiResult['error'] ?? 'Unable to find NHIF member. Please verify the Card Number/NIN.',
            ];
        }

        if (! $this->isCardActive($memberData)) {
            $this->auditLog->record('nhif_registration_lookup_failed', null, null, [
                'identifier_type' => $identifierType,
                'nhif_operation' => $operation,
                'status' => 'member_inactive',
            ], $request);

            return [
                'success' => false,
                'status' => 'member_inactive',
                'data' => null,
                'error' => 'NHIF membership is inactive, expired, or suspended.',
            ];
        }

        $normalized = $this->normalize($memberData);

        $this->auditLog->record(
            $identifierType === NhifMemberVerification::TYPE_NIN ? 'nhif_nin_details_retrieved' : 'nhif_card_details_retrieved',
            null,
            null,
            [
                'identifier_type' => $identifierType,
                'nhif_operation' => $operation,
                'membership_number' => $normalized['membership_number'],
            ],
            $request,
        );

        return [
            'success' => true,
            'status' => 'found',
            'data' => $normalized,
            'error' => null,
        ];
    }

    /**
     * Normalize a raw NHIF GetCardDetails/GetCardDetailsByNIN response into
     * the flat structure the registration form auto-fill consumes. Prefers
     * the discrete FirstName/MiddleName/LastName fields NHIF returns; falls
     * back to splitting FullName (see splitName()) only when those are
     * absent, as a defensive fallback for a differently-shaped response.
     *
     * @param  array<string, mixed>  $memberData
     * @return array<string, mixed>
     */
    protected function normalize(array $memberData): array
    {
        $fullName = $memberData['FullName'] ?? $memberData['MemberName'] ?? null;
        $firstName = $memberData['FirstName'] ?? null;
        $middleName = $memberData['MiddleName'] ?? null;
        $lastName = $memberData['LastName'] ?? null;

        if (blank($firstName) && blank($lastName) && filled($fullName)) {
            [$firstName, $middleName, $lastName] = $this->splitName($fullName);
        }

        return [
            'member_name' => filled($fullName) ? trim(preg_replace('/\s+/', ' ', (string) $fullName)) : null,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'gender' => $this->normalizeGender($memberData['Gender'] ?? null),
            'date_of_birth' => $this->normalizeDate($memberData['DateOfBirth'] ?? null),
            'membership_number' => $memberData['MembershipNo'] ?? $memberData['MembershipNumber'] ?? null,
            'card_number' => $memberData['CardNo'] ?? $memberData['CardNumber'] ?? null,
            'nin' => $memberData['CHNationalID'] ?? $memberData['NIN'] ?? $memberData['Nin'] ?? null,
            'plan_name' => $memberData['SchemeName'] ?? $memberData['PlanName'] ?? null,
            'expiry_date' => $this->normalizeDate($memberData['ExpiryDate'] ?? $memberData['CardExpiryDate'] ?? null),
            'authorization_no' => $memberData['AuthorizationNo'] ?? $memberData['authorizationNo'] ?? $memberData['AuthNo'] ?? null,
            'nhif_scheme_id' => $memberData['SchemeID'] ?? $memberData['schemeID'] ?? $memberData['schemeId'] ?? null,
            'pf_number' => $memberData['PFNumber'] ?? $memberData['pfNumber'] ?? null,
        ];
    }

    /**
     * Split a single "First Middle Last" name string into first/middle/last
     * parts for the HMS patient form, which stores them as separate
     * columns. Only used as a fallback when NHIF's discrete name fields are
     * absent — see normalize().
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($parts)) {
            return [null, null, null];
        }

        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        $first = array_shift($parts);
        $last = array_pop($parts);

        return [$first, implode(' ', $parts), $last];
    }

    /** Map NHIF's "Male"/"Female" to the HMS patient gender enum, or null if unrecognized. */
    protected function normalizeGender(?string $gender): ?string
    {
        if (blank($gender)) {
            return null;
        }

        $normalized = strtolower(trim($gender));

        return in_array($normalized, ['male', 'female'], true) ? $normalized : null;
    }

    /** Convert an NHIF ISO datetime string (e.g. "2008-03-12T00:00:00") to a plain Y-m-d date, or null if unparseable. */
    protected function normalizeDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the card/member is currently active, per whichever status
     * fields the response includes. Prefers the explicit IsActive/
     * IsValidCard booleans when present; falls back to a string status
     * field (CardStatus, or the older Status/MemberStatus shape) otherwise.
     *
     * @param  array<string, mixed>  $memberData
     */
    protected function isCardActive(array $memberData): bool
    {
        if (array_key_exists('IsActive', $memberData) || array_key_exists('IsValidCard', $memberData)) {
            return (bool) ($memberData['IsActive'] ?? true) && (bool) ($memberData['IsValidCard'] ?? true);
        }

        $status = strtolower($memberData['CardStatus'] ?? $memberData['Status'] ?? $memberData['MemberStatus'] ?? 'active');

        return ! in_array($status, ['inactive', 'expired', 'suspended', 'cancelled'], true);
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
        $nhifName = $memberData['FullName'] ?? $memberData['MemberName'] ?? '';
        $nhifNin = $memberData['CHNationalID'] ?? $memberData['NIN'] ?? $memberData['Nin'] ?? null;

        if (! $this->isCardActive($memberData)) {
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

        return [NhifMemberVerification::STATUS_LOOKUP_FOUND, $nhifName ?: null];
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

    /**
     * Steps 2+3 of the three-step NHIF verification flow: (GetCardVerifiers →) VerifyCard.
     *
     * Called only when GetCardDetails succeeded (STATUS_LOOKUP_FOUND). Updates the record
     * to STATUS_VERIFIED on success, STATUS_VERIFICATION_FAILED on failure.
     */
    protected function performVerifyCard(
        NhifMemberVerification $record,
        PatientInsurance $insurance,
        int $visitTypeId,
        Request $request,
        ?string $verifierId = null,
        ?string $cardTypeId = null,
        ?string $remarks = null,
        ?string $referralNo = null,
        string $biometricMethod = 'NONE',
    ): void {
        $insurance->loadMissing('patient');

        $hospital = $request->user()?->staff()?->with('hospital')->first()?->hospital;
        $facilityCode = $hospital?->nhif_facility_code ?? $hospital?->code ?? '';
        $cardNo = $record->nhif_card_no ?? $record->identifier_value;

        // Fetch verifiers only when the user did not supply a verifierID.
        $selectedVerifier = null;
        if ($verifierId === null) {
            $verifiersResult = $this->client->getCardVerifiers($cardNo);
            $verifiers = $verifiersResult['data'] ?? [];
            if (is_array($verifiers) && ! empty($verifiers)) {
                $selectedVerifier = collect($verifiers)->first(function ($v) {
                    return strtoupper((string) ($v['verifierID'] ?? $v['VerifierID'] ?? '')) === 'NHIF';
                }) ?? $verifiers[0];
                $verifierId = $selectedVerifier['verifierID'] ?? $selectedVerifier['VerifierID'] ?? $facilityCode;
            } else {
                $verifierId = $facilityCode;
            }
        }

        if ($cardTypeId === null) {
            if ($selectedVerifier) {
                $cardTypes = $selectedVerifier['cardTypes'] ?? $selectedVerifier['CardTypes'] ?? [];
                if (! empty($cardTypes) && is_array($cardTypes)) {
                    $cardTypeId = $cardTypes[0]['cardTypeID'] ?? $cardTypes[0]['CardTypeID'] ?? null;
                }
            }
        }

        $cardTypeId = $cardTypeId ?: '100000001';

        $payload = [
            'cardNo' => $cardNo,
            'verifierID' => $verifierId,
            'cardTypeID' => $cardTypeId,
            'biometricMethod' => $biometricMethod,
            'fpCode' => null,
            'imageData' => null,
            'visitTypeID' => $visitTypeId,
            'referralNo' => $referralNo,
            'remarks' => $remarks,
            'wcfNotificationNo' => null,
            'mobileNo' => $insurance->patient?->phone,
            'facilityCode' => $facilityCode,
        ];

        try {
            $result = $this->client->verifyCard($payload);

            $authNoFromVerify = $result['data']['authorizationNo']
                ?? $result['data']['AuthorizationNo']
                ?? $result['data']['AuthNo']
                ?? $result['data']['authNo']
                ?? $result['data']['tokenNo']
                ?? $result['data']['TokenNo']
                ?? null;

            $schemeIdFromVerify = $result['data']['schemeID']
                ?? $result['data']['SchemeID']
                ?? $result['data']['schemeId']
                ?? null;
            $facilityCodeFromVerify = $result['data']['facilityCode']
                ?? $result['data']['FacilityCode']
                ?? null;

            $isSuccess = ($result['success'] ?? false) && filled($authNoFromVerify);

            if ($isSuccess) {
                $record->update([
                    'verification_status' => NhifMemberVerification::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'verify_card_request' => $payload,
                    'verify_card_response' => $result,
                    'nhif_authorization_no' => $authNoFromVerify,
                    'nhif_scheme_id' => $schemeIdFromVerify,
                    'nhif_facility_code' => $facilityCodeFromVerify,
                ]);

                $this->auditLog->record('NHIF_VERIFY_CARD_SUCCESS', $insurance, null, [
                    'card_no' => $cardNo,
                    'visit_type_id' => $visitTypeId,
                ], $request);

                $this->auditLog->record('NHIF_AUTHORIZATION_CAPTURED', $insurance, null, [
                    'authorization_no' => $authNoFromVerify,
                ], $request);
            } else {
                $responseData = $result['data'] ?? [];
                $authStatus = $responseData['authorizationStatus'] ?? null;
                $nhifRemarks = $responseData['remarks'] ?? null;

                if ($authStatus === 'REJECTED' && filled($nhifRemarks)) {
                    $nhifError = $nhifRemarks;
                } else {
                    $nhifError = $result['error'] ?? 'VerifyCard did not return an authorization number.';
                }

                // Log to file — survives the DB transaction rollback in PatientController.
                Log::warning('NHIF VerifyCard failed', [
                    'card_no_prefix' => substr((string) $cardNo, 0, 6),
                    'visit_type_id' => $visitTypeId,
                    'http_status' => $result['http_status'] ?? null,
                    'authorization_status' => $authStatus,
                    'error' => $nhifError,
                ]);

                try {
                    $record->update([
                        'verification_status' => NhifMemberVerification::STATUS_VERIFICATION_FAILED,
                        'verify_card_request' => $payload,
                        'verify_card_response' => $result,
                        'error_message' => $nhifError,
                    ]);

                    $this->auditLog->record('NHIF_VERIFY_CARD_FAILED', $insurance, null, [
                        'card_no' => $cardNo,
                        'error' => $nhifError,
                    ], $request);
                } catch (Throwable $updateEx) {
                    Log::error('NHIF VerifyCard: failed to persist failure record', [
                        'error' => $updateEx->getMessage(),
                        'original_nhif_error' => $nhifError,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $exceptionMsg = $e->getMessage();

            // Log to file — survives the DB transaction rollback in PatientController.
            Log::warning('NHIF VerifyCard threw exception', [
                'card_no_prefix' => substr((string) $cardNo, 0, 6),
                'visit_type_id' => $visitTypeId,
                'exception' => $exceptionMsg,
                'exception_class' => get_class($e),
            ]);

            try {
                $record->update([
                    'verification_status' => NhifMemberVerification::STATUS_VERIFICATION_FAILED,
                    'verify_card_request' => $payload,
                    'error_message' => $exceptionMsg,
                ]);

                $this->auditLog->record('NHIF_VERIFY_CARD_FAILED', $insurance, null, [
                    'card_no' => $cardNo,
                    'error' => $exceptionMsg,
                ], $request);
            } catch (Throwable $updateEx) {
                Log::error('NHIF VerifyCard: failed to persist exception record', [
                    'error' => $updateEx->getMessage(),
                    'original_exception' => $exceptionMsg,
                ]);
            }
        }
    }

    /**
     * Validate that the selected visit type's requirements (remarks, referral number)
     * are satisfied before calling VerifyCard. Fetches visit types from NHIF.
     *
     * @throws \InvalidArgumentException when a required field is blank
     */
    protected function validateVisitTypeRequirements(int $visitTypeId, ?string $remarks, ?string $referralNo): void
    {
        $result = $this->client->getVisitTypes();
        $visitTypes = collect($result['data'] ?? []);

        $vt = $visitTypes->firstWhere('VisitTypeID', $visitTypeId)
            ?? $visitTypes->firstWhere('visitTypeID', $visitTypeId);

        if (! $vt) {
            return;
        }

        if (($vt['RequiresRemarks'] ?? $vt['requiresRemarks'] ?? false) && blank($remarks)) {
            throw new \InvalidArgumentException('Remarks are required for the selected visit type.');
        }

        if (($vt['RequiresReferralNo'] ?? $vt['requiresReferralNo'] ?? false) && blank($referralNo)) {
            throw new \InvalidArgumentException('Referral number is required for the selected visit type.');
        }
    }
}
