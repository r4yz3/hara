<?php

/*
| The three browser surfaces and the exact origin each is served from
| (ADR-011). A request is attributed to a surface only when its Origin header
| equals one of these values. Each surface gets its own session cookie, so a
| customer session can never be presented to the admin or merchant surface.
*/

return [

    'origins' => [
        'customer' => rtrim((string) env('CUSTOMER_APP_URL', 'http://localhost:3000'), '/'),
        'merchant' => rtrim((string) env('MERCHANT_APP_URL', 'http://localhost:3001'), '/'),
        'admin' => rtrim((string) env('ADMIN_APP_URL', 'http://localhost:3002'), '/'),
    ],

    // Roles that open the admin surface. Anyone else is turned away at sign-in.
    'staff_roles' => ['platform_owner', 'platform_admin', 'city_manager', 'dispatcher', 'finance', 'support'],

    'login' => [
        'max_attempts' => 5,
        'decay_seconds' => 60,
    ],

];
