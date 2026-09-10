<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only the API paths participate in CORS. Allowed origins come from
    | config/api.php (the API_ALLOWED_ORIGINS env var) so an authenticated API
    | is never opened to arbitrary origins. Credentials support is off because
    | the API authenticates with bearer tokens, not cookies.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => config('api.allowed_origins', []),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-ID'],

    'max_age' => 0,

    'supports_credentials' => false,

];
