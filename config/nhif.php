<?php

return [
    /*
     * OAuth2 client-credentials token endpoint.
     * All credentials remain server-side; they are never sent to the browser.
     */
    'auth_url' => env('NHIF_AUTH_URL', 'https://test.nhif.or.tz/auth/connect/token'),

    /*
     * NHIF ServiceHub base URL — member verification, service catalog, preauth.
     */
    'servicehub_base_url' => env('NHIF_SERVICEHUB_BASE_URL', 'https://test.nhif.or.tz/servicehub'),

    /*
     * NHIF OCS base URL — online claim system.
     */
    'ocs_base_url' => env('NHIF_OCS_BASE_URL', 'https://test.nhif.or.tz/ocs'),

    'client_id' => env('NHIF_CLIENT_ID'),

    'client_secret' => env('NHIF_CLIENT_SECRET'),

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
];
