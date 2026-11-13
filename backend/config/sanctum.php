<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

$host = static function (string $url): string {
    $parts = parse_url($url);

    return ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
};

return [

    /*
    | Hosts whose requests get cookie sessions: exactly the three app origins.
    | Everything else is stateless.
    */
    'stateful' => array_values(array_filter([
        $host((string) env('CUSTOMER_APP_URL', 'http://localhost:3000')),
        $host((string) env('MERCHANT_APP_URL', 'http://localhost:3001')),
        $host((string) env('ADMIN_APP_URL', 'http://localhost:3002')),
    ])),

    // Sanctum's own /sanctum/csrf-cookie route is not registered: its cookie
    // name is shared by all three surfaces, so the apps read a per-session
    // token from GET /api/v1/auth/csrf instead.
    'routes' => false,

    'guard' => ['web'],

    // Personal access tokens (rider app) arrive with the mobile sign-in slice.
    'expiration' => 60,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],

];
