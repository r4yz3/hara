<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_live_returns_minimal_body(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
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

    public function test_no_versioned_api_routes_exist_yet(): void
    {
        $this->getJson('/api/v1/orders')->assertNotFound();
        $this->getJson('/api/v1/restaurants')->assertNotFound();
    }
}
