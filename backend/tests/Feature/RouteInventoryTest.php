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
            'GET|HEAD health/live',
            'GET|HEAD health/ready',
        ], $registered);
    }
}
