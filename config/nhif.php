<?php

$isProduction = env('APP_ENV') === 'production';

return [
    /*
     * OAuth2 client-credentials token endpoint.
     * All credentials remain server-side; they are never sent to the browser.
     * In production, this MUST be explicitly set via NHIF_AUTH_URL; fallback to test is blocked.
     */
    'auth_url' => env('NHIF_AUTH_URL', $isProduction ? null : 'https://test.nhif.or.tz/auth/connect/token'),

    /*
     * NHIF ServiceHub base URL — member verification, service catalog, preauth.
     * In production, this MUST be explicitly set via NHIF_SERVICEHUB_BASE_URL.
     */
    'servicehub_base_url' => env('NHIF_SERVICEHUB_BASE_URL', $isProduction ? null : 'https://test.nhif.or.tz/servicehub'),

    /*
     * NHIF OCS base URL — online claim system.
     * In production, this MUST be explicitly set via NHIF_OCS_BASE_URL.
     */
    'ocs_base_url' => env('NHIF_OCS_BASE_URL', $isProduction ? null : 'https://test.nhif.or.tz/ocs'),

    'client_id' => env('NHIF_CLIENT_ID'),

    'client_secret' => env('NHIF_CLIENT_SECRET'),

    /*
     * OAuth2 scope — NHIF requires 'OnlineServices' for ServiceHub API access.
     */
    'scope' => env('NHIF_SCOPE', 'OnlineServices'),

    /*
     * Extra username parameter required by the NHIF token endpoint.
     * Use 'test' for the sandbox environment only.
     */
    'username' => env('NHIF_USERNAME', $isProduction ? null : 'test'),

    'timeout' => (int) env('NHIF_TIMEOUT', 30),

    'connect_timeout' => (int) env('NHIF_CONNECT_TIMEOUT', 10),

    /*
     * Cache key used to store the current access token between requests.
     */
    'token_cache_key' => env('NHIF_TOKEN_CACHE_KEY', 'nhif_access_token'),

    /*
     * Refresh the token this many seconds before it actually expires to avoid
     * race conditions on busy servers.
     */
    'token_expiry_buffer' => (int) env('NHIF_TOKEN_EXPIRY_BUFFER', 60),

    'verify_ssl' => (bool) env('NHIF_VERIFY_SSL', true),

    /*
     * NHIF item_type_id values that classify consultation services.
     * Used as a fallback filter when consultation_type_name_keywords is empty.
     *
     * To discover actual values: SELECT DISTINCT item_type_id, type_name FROM nhif_scheme_services;
     */
    'consultation_item_type_ids' => env('NHIF_CONSULTATION_ITEM_TYPE_IDS')
        ? array_map('intval', explode(',', env('NHIF_CONSULTATION_ITEM_TYPE_IDS')))
        : [],

    /*
     * Keywords used to match consultation services by type_name (case-insensitive LIKE).
     * The walk-in form shows NHIF scheme services whose type_name contains any of these.
     * Set NHIF_CONSULTATION_TYPE_KEYWORDS= (empty) to disable and fall back to item_type_id.
     *
     * NHIF typically returns 'Registration/Consultation Charges' for outpatient consultations.
     */
    'consultation_type_name_keywords' => array_filter(
        explode(',', env('NHIF_CONSULTATION_TYPE_KEYWORDS', 'Consultation,Registration/Consultation'))
    ),

    /*
     * NHIF item_type_id values for laboratory tests.
     * Common value: 2 = Laboratory.
     */
    'laboratory_item_type_ids' => array_map(
        'intval',
        explode(',', env('NHIF_LABORATORY_ITEM_TYPE_IDS', '2'))
    ),

    /*
     * NHIF item_type_id values for pharmacy/medications.
     * Common value: 3 = Pharmacy.
     */
    'pharmacy_item_type_ids' => array_map(
        'intval',
        explode(',', env('NHIF_PHARMACY_ITEM_TYPE_IDS', '3'))
    ),
];
