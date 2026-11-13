<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The registered routes must match this list exactly. Adding a route means
 * adding it here in the same change, so nothing is exposed by accident
 * (framework defaults included).
 */
class RouteInventoryTest extends TestCase
{
    public function test_only_expected_routes_are_registered(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'DELETE api/v1/admin/exclusions/{exclusion}',
            'DELETE api/v1/auth/mobile/token',
            'GET|HEAD api/v1/admin/audit-logs',
            'GET|HEAD api/v1/admin/cities',
            'GET|HEAD api/v1/admin/cities/{city}',
            'GET|HEAD api/v1/admin/cities/{city}/zones',
            'GET|HEAD api/v1/admin/system/status',
            'GET|HEAD api/v1/admin/zones/{zone}',
            'GET|HEAD api/v1/admin/zones/{zone}/exclusions',
            'GET|HEAD api/v1/auth/csrf',
            'GET|HEAD api/v1/me',
            'GET|HEAD api/v1/public/cities',
            'GET|HEAD api/v1/public/config',
            'GET|HEAD api/v1/rider/me',
            'GET|HEAD health/live',
            'GET|HEAD health/ready',
            'PATCH api/v1/admin/cities/{city}',
            'PATCH api/v1/admin/zones/{zone}',
            'POST api/v1/admin/cities',
            'POST api/v1/admin/cities/{city}/zones',
            'POST api/v1/admin/users/{user}/mfa/reset',
            'POST api/v1/admin/zones/{zone}/activate',
            'POST api/v1/admin/zones/{zone}/archive',
            'POST api/v1/admin/zones/{zone}/exclusions',
            'POST api/v1/admin/zones/{zone}/pause',
            'POST api/v1/auth/login',
            'POST api/v1/auth/logout',
            'POST api/v1/auth/mfa/verify',
            'POST api/v1/auth/mobile/token',
            'POST api/v1/auth/mobile/token/refresh',
            'POST api/v1/auth/password/forgot',
            'POST api/v1/auth/password/reset',
            'POST api/v1/auth/register',
            'POST api/v1/locations/serviceability',
            'POST api/v1/me/mfa/recovery-codes',
            'POST api/v1/me/mfa/totp',
            'POST api/v1/me/mfa/totp/confirm',
        ], $registered);
    }
}
