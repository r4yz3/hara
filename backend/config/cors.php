<?php

/*
| Cross-origin rules (spec 4.3): an explicit allowlist of the three app
| origins, with credentials. A wildcard origin together with credentials is
| forbidden, so there is none here and there are no origin patterns.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('CUSTOMER_APP_URL', 'http://localhost:3000'), '/'),
        rtrim((string) env('MERCHANT_APP_URL', 'http://localhost:3001'), '/'),
        rtrim((string) env('ADMIN_APP_URL', 'http://localhost:3002'), '/'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-CSRF-TOKEN', 'X-Requested-With', 'X-Request-Id', 'Idempotency-Key'],

    // Retry-After lets the sign-in screens show how long to wait after a 429.
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];
