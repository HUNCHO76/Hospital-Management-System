<?php

namespace App\Services\NHIF;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Low-level HTTP client for the NHIF Tanzania API (Phase 8).
 *
 * Uses the OAuth2 client-credentials flow: a short-lived Bearer token is
 * fetched on first use, cached, and automatically refreshed when it nears
 * expiry. Credentials (client_id, client_secret) are read from config/nhif.php
 * and NEVER leave the server.
 *
 * All public methods return a normalized response array:
 * [
 *   'success'        => bool,
 *   'data'           => array,        // parsed response body
 *   'http_status'    => int,
 *   'error'          => string|null,
 *   'correlation_id' => string,       // always set, echoes NHIF header or generated
 * ]
 */
class NhifHttpClient
{
    public function __construct(
        protected ?string $authUrl = null,
        protected ?string $servicehubBaseUrl = null,
        protected ?string $clientId = null,
        protected ?string $clientSecret = null,
        protected ?string $scope = null,
        protected ?string $username = null,
        protected int $timeout = 30,
        protected int $connectTimeout = 10,
        protected string $tokenCacheKey = 'nhif_access_token',
        protected int $tokenExpiryBuffer = 60,
        protected bool $verifySsl = true,
    ) {
        $this->authUrl = $authUrl ?? config('nhif.auth_url');
        $this->servicehubBaseUrl = $servicehubBaseUrl ?? config('nhif.servicehub_base_url');
        $this->clientId = $clientId ?? config('nhif.client_id');
        $this->clientSecret = $clientSecret ?? config('nhif.client_secret');
        $this->scope = $scope ?? config('nhif.scope', 'OnlineServices');
        $this->username = $username ?? config('nhif.username', 'test');
        $this->timeout = $timeout ?: (int) config('nhif.timeout', 30);
        $this->connectTimeout = $connectTimeout ?: (int) config('nhif.connect_timeout', 10);
        $this->tokenCacheKey = $tokenCacheKey ?: config('nhif.token_cache_key', 'nhif_access_token');
        $this->tokenExpiryBuffer = $tokenExpiryBuffer ?: (int) config('nhif.token_expiry_buffer', 60);
        $this->verifySsl = $verifySsl && (bool) config('nhif.verify_ssl', true);
    }

