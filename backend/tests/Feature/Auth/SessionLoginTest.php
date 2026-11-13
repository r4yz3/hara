<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionLoginTest extends AuthTestCase
{
    private const GENERIC = [
        'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'These credentials do not match our records.'],
    ];

    public function test_valid_credentials_sign_the_user_in_on_the_customer_surface(): void
    {
        $user = $this->user();

        $response = $this->login('customer', 'person@example.com');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'person@example.com')
            ->assertCookie('fd_customer_session');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    public function test_response_never_contains_secrets(): void
    {
        $this->user();

        $body = $this->login('customer', 'person@example.com')->json();

        $this->assertArrayNotHasKey('password', $body['data']);
        $this->assertArrayNotHasKey('remember_token', $body['data']);
        $this->assertStringNotContainsString('$argon', json_encode($body));
        $this->assertStringNotContainsString('$2y$', json_encode($body));
    }

    public function test_email_is_matched_without_regard_to_case_or_spaces(): void
    {
        $this->user();

        $this->login('customer', '  Person@Example.COM ')->assertOk();
    }

    public function test_wrong_password_unknown_account_and_suspended_account_look_identical(): void
    {
        $this->user();
        $suspended = $this->user('suspended@example.com');
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'suspended']);

        $wrongPassword = $this->login('customer', 'person@example.com', 'not-the-password');
        $unknown = $this->login('customer', 'nobody@example.com');
        $isSuspended = $this->login('customer', 'suspended@example.com');

        foreach ([$wrongPassword, $unknown, $isSuspended] as $response) {
            $response->assertStatus(401)->assertJson(self::GENERIC);
            $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        }
        $this->assertGuest();
    }

    public function test_login_requires_a_known_app_origin(): void
    {
        $this->user();

        $this->withoutHeader('Origin')->postJson('/api/v1/auth/login', ['email' => 'person@example.com', 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/v1/auth/login', ['email' => 'person@example.com', 'password' => self::PASSWORD])
            ->assertStatus(403);

        $this->assertGuest();
    }

    public function test_missing_fields_are_a_validation_error(): void
    {
        $this->via('customer')->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['email', 'password'], 'request_id']]);
    }

    public function test_a_customer_cannot_sign_in_to_the_admin_or_merchant_surface(): void
    {
        $this->user();

        $this->login('admin', 'person@example.com')->assertStatus(401)->assertJson(self::GENERIC);
        $this->login('merchant', 'person@example.com')->assertStatus(401)->assertJson(self::GENERIC);
        $this->assertGuest();
    }

    public function test_staff_sign_in_to_the_admin_surface_with_its_own_cookie(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'platform_admin');

        $this->login('admin', 'staff@example.com')
            ->assertOk()
            ->assertCookie('fd_admin_session')
            ->assertCookieMissing('fd_customer_session');
    }

    public function test_a_revoked_staff_grant_no_longer_opens_the_admin_surface(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'platform_admin');
        DB::table('user_role_scopes')->where('user_id', $staff->id)->update(['revoked_at' => now()]);

        $this->login('admin', 'staff@example.com')->assertStatus(401)->assertJson(self::GENERIC);
    }

    public function test_merchant_surface_needs_an_active_staff_membership(): void
    {
        $member = $this->user('cook@example.com');
        $merchantId = strtolower((string) Str::ulid());
        DB::table('merchants')->insert(['id' => $merchantId, 'legal_name' => 'Test Merchant', 'display_name' => 'Test Merchant', 'slug' => 'test-merchant']);
        DB::table('merchant_staff')->insert([
            'id' => strtolower((string) Str::ulid()), 'merchant_id' => $merchantId, 'user_id' => $member->id, 'role' => 'staff',
        ]);

        $this->login('merchant', 'cook@example.com')->assertOk()->assertCookie('fd_merchant_session');

        $this->via('merchant')->postJson('/api/v1/auth/logout')->assertNoContent();
        DB::table('merchant_staff')->where('user_id', $member->id)->update(['status' => 'revoked']);

        $this->login('merchant', 'cook@example.com')->assertStatus(401);
    }

    public function test_admin_sign_in_is_refused_in_production_until_two_step_verification_exists(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'platform_owner');

        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->login('admin', 'staff@example.com')
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'FORBIDDEN');
            $this->assertGuest();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_repeated_failures_are_rate_limited_per_account(): void
    {
        $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login('customer', 'person@example.com', 'wrong')->assertStatus(401);
        }

        $this->login('customer', 'person@example.com', 'wrong')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        // Even the right password is refused while the limit holds.
        $this->login('customer', 'person@example.com')->assertStatus(429);
        // Another account from the same address is not locked out.
        $this->user('other@example.com');
        $this->login('customer', 'other@example.com')->assertOk();
    }
}
