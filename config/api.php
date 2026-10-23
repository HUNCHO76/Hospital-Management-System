<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Current API Version
    |--------------------------------------------------------------------------
    |
    | The default stable API version. Routes are grouped under /api/{version}.
    | Future breaking changes ship as a new version (v2) so existing clients
    | keep working against v1.
    |
    */

    'version' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Collection endpoints paginate. Clients may request a page size with
    | ?per_page=N, clamped between 1 and `max` so a client can never request an
    | unbounded result set.
    |
    */

    'pagination' => [
        'default' => 20,
        'max' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | `auth` protects credential endpoints against brute force; `standard`
    | applies to authenticated API traffic. Both are enforced via named
    | limiters registered in AppServiceProvider.
    |
    */

    'rate_limits' => [
        'auth' => (int) env('API_RATE_LIMIT_AUTH', 5),
        'standard' => (int) env('API_RATE_LIMIT_STANDARD', 60),
        'nhif_verification' => (int) env('API_RATE_LIMIT_NHIF_VERIFICATION', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed CORS Origins
    |--------------------------------------------------------------------------
    |
    | Origins permitted to call the API from a browser. Never a wildcard for an
    | authenticated API — list the real web/mobile clients via the
    | API_ALLOWED_ORIGINS env var (comma-separated).
    |
    */

    'allowed_origins' => array_values(array_filter(
        explode(',', (string) env('API_ALLOWED_ORIGINS', ''))
    )),

];