    /**
     * Look up a member by their National Identification Number via the
     * Verification API's GetCardDetailsByNIN operation.
     *
     * Returns a normalized result array (see class docblock).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMemberByNin(string $nin): array
    {
        return $this->get('/api/Verification/GetCardDetailsByNIN', [
            'nationalID' => $nin,
            'visitTypeID' => 1,
        ], 'GetCardDetailsByNIN');
    }

    /**
     * Look up a member by their NHIF card number via the Verification API's
     * GetCardDetails operation.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMemberByCard(string $cardNumber): array
    {
        return $this->get('/api/Verification/GetCardDetails', [
            'cardNo' => $cardNumber,
            'visitTypeID' => 1,
        ], 'GetCardDetails');
    }

    /**
     * Check whether a member is currently eligible for NHIF cover.
     *
     * Proxied through GetCardDetails (Verification API) which returns all the
     * fields needed to determine eligibility (card status, scheme, expiry, etc.)
     * and is available in the current NHIF sandbox. The legacy
     * /v2/Members/CheckEligibility endpoint is not available.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function checkEligibility(string $cardNumber): array
    {
        return $this->get('/api/Verification/GetCardDetails', [
            'cardNo' => $cardNumber,
            'visitTypeID' => 1,
        ], 'CheckEligibility');
    }

    /**
     * Register a practitioner login attendance with NHIF.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function loginPractitioner(string $practitionerNo, string $facilityCode): array
    {
        return $this->post('/v2/Practitioners/LoginPractitioner', [
            'PractitionerNo' => $practitionerNo,
            'FacilityCode' => $facilityCode,
        ], 'LoginPractitioner');
    }

    /**
     * Register a practitioner logout attendance with NHIF.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function logoutPractitioner(string $practitionerNo, string $facilityCode): array
    {
        return $this->post('/v2/Practitioners/LogoutPractitioner', [
            'PractitionerNo' => $practitionerNo,
            'FacilityCode' => $facilityCode,
        ], 'LogoutPractitioner');
    }

    /**
     * Fetch the NHIF service (item) list for a facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getServiceList(?string $facilityCode = null, ?string $facilityLevel = null): array
    {
        $query = array_filter([
            'FacilityCode' => $facilityCode,
            'FacilityLevel' => $facilityLevel,
        ]);

        return $this->get('/api/Packages/GetSchemesServices', $query, 'GetServiceList');
    }

    /**
     * Fetch NHIF packages (benefit packages/schemes).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPackageList(): array
    {
        return $this->get('/v2/PriceLists/GetPackageList', [], 'GetPackageList');
    }

    /**
     * Request preauthorization for one or more services on a patient's
     * current NHIF-authorized visit (the "requested service check").
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function requestPreauthorizedServices(array $payload): array
    {
        return $this->post('/api/PreApprovals/RequestServices', $payload, 'RequestServices');
    }

    /**
     * Look up NHIF's current decision (approved/rejected/pending) for every
     * service requested under a given authorization number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getRequestServicesByAuthorizationNo(string $authorizationNo): array
    {
        return $this->get('/api/PreApprovals/GetRequestServicesByAuthorizationNo', [
            'authorizationNo' => $authorizationNo,
        ], 'GetRequestServicesByAuthorizationNo');
    }

    /**
     * Submit an admission notification to NHIF.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function submitAdmission(array $payload): array
    {
        return $this->post('/v2/Admissions/SubmitAdmission', $payload, 'SubmitAdmission');
    }

    /**
     * Submit a transfer notification to NHIF.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function submitTransfer(array $payload): array
    {
        return $this->post('/v2/Admissions/SubmitTransfer', $payload, 'SubmitTransfer');
    }

    // =========================================================================
    // Verification
    // =========================================================================

    /**
     * Retrieve the list of card verifiers for a point of care.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getCardVerifiers(string $cardNo): array
    {
        return $this->get('/api/Verification/GetCardVerifiers', ['cardNo' => $cardNo], 'GetCardVerifiers');
    }

    /**
     * Retrieve NHIF visit types.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getVisitTypes(): array
    {
        return $this->get('/api/Verification/GetVisitTypes', [], 'GetVisitTypes');
    }

    /**
     * Retrieve authorization details for the given authorization number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAuthorizationDetails(string $authorizationNo): array
    {
        return $this->get('/api/Verification/GetAuthorizationDetails', [
            'authorizationNo' => $authorizationNo,
        ], 'GetAuthorizationDetails');
    }

    /**
     * Retrieve authorization details for a card within a date range.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAuthorizationDetailsByDate(string $cardNo, string $from, string $to): array
    {
        return $this->get('/api/Verification/GetAuthorizationDetailsByDate', [
            'cardNo' => $cardNo,
            'from' => $from,
            'to' => $to,
        ], 'GetAuthorizationDetailsByDate');
    }

    /**
     * Retrieve all patients authorized within a date range.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAuthorizedPatientsByDate(string $from, string $to): array
    {
        return $this->get('/api/Verification/GetAuthorizedPatientsByDate', [
            'from' => $from,
            'to' => $to,
        ], 'GetAuthorizedPatientsByDate');
    }

    /**
     * Verify a card against the NHIF Verification API.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function verifyCard(array $payload): array
    {
        return $this->post('/api/Verification/VerifyCard', $payload, 'VerifyCard');
    }

    /**
     * Update the visit type for an active authorization.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function updateVisitType(array $payload): array
    {
        return $this->post('/api/Verification/UpdateVisitType', $payload, 'UpdateVisitType');
    }

    /**
     * Compare a live face image against the NHIF member photo.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function compareFaces(array $payload): array
    {
        return $this->post('/api/Verification/CompareFaces', $payload, 'CompareFaces');
    }

    /**
     * Generate a Point-of-Care reference number.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function generatePocReferenceNo(array $payload): array
    {
        return $this->post('/api/Verification/GeneratePOCReferenceNo', $payload, 'GeneratePOCReferenceNo');
    }

    /**
     * Retrieve beneficiary details for a member.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getBeneficiaryDetails(array $payload): array
    {
        return $this->post('/api/Verification/GetBeneficiaryDetails', $payload, 'GetBeneficiaryDetails');
    }

    /**
     * Retrieve points of care from the Verification API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPointsOfCare(): array
    {
        return $this->get('/api/Verification/GetPointsOfCare', [], 'GetPointsOfCare');
    }

    /**
     * Retrieve detailed patient data by card number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPatientDetails(string $cardNo): array
    {
        return $this->get('/api/Verification/GetPatientDetails', [
            'cardNo' => $cardNo,
        ], 'GetPatientDetails');
    }

    /**
     * Retrieve an authorization by its token number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAuthorizationByToken(string $tokenNo): array
    {
        return $this->get('/api/Verification/GetAuthorizationByToken', [
            'tokenNo' => $tokenNo,
        ], 'GetAuthorizationByToken');
    }

    /**
     * Retrieve a patient's previous visits from the Verification API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPreviousPatientVisits(string $cardNo): array
    {
        return $this->get('/api/Verification/GetPreviousPatientVisists', [
            'cardNo' => $cardNo,
        ], 'GetPreviousPatientVisists');
    }

    /**
     * Retrieve services excluded from NHIF cover for a patient.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPatientExcludedServices(string $cardNo): array
    {
        return $this->get('/api/Verification/GetPatientExcludedServices', [
            'cardNo' => $cardNo,
        ], 'GetPatientExcludedServices');
    }

    /**
     * Retrieve the percentage of a service covered by NHIF for an authorization.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPercentCovered(string $authorizationNo, string $itemCode): array
    {
        return $this->get('/api/Verification/GetPercentCovered', [
            'authorizationNo' => $authorizationNo,
            'itemCode' => $itemCode,
        ], 'GetPercentCovered');
    }

    /**
     * Retrieve the NHIF member photo for biometric verification.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMemberPicture(string $cardNo, string $verifierId): array
    {
        return $this->get('/api/Verification/GetMemberPicture', [
            'cardNo' => $cardNo,
            'verifierId' => $verifierId,
        ], 'GetMemberPicture');
    }

    // =========================================================================
    // Admissions
    // =========================================================================

    /**
     * Retrieve admission lookup values (types, ward types, discharge types, etc.).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmissionLookups(): array
    {
        return $this->get('/api/Admissions/GetAdmissionLookups', [], 'GetAdmissionLookups');
    }

    /**
     * Retrieve the list of NHIF admission types.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmissionTypes(): array
    {
        return $this->get('/api/Admissions/GetAdmissionTypes', [], 'GetAdmissionTypes');
    }

    /**
     * Retrieve the list of NHIF discharge types.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getDischargeTypes(): array
    {
        return $this->get('/api/Admissions/GetDischargeTypes', [], 'GetDischargeTypes');
    }

    /**
     * Retrieve the list of NHIF ward types.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getWardTypes(): array
    {
        return $this->get('/api/Admissions/GetWardTypes', [], 'GetWardTypes');
    }

    /**
     * Retrieve the list of NHIF room types.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getRoomTypes(): array
    {
        return $this->get('/api/Admissions/GetRoomTypes', [], 'GetRoomTypes');
    }

    /**
     * Retrieve admission details by authorization number (short form).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmissionDetailsByAuthorizationNo(string $authorizationNo): array
    {
        return $this->get('/api/Admissions/GetDetailsByAuthorizationNo', [
            'authorizationNo' => $authorizationNo,
        ], 'GetDetailsByAuthorizationNo');
    }

    /**
     * Retrieve full admission details by authorization number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmissionDetailsFull(string $authorizationNo): array
    {
        return $this->get('/api/Admissions/GetAdmissionDetailsByAuthorizationNo', [
            'authorizationNo' => $authorizationNo,
        ], 'GetAdmissionDetailsByAuthorizationNo');
    }

    /**
     * Retrieve admission types available for a given product code.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmissionTypesByProductCode(string $productCode): array
    {
        return $this->get('/api/Admissions/GetAdmissionTypesByProductCode', [
            'productCode' => $productCode,
        ], 'GetAdmissionTypesByProductCode');
    }

    /**
     * Notify NHIF of a new patient admission (spec path).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function admitPatient(array $payload): array
    {
        return $this->post('/api/Admissions/AdmitPatient', $payload, 'AdmitPatient');
    }

    /**
     * Notify NHIF of a patient discharge (spec path; note the NHIF typo "Disharge").
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function dischargePatient(array $payload): array
    {
        return $this->post('/api/Admissions/DishargePatient', $payload, 'DishargePatient');
    }

    /**
     * Notify NHIF of a patient transfer (spec path).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function transferPatient(array $payload): array
    {
        return $this->post('/api/Admissions/TransferPatient', $payload, 'TransferPatient');
    }

    /**
     * Retrieve all currently admitted patients for the facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmittedPatients(): array
    {
        return $this->get('/api/Admissions/GetAdmittedPatients', [], 'GetAdmittedPatients');
    }

    /**
     * Retrieve admitted patients for a specific facility code.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAdmittedPatientsByFacility(string $facilityCode): array
    {
        return $this->get('/api/Admissions/GetAdmittedPatientsByFacility', [
            'facilityCode' => $facilityCode,
        ], 'GetAdmittedPatientsByFacility');
    }

    /**
     * Send an overstay notification to NHIF for a long-stay admission.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function sendOverstayNotification(array $payload): array
    {
        return $this->post('/api/Admissions/SendOverstayNotification', $payload, 'SendOverstayNotification');
    }

    /**
     * Retrieve discharge lookup values.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getDischargeLookups(): array
    {
        return $this->get('/api/Admissions/GetDischargeLookups', [], 'GetDischargeLookups');
    }

    // =========================================================================
    // Approvals
    // =========================================================================

    /**
     * Retrieve approval lookup values.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovalLookups(): array
    {
        return $this->get('/api/Approvals/GetApprovalLookups', [], 'GetApprovalLookups');
    }

    /**
     * Retrieve the list of services that can be approved.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovalServices(): array
    {
        return $this->get('/api/Approvals/GetApprovalServices', [], 'GetApprovalServices');
    }

    /**
     * Retrieve approved services under an approval reference number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovedServices(string $approvalReferenceNo): array
    {
        return $this->get('/api/Approvals/GetApprovedServices', [
            'approvalReferenceNo' => $approvalReferenceNo,
        ], 'GetApprovedServices');
    }

    /**
     * Issue an approved service against an existing approval.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function issueApprovedService(array $payload): array
    {
        return $this->post('/api/Approvals/IssueApprovedService', $payload, 'IssueApprovedService');
    }

    /**
     * Cancel an issued service against an existing approval.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function issueCancelService(array $payload): array
    {
        return $this->post('/api/Approvals/IssueCancelService', $payload, 'IssueCancelService');
    }

    /**
     * Request NHIF approval for a clinical service.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function requestApproval(array $payload): array
    {
        return $this->post('/api/Approvals/RequestApproval', $payload, 'RequestApproval');
    }

    /**
     * Update an existing approval request (e.g. add or remove items).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function updateApprovalRequest(array $payload): array
    {
        return $this->post('/api/Approvals/UpdateApprovalRequest', $payload, 'UpdateApprovalRequest');
    }

    /**
     * Check eligibility for a specific service item via the Approvals API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function checkApprovalEligibility(string $cardNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/CheckEligibility', [
            'cardNo' => $cardNo,
            'itemCode' => $itemCode,
        ], 'CheckEligibility');
    }

    /**
     * Retrieve the issued approval reference number for a service item.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuedApprovalReferenceNo(string $authorizationNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/GetIssuedApprovalReferenceNo', [
            'authorizationNo' => $authorizationNo,
            'itemCode' => $itemCode,
        ], 'GetIssuedApprovalReferenceNo');
    }

    /**
     * Retrieve the issued approval reference number by card number (external lookup).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuedApprovalReferenceNoExt(string $cardNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/GetIssuedApprovalReferenceNoExt', [
            'cardNo' => $cardNo,
            'itemCode' => $itemCode,
        ], 'GetIssuedApprovalReferenceNoExt');
    }

    /**
     * Retrieve approval requests submitted within a date range.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovalRequestsByDateRange(string $from, string $to): array
    {
        return $this->get('/api/Approvals/GetRequestsByDateRange', [
            'from' => $from,
            'to' => $to,
        ], 'GetRequestsByDateRange');
    }

    /**
     * Retrieve the current NHIF approval status for an authorization.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovalStatus(string $authorizationNo): array
    {
        return $this->get('/api/Approvals/GetApprovalStatus', [
            'authorizationNo' => $authorizationNo,
        ], 'GetApprovalStatus');
    }

    /**
     * Retrieve all approvals for a card number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovals(string $cardNo): array
    {
        return $this->get('/api/Approvals/GetApprovals', [
            'cardNo' => $cardNo,
        ], 'GetApprovals');
    }

    /**
     * Retrieve issuance details by approval reference number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuanceByRefNo(string $approvalReferenceNo): array
    {
        return $this->get('/api/Approvals/GetIssuanceByRefNo', [
            'approvalReferenceNo' => $approvalReferenceNo,
        ], 'GetIssuanceByRefNo');
    }

    /**
     * Retrieve issued services within a date range.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuedServices(array $payload): array
    {
        return $this->post('/api/Approvals/GetIssuedServices', $payload, 'GetIssuedServices');
    }

    /**
     * Retrieve the approved items for an authorized item ID.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovedItems(string $authorizedItemId): array
    {
        return $this->get('/api/Approvals/GetApprovedItems', [
            'authorizedItemId' => $authorizedItemId,
        ], 'GetApprovedItems');
    }

    /**
     * Remove a supporting document from an approval request.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function removeSupportingDocument(string $documentId): array
    {
        return $this->get('/api/Approvals/RemoveSupportingDocument', [
            'documentId' => $documentId,
        ], 'RemoveSupportingDocument');
    }

    /**
     * Remove an authorized item from an approval request.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function removeAuthorizedItem(string $itemId): array
    {
        return $this->get('/api/Approvals/RemoveAuthorizedItem', [
            'itemId' => $itemId,
        ], 'RemoveAuthorizedItem');
    }

    /**
     * Retrieve a supporting document by filename.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getSupportDocument(string $fileName): array
    {
        return $this->get('/api/Approvals/GetSupportDocument', [
            'fileName' => $fileName,
        ], 'GetSupportDocument');
    }

    /**
     * Retrieve old approval requests for a given month and year.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getOldRequests(int $month, int $year): array
    {
        return $this->get('/api/Approvals/GetOldRequests', [
            'month' => $month,
            'year' => $year,
        ], 'GetOldRequests');
    }

    /**
     * Retrieve the patient files (medical file registry) for a card number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPatientFiles(string $cardNo): array
    {
        return $this->get('/api/Approvals/GetPatientFiles', [
            'cardNo' => $cardNo,
        ], 'GetPatientFiles');
    }

    /**
     * Retrieve details of a specific patient file by its file number ID.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPatientFileDetails(string $fileNumberId): array
    {
        return $this->get('/api/Approvals/GetPatientFileDetails', [
            'fileNumberId' => $fileNumberId,
        ], 'GetPatientFileDetails');
    }

    /**
     * Retrieve file registry lookup values.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getFileLookups(): array
    {
        return $this->get('/api/Approvals/GetFileLookups', [], 'GetFileLookups');
    }

    /**
     * Update a registered patient file.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function updateRegisteredFile(array $payload): array
    {
        return $this->post('/api/Approvals/UpdateRegisteredFile', $payload, 'UpdateRegisteredFile');
    }

    /**
     * Remove a service item from an approval request.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function removeServiceItem(string $itemId): array
    {
        return $this->get('/api/Approvals/RemoveServiceItem', [
            'itemId' => $itemId,
        ], 'RemoveServiceItem');
    }

    /**
     * Retrieve services excluded from NHIF cover for a card holder (Approvals API).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getExcludedServices(string $cardNo): array
    {
        return $this->get('/api/Approvals/GetExcludedServices', [
            'cardNo' => $cardNo,
        ], 'GetExcludedServices');
    }

    /**
     * Retrieve services allowed for a patient at a facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAllowedServices(string $facilityCode, int $serviceTypeId, string $cardNo): array
    {
        return $this->get('/api/Approvals/GetAllowedServices', [
            'facilityCode' => $facilityCode,
            'serviceTypeId' => $serviceTypeId,
            'cardNo' => $cardNo,
        ], 'GetAllowedServices');
    }

    /**
     * Retrieve allowed items for a scheme and product.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getAllowedItems(int $serviceTypeId, int $schemeId, string $productCode): array
    {
        return $this->get('/api/Approvals/GetAllowedItems', [
            'serviceTypeId' => $serviceTypeId,
            'schemeId' => $schemeId,
            'productCode' => $productCode,
        ], 'GetAllowedItems');
    }

    /**
     * Retrieve services included in a product/scheme combination.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIncludedServices(string $productCode, int $schemeId): array
    {
        return $this->get('/api/Approvals/GetIncludedServices', [
            'productCode' => $productCode,
            'schemeId' => $schemeId,
        ], 'GetIncludedServices');
    }

    /**
     * Retrieve services excluded from a product.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getProductExcludedServices(string $productCode): array
    {
        return $this->get('/api/Approvals/GetProductExcludedServices', [
            'productCode' => $productCode,
        ], 'GetProductExcludedServices');
    }

    /**
     * Retrieve the status of an issued approval reference number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferenceNoStatus(string $cardNo, string $referenceNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/GetReferenceNoStatus', [
            'cardNo' => $cardNo,
            'referenceNo' => $referenceNo,
            'itemCode' => $itemCode,
        ], 'GetReferenceNoStatus');
    }

    /**
     * Retrieve details of an issued approval reference number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferenceNoDetails(string $cardNo, string $referenceNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/GetReferenceNoDetails', [
            'cardNo' => $cardNo,
            'referenceNo' => $referenceNo,
            'itemCode' => $itemCode,
        ], 'GetReferenceNoDetails');
    }

    /**
     * Retrieve details of an NHIF approval number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getApprovalNoDetails(string $cardNo, string $referenceNo, string $itemCode): array
    {
        return $this->get('/api/Approvals/GetApprovalNoDetails', [
            'cardNo' => $cardNo,
            'referenceNo' => $referenceNo,
            'itemCode' => $itemCode,
        ], 'GetApprovalNoDetails');
    }

    // =========================================================================
    // PreApprovals (additional methods — requestPreauthorizedServices and
    // getRequestServicesByAuthorizationNo already exist above)
    // =========================================================================

    /**
     * Retrieve services unavailable for preapproval for a card holder.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getUnavailableServices(array $payload): array
    {
        return $this->post('/api/PreApprovals/GetUnavailableServices', $payload, 'GetUnavailableServices');
    }

    /**
     * Retrieve existing preapproval request services for an authorization.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPreApprovalRequestServices(string $authorizationNo): array
    {
        return $this->get('/api/PreApprovals/GetRequestServices', [
            'authorizationNo' => $authorizationNo,
        ], 'GetRequestServices');
    }

    /**
     * Cancel a preapproval request by request number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function cancelPreApprovalRequest(string $requestNo, string $remarks): array
    {
        return $this->post('/api/PreApprovals/CancelRequest', [
            'requestNo' => $requestNo,
            'remarks' => $remarks,
        ], 'CancelRequest');
    }

    /**
     * Cancel a preapproval request using a structured DTO payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function cancelPreApprovalRequestDto(array $payload): array
    {
        return $this->post('/api/PreApprovals/CancelPreApprovalRequest', $payload, 'CancelPreApprovalRequest');
    }

    /**
     * Issue preapproval request services once approved.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function issuePreApprovalRequestServices(array $payload): array
    {
        return $this->post('/api/PreApprovals/IssueRequestServices', $payload, 'IssueRequestServices');
    }

    /**
     * Retrieve the issued approval reference number for a preapproval item.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPreApprovalIssuedReferenceNo(string $authorizationNo, string $itemCode): array
    {
        return $this->get('/api/PreApprovals/GetIssuedApprovalReferenceNo', [
            'authorizationNo' => $authorizationNo,
            'itemCode' => $itemCode,
        ], 'PreApprovals_GetIssuedApprovalReferenceNo');
    }

    // =========================================================================
    // Attendance (spec-path aliases — existing loginPractitioner /
    // logoutPractitioner use legacy /v2/Practitioners/* paths)
    // =========================================================================

    /**
     * Register practitioner login via the spec Attendance path.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function attendanceLoginPractitioner(array $payload): array
    {
        return $this->post('/api/Attendance/LoginPractitioner', $payload, 'Attendance_LoginPractitioner');
    }

    /**
     * Register practitioner logout via the spec Attendance path.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function attendanceLogoutPractitioner(string $practitionerNo, string $facilityCode): array
    {
        return $this->post('/api/Attendance/LogoutPractitioner', [
            'PractitionerNo' => $practitionerNo,
            'FacilityCode' => $facilityCode,
        ], 'Attendance_LogoutPractitioner');
    }

    // =========================================================================
    // History
    // =========================================================================

    /**
     * Retrieve a patient's previous visit history.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPatientVisitHistory(string $cardNo): array
    {
        return $this->get('/api/History/GetPreviousPatientVisists', [
            'cardNo' => $cardNo,
        ], 'History_GetPreviousPatientVisists');
    }

    /**
     * Retrieve medical history for a patient over the past N days.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMedicalHistory(string $cardNo, int $daysPast = 60): array
    {
        return $this->get('/api/History/GetMedicalHistory', [
            'cardNo' => $cardNo,
            'daysPast' => $daysPast,
        ], 'GetMedicalHistory');
    }

    /**
     * Retrieve a visit summary for an authorization at a facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getVisitSummary(string $authorizationNo, string $facilityCode): array
    {
        return $this->get('/api/History/GetVisitSummary', [
            'authorizationNo' => $authorizationNo,
            'facilityCode' => $facilityCode,
        ], 'GetVisitSummary');
    }

    // =========================================================================
    // Issues
    // =========================================================================

    /**
     * Retrieve issues logged by an office.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuesByOffice(string $officeCode, bool $pendingOnly = false): array
    {
        return $this->get('/api/Issues/GetIssuesByOffice', [
            'officeCode' => $officeCode,
            'pendingOnly' => $pendingOnly,
        ], 'GetIssuesByOffice');
    }

    /**
     * Retrieve messages for a specific issue.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMessagesForIssue(int $issueId): array
    {
        return $this->get('/api/Issues/GetMessagesForIssue', [
            'issueId' => $issueId,
        ], 'GetMessagesForIssue');
    }

    /**
     * Retrieve all messages on an issue thread.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssueMessages(int $issueId): array
    {
        return $this->get('/api/Issues/GetIssueMessages', [
            'issueId' => $issueId,
        ], 'GetIssueMessages');
    }

    /**
     * Create a new issue with NHIF.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function createIssue(array $payload): array
    {
        return $this->post('/api/Issues/CreateIssue', $payload, 'CreateIssue');
    }

    /**
     * Close an existing issue with NHIF.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function closeIssue(array $payload): array
    {
        return $this->post('/api/Issues/CloseIssue', $payload, 'CloseIssue');
    }

    /**
     * Add a message to an existing issue thread.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function createIssueMessage(array $payload): array
    {
        return $this->post('/api/Issues/CreateMessage', $payload, 'CreateMessage');
    }

    /**
     * Retrieve issues logged by a facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getIssuesByFacility(string $facilityCode): array
    {
        return $this->get('/api/Issues/GetIssuesByFacility', [
            'facilityCode' => $facilityCode,
        ], 'GetIssuesByFacility');
    }

    // =========================================================================
    // Practitioners
    // =========================================================================

    /**
     * Retrieve the list of practitioners registered with NHIF.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPractitioners(): array
    {
        return $this->get('/api/Practitioners', [], 'GetPractitioners');
    }

    /**
     * Retrieve details for a specific practitioner by their NHIF practitioner number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getPractitionerDetails(string $practitionerNo): array
    {
        return $this->get('/api/Practitioners/details/'.$practitionerNo, [], 'GetPractitionerDetails');
    }

    /**
     * Register a new practitioner with NHIF.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function registerPractitioner(array $payload): array
    {
        return $this->post('/api/Practitioners/register', $payload, 'RegisterPractitioner');
    }

    /**
     * Terminate a practitioner's NHIF registration.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function terminatePractitioner(array $payload): array
    {
        return $this->post('/api/Practitioners/terminate', $payload, 'TerminatePractitioner');
    }

    // =========================================================================
    // Reference
    // =========================================================================

    /**
     * Retrieve points of care from the Reference API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferencePointsOfCare(): array
    {
        return $this->get('/api/Reference/GetPointsOfCare', [], 'Reference_GetPointsOfCare');
    }

    /**
     * Retrieve visit types from the Reference API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferenceVisitTypes(): array
    {
        return $this->get('/api/Reference/GetVisitTypes', [], 'Reference_GetVisitTypes');
    }

    /**
     * Retrieve the list of NHIF-registered facilities.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getFacilities(): array
    {
        return $this->get('/api/Reference/GetFacilities', [], 'GetFacilities');
    }

    /**
     * Retrieve the list of facilities available for referrals.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferralFacilities(): array
    {
        return $this->get('/api/Reference/GetReferralFacilities', [], 'GetReferralFacilities');
    }

    // =========================================================================
    // Referrals
    // =========================================================================

    /**
     * Create a service referral for a patient.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function createServiceReferral(array $payload): array
    {
        return $this->post('/api/Referrals/CreateServiceReferral', $payload, 'CreateServiceReferral');
    }

    /**
     * Acknowledge receipt of a service referral.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function acknowledgeServiceReferral(string $referralNo): array
    {
        return $this->get('/api/Referrals/AcknowledgeServiceReferral', [
            'referralNo' => $referralNo,
        ], 'AcknowledgeServiceReferral');
    }

    /**
     * Retrieve referrals for a patient by card number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferralByCardNo(string $cardNo): array
    {
        return $this->get('/api/Referrals/GetReferralByCardNo', [
            'cardNo' => $cardNo,
        ], 'GetReferralByCardNo');
    }

    /**
     * Retrieve details for a specific referral.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReferralDetails(string $referralNo): array
    {
        return $this->get('/api/Referrals/GetReferralDetails', [
            'referralNo' => $referralNo,
        ], 'GetReferralDetails');
    }

    /**
     * Retrieve the last issued services for an authorization at a receiving facility.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getLastIssuedServices(string $authorizationNo): array
    {
        return $this->get('/api/Referrals/GetLastIssuedServices', [
            'authorizationNo' => $authorizationNo,
        ], 'GetLastIssuedServices');
    }

    /**
     * Create a treatment referral for a patient.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function createTreatmentReferral(array $payload): array
    {
        return $this->post('/api/Referrals/CreateTreatmentReferral', $payload, 'CreateTreatmentReferral');
    }

    /**
     * Update an existing referral.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function updateReferral(array $payload): array
    {
        return $this->post('/api/Referrals/UpdateReferral', $payload, 'UpdateReferral');
    }

    // =========================================================================
    // OCS — Online Claim Submission (https://test.nhif.or.tz/ocs)
    // All OCS methods route through postOcs()/getOcs() which use $ocsBaseUrl.
    // =========================================================================

    /**
     * Test connectivity to the OCS endpoint.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function testOcsConnection(): array
    {
        return $this->getOcs('/api/Claims/Test', [], 'OCS_Test');
    }

    /**
     * Sign a folio before submission.
     *
     * @param  array<string, mixed>  $payload  SignatureModel
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function signFolio(array $payload): array
    {
        return $this->postOcs('/api/Claims/SignFolio', $payload, 'OCS_SignFolio');
    }

    /**
     * Submit a folio to the OCS endpoint.
     *
     * @param  array<string, mixed>  $folio  Folio model
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function submitFolio(array $folio): array
    {
        return $this->postOcs('/api/Claims/SubmitFolio', $folio, 'OCS_SubmitFolio');
    }

    /**
     * Retrieve submitted claims for a facility in a given month.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getSubmittedClaims(string $facilityCode, int $year, int $month): array
    {
        return $this->getOcs('/api/Claims/GetSubmittedClaims', [
            'facilityCode' => $facilityCode,
            'claimYear' => $year,
            'claimMonth' => $month,
        ], 'OCS_GetSubmittedClaims');
    }

    /**
     * Retrieve the receipt for a submitted folio.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getReceipt(string $facilityCode, int $year, int $month, string $folioNo): array
    {
        return $this->getOcs('/api/Claims/GetReceipt', [
            'facilityCode' => $facilityCode,
            'claimYear' => $year,
            'claimMonth' => $month,
            'folioNo' => $folioNo,
        ], 'OCS_GetReceipt');
    }

    /**
     * Send a confirmation code request to NHIF for bill confirmation.
     *
     * @param  array<string, mixed>  $payload  BillConfirmationRequestModel
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function sendConfirmationCode(array $payload): array
    {
        return $this->postOcs('/api/Claims/SendConfirmationCode', $payload, 'OCS_SendConfirmationCode');
    }

    /**
     * Request bill confirmation from NHIF.
     *
     * @param  array<string, mixed>  $payload  BillConfirmationRequestModel
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function requestBillConfirmation(array $payload): array
    {
        return $this->postOcs('/api/Claims/RequestBillConfirmation', $payload, 'OCS_RequestBillConfirmation');
    }

    /**
     * Retrieve bill confirmation status by authorization number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getBillConfirmation(string $authorizationNo): array
    {
        return $this->getOcs('/api/Claims/GetBillConfirmation', [
            'authorizationNo' => $authorizationNo,
        ], 'OCS_GetBillConfirmation');
    }

    /**
     * Submit the monthly consolidated claim to NHIF OCS.
     *
     * @param  array<string, mixed>  $payload  MonthlySubmissionModel
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function submitMonthlyClaim(array $payload): array
    {
        return $this->postOcs('/api/Claims/SubmitMonthlyClaim', $payload, 'OCS_SubmitMonthlyClaim');
    }

    /**
     * Retrieve the list of NHIF-registered facilities from the OCS API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getOcsFacilities(): array
    {
        return $this->getOcs('/api/Facilities/GetFacilities', [], 'OCS_GetFacilities');
    }

    /**
     * Retrieve item catalog from OCS Packages API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getOcsItems(): array
    {
        return $this->getOcs('/api/Packages/GetItems', [], 'OCS_GetItems');
    }

    /**
     * Retrieve item types from OCS Packages API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getOcsItemTypes(): array
    {
        return $this->getOcs('/api/Packages/GetItemTypes', [], 'OCS_GetItemTypes');
    }

    /**
     * Retrieve disease reference list from OCS Reference API.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getOcsDiseases(): array
    {
        return $this->getOcs('/api/Reference/GetDiseases', [], 'OCS_GetDiseases');
    }

    /**
     * Issue an authenticated POST request to the OCS API.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    protected function postOcs(string $path, array $payload, string $operationName): array
    {
        $ocsBaseUrl = config('nhif.ocs_base_url', '');
        $correlationId = (string) Str::uuid();

        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions([
                    'verify' => $this->verifySsl,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                ])
                ->withHeaders([
                    'X-Correlation-ID' => $correlationId,
                    'Accept' => 'application/json',
                ])
                ->post(rtrim($ocsBaseUrl, '/').$path, $payload);

            $echoedId = $response->header('X-Correlation-ID') ?: $correlationId;
            $body = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => is_array($body) ? $body : [],
                    'http_status' => $response->status(),
                    'error' => null,
                    'correlation_id' => $echoedId,
                    'operation' => $operationName,
                ];
            }

            return [
                'success' => false,
                'data' => is_array($body) ? $body : [],
                'http_status' => $response->status(),
                'error' => $this->extractError($body, $response->status()),
                'correlation_id' => $echoedId,
                'operation' => $operationName,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => 0,
                'error' => 'Could not connect to NHIF OCS service: '.$e->getMessage(),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => $e->response->status(),
                'error' => $this->extractError($e->response->json() ?? [], $e->response->status()),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        }
    }

    /**
     * Issue an authenticated GET request to the OCS API.
     *
     * @param  array<string, string|int>  $query
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    protected function getOcs(string $path, array $query, string $operationName): array
    {
        $ocsBaseUrl = config('nhif.ocs_base_url', '');
        $correlationId = (string) Str::uuid();

        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions([
                    'verify' => $this->verifySsl,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                ])
                ->withHeaders([
                    'X-Correlation-ID' => $correlationId,
                    'Accept' => 'application/json',
                ])
                ->get(rtrim($ocsBaseUrl, '/').$path, $query);

            $echoedId = $response->header('X-Correlation-ID') ?: $correlationId;
            $body = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => is_array($body) ? $body : [],
                    'http_status' => $response->status(),
                    'error' => null,
                    'correlation_id' => $echoedId,
                    'operation' => $operationName,
                ];
            }

            return [
                'success' => false,
                'data' => is_array($body) ? $body : [],
                'http_status' => $response->status(),
                'error' => $this->extractError($body, $response->status()),
                'correlation_id' => $echoedId,
                'operation' => $operationName,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => 0,
                'error' => 'Could not connect to NHIF OCS service: '.$e->getMessage(),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => $e->response->status(),
                'error' => $this->extractError($e->response->json() ?? [], $e->response->status()),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        }
    }

    /**
     * Issue an authenticated POST request to the ServiceHub API.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    protected function post(string $path, array $payload, string $operationName): array
    {
        $correlationId = (string) Str::uuid();

        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions([
                    'verify' => $this->verifySsl,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                ])
                ->withHeaders([
                    'X-Correlation-ID' => $correlationId,
                    'Accept' => 'application/json',
                ])
                ->post(rtrim((string) $this->servicehubBaseUrl, '/').$path, $payload);

            $echoedId = $response->header('X-Correlation-ID') ?: $correlationId;
            $body = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => is_array($body) ? $body : [],
                    'http_status' => $response->status(),
                    'error' => null,
                    'correlation_id' => $echoedId,
                    'operation' => $operationName,
                ];
            }

            return [
                'success' => false,
                'data' => is_array($body) ? $body : [],
                'http_status' => $response->status(),
                'error' => $this->extractError($body, $response->status()),
                'correlation_id' => $echoedId,
                'operation' => $operationName,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => 0,
                'error' => 'Could not connect to NHIF service: '.$e->getMessage(),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => $e->response->status(),
                'error' => $this->extractError($e->response->json() ?? [], $e->response->status()),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        }
    }

    /**
     * Issue an authenticated GET request to the ServiceHub API.
     *
     * @param  array<string, string>  $query
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    protected function get(string $path, array $query, string $operationName): array
    {
        $correlationId = (string) Str::uuid();

        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions([
                    'verify' => $this->verifySsl,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                ])
                ->withHeaders([
                    'X-Correlation-ID' => $correlationId,
                    'Accept' => 'application/json',
                ])
                ->get(rtrim((string) $this->servicehubBaseUrl, '/').$path, $query);

            $echoedId = $response->header('X-Correlation-ID') ?: $correlationId;
            $body = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => is_array($body) ? $body : [],
                    'http_status' => $response->status(),
                    'error' => null,
                    'correlation_id' => $echoedId,
                    'operation' => $operationName,
                ];
            }

            return [
                'success' => false,
                'data' => is_array($body) ? $body : [],
                'http_status' => $response->status(),
                'error' => $this->extractError($body, $response->status()),
                'correlation_id' => $echoedId,
                'operation' => $operationName,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => 0,
                'error' => 'Could not connect to NHIF service: '.$e->getMessage(),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'data' => [],
                'http_status' => $e->response->status(),
                'error' => $this->extractError($e->response->json() ?? [], $e->response->status()),
                'correlation_id' => $correlationId,
                'operation' => $operationName,
            ];
        }
    }

    /**
     * Return a valid Bearer token, refreshing it from the NHIF auth server when
     * it is absent or close to expiry.
     */
    protected function accessToken(): string
    {
        /** @var array{token: string, expires_at: int}|null $cached */
        $cached = Cache::get($this->tokenCacheKey);

        if ($cached && $cached['expires_at'] > (time() + $this->tokenExpiryBuffer)) {
            return $cached['token'];
        }

        return $this->fetchNewToken();
    }

    /**
     * Exchange client credentials for a new access token and cache it.
     *
     * @throws RuntimeException when the auth server rejects the credentials.
     */
    protected function fetchNewToken(): string
    {
        $response = Http::withOptions([
            'verify' => $this->verifySsl,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ])
            ->asForm()
            ->post((string) $this->authUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scope,
                'username' => $this->username,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'NHIF authentication failed (HTTP '.$response->status().'). Check NHIF_CLIENT_ID and NHIF_CLIENT_SECRET.'
            );
        }

        $body = $response->json();
        $token = $body['access_token'] ?? null;
        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        if (! $token) {
            throw new RuntimeException('NHIF authentication response did not include an access_token.');
        }

        Cache::put($this->tokenCacheKey, [
            'token' => $token,
            'expires_at' => time() + $expiresIn,
        ], $expiresIn);

        return $token;
    }

    /**
     * Extract a human-readable error message from a failed NHIF response body.
     *
     * @param  array<string, mixed>  $body
     */
    protected function extractError(array $body, int $httpStatus): string
    {
        return $body['message']
            ?? $body['error_description']
            ?? $body['error']
            ?? $body['Message']
            ?? "NHIF API request failed with HTTP {$httpStatus}.";
    }
}
