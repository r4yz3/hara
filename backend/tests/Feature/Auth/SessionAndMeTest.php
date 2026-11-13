<?php

namespace Tests\Feature\Auth;

use App\Models\City;
use Illuminate\Support\Facades\DB;

class SessionAndMeTest extends AuthTestCase
{
    public function test_me_requires_a_session(): void
    {
        $this->via('customer')->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_me_returns_the_user_with_grants_and_no_secret_fields(): void
    {
        $city = City::factory()->create();
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'city_manager', 'city', $city->id);
        $this->login('admin', 'staff@example.com')->assertOk();

        $response = $this->via('admin')->getJson('/api/v1/me')->assertOk();

        $this->assertSame(
            ['id', 'name', 'email', 'email_verified', 'surface', 'grants'],
            array_keys($response->json('data')),
        );
        $response->assertJsonPath('data.surface', 'admin')
            ->assertJsonPath('data.grants.0.role', 'city_manager')
            ->assertJsonPath('data.grants.0.scope_type', 'city')
            ->assertJsonPath('data.grants.0.scope_id', $city->id);
        $this->assertContains('zones.write', $response->json('data.grants.0.permissions'));
        $this->assertNotContains('cities.write', $response->json('data.grants.0.permissions'));
    }

    public function test_revoked_grants_do_not_appear_in_me(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'support');
        $this->grantRole($staff, 'platform_admin');
        $this->login('admin', 'staff@example.com')->assertOk();
        DB::table('user_role_scopes')
            ->where('user_id', $staff->id)
            ->where('role_id', DB::table('roles')->where('slug', 'platform_admin')->value('id'))
            ->update(['revoked_at' => now()]);

        $roles = array_column($this->via('admin')->getJson('/api/v1/me')->json('data.grants'), 'role');

        $this->assertSame(['support'], $roles);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest();
        $this->via('customer')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_logout_without_a_session_is_refused(): void
    {
        $this->via('customer')->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_a_customer_session_is_not_valid_on_the_admin_surface(): void
    {
        // Even for someone who is staff: the session belongs to one surface.
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'platform_admin');
        $this->login('customer', 'staff@example.com')->assertOk();

        $this->via('customer')->getJson('/api/v1/me')->assertOk();
        // The test client shares one session store across "cookies", so this
        // shows the surface check itself. That the two cookies are really
        // separate is checked with real requests (docs/qa/evidence).
        $this->via('admin')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_suspended_user_loses_their_session_on_the_next_request(): void
    {
        $user = $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

        $this->via('customer')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_staff_lose_the_admin_surface_when_their_last_staff_grant_is_revoked(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'support');
        $this->login('admin', 'staff@example.com')->assertOk();

        DB::table('user_role_scopes')->where('user_id', $staff->id)->update(['revoked_at' => now()]);

        $this->via('admin')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_csrf_endpoint_issues_a_token_only_to_known_origins(): void
    {
        $this->via('customer')->getJson('/api/v1/auth/csrf')
            ->assertOk()
            ->assertJsonStructure(['data' => ['csrf_token'], 'meta' => ['request_id']]);

        $this->withoutHeader('Origin')->getJson('/api/v1/auth/csrf')->assertStatus(403);
    }
}
