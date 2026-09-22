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
        $this->timeout = $timeout ?: (int) config('nhif.timeout', 30);
        $this->connectTimeout = $connectTimeout ?: (int) config('nhif.connect_timeout', 10);
        $this->tokenCacheKey = $tokenCacheKey ?: config('nhif.token_cache_key', 'nhif_access_token');
        $this->tokenExpiryBuffer = $tokenExpiryBuffer ?: (int) config('nhif.token_expiry_buffer', 60);
        $this->verifySsl = $verifySsl && (bool) config('nhif.verify_ssl', true);
    }

    /**
     * Look up a member by their National Identification Number.
     *
     * Returns a normalized result array (see class docblock).
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMemberByNin(string $nin): array
    {
        return $this->get('/v2/Members/GetMemberDetails', [
            'NIN' => $nin,
        ], 'GetMemberDetailsByNIN');
    }

    /**
     * Look up a member by their NHIF card number.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function getMemberByCard(string $cardNumber): array
    {
        return $this->get('/v2/Members/GetMemberDetails', [
            'CardNo' => $cardNumber,
        ], 'GetMemberDetailsByCard');
    }

    /**
     * Check whether a member is currently eligible for NHIF cover.
     *
     * @return array{success: bool, data: array<string, mixed>, http_status: int, error: string|null, correlation_id: string}
     */
    public function checkEligibility(string $cardNumber): array
    {
        return $this->get('/v2/Members/CheckEligibility', [
            'CardNo' => $cardNumber,
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

        return $this->get('/v2/PriceLists/GetServiceList', $query, 'GetServiceList');
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
