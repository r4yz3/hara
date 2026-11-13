<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_live_returns_minimal_body(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_routes_run_outside_the_session_middleware(): void
    {
        foreach (['health.live', 'health.ready'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertNotContains('web', $route->gatherMiddleware());
            $this->assertNotContains('api', $route->gatherMiddleware());
        }
    }

    public function test_live_does_not_touch_the_session_store(): void
    {
        // A database session driver with no reachable database must not matter.
        // The connection stays defined (test teardown resolves it); only its host is dead.
        config([
            'session.driver' => 'database',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 1,
        ]);

        $this->getJson('/health/live')->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_ready_is_hidden_from_public_addresses(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/health/ready')
            ->assertNotFound();
    }

    public function test_ready_reports_only_dependency_state_to_internal_callers(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/health/ready');

        $this->assertContains($response->status(), [200, 503]);
        $this->assertSame(['status', 'checks'], array_keys($response->json()));
        $this->assertSame(['database', 'redis'], array_keys($response->json('checks')));
        $this->assertIsBool($response->json('checks.database'));
        $this->assertIsBool($response->json('checks.redis'));
    }

    public function test_framework_default_health_route_is_not_registered(): void
    {
        $this->get('/up')->assertNotFound();
    }

    public function test_no_later_phase_api_routes_exist(): void
    {
        foreach (['orders', 'restaurants', 'carts', 'checkout/quote', 'payments/intents', 'rider/location', 'admin/dispatch/map'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertNotFound();
        }
    }
}
