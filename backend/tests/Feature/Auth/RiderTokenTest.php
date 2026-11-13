<?php

namespace Tests\Feature\Auth;

use App\Application\Commands\PasswordRecovery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Backlog B-08 / spec 6.2 and 7.3 (MobileTokenScopeTest): the rider app signs
 * in with a short-lived, narrowly scoped bearer token. Tokens and browser
 * sessions never work on each other's routes.
 */
class RiderTokenTest extends AuthTestCase
{
    private const DEVICE = ['device_name' => 'Test phone', 'platform' => 'android'];

    protected function setUp(): void
    {
        parent::setUp();

        // The rider app is not a browser: it sends no Origin header.
        $this->withoutHeader('Origin');
    }

    private function rider(string $email = 'rider@example.com', string $status = 'approved'): User
    {
        $user = $this->user($email);
        DB::table('riders')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'status' => $status,
            'approved_at' => $status === 'pending' ? null : now(),
        ]);

        return $user;
    }

    /** @return TestResponse<JsonResponse> */
    private function issue(string $email = 'rider@example.com', string $password = self::PASSWORD): TestResponse
    {
        $this->fresh();

        return $this->postJson('/api/v1/auth/mobile/token', ['email' => $email, 'password' => $password] + self::DEVICE);
    }

    /** Each request must authenticate on its own, as it would over the network. */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @return TestResponse<JsonResponse> */
    private function withToken2(string $token, string $method, string $uri): TestResponse
    {
        $this->fresh();

        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $uri);
    }

    public function test_an_approved_rider_receives_a_scoped_short_lived_token(): void
    {
        $user = $this->rider();

        $response = $this->issue()->assertStatus(201);

        $this->assertSame(['token', 'token_type', 'abilities', 'expires_at'], array_keys($response->json('data')));
        $response->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.abilities', ['rider:profile']);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, strtotime($response->json('data.expires_at')), 5);

        $stored = DB::table('personal_access_tokens')->sole();
        $plain = explode('|', $response->json('data.token'), 2)[1];
        $this->assertSame($user->id, $stored->tokenable_id);
        $this->assertSame(hash('sha256', $plain), $stored->token);
        $this->assertStringNotContainsString($plain, json_encode($stored));

        $device = DB::table('devices')->sole();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame('android', $device->platform);
        $this->assertSame($device->id, $stored->name);
    }

    public function test_every_refusal_looks_the_same(): void
    {
        $this->rider();
        $this->user('customer@example.com');
        $this->rider('pending@example.com', 'pending');
        $this->rider('suspended-rider@example.com', 'suspended');
        $suspendedUser = $this->rider('suspended-user@example.com');
        DB::table('users')->where('id', $suspendedUser->id)->update(['status' => 'suspended']);

        $responses = [
            $this->issue('rider@example.com', 'wrong-password'),
            $this->issue('nobody@example.com'),
            $this->issue('customer@example.com'),
            $this->issue('pending@example.com'),
            $this->issue('suspended-rider@example.com'),
            $this->issue('suspended-user@example.com'),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(401);
            $this->assertSame('AUTH_REQUIRED', $response->json('error.code'));
            $this->assertSame($responses[0]->json('error.message'), $response->json('error.message'));
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('devices')->count());
    }

    public function test_the_token_opens_the_riders_own_profile_and_nothing_secret(): void
    {
        $user = $this->rider();
        $token = $this->issue()->json('data.token');

        $response = $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertOk();

        $this->assertSame(['id', 'user_id', 'name', 'email', 'status', 'approved_at'], array_keys($response->json('data')));
        $response->assertJsonPath('data.user_id', $user->id)->assertJsonPath('data.status', 'approved');
    }

    public function test_requests_without_a_valid_token_are_refused(): void
    {
        $this->rider();

        $this->fresh();
        $this->getJson('/api/v1/rider/me')->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->withToken2('1|not-a-real-token', 'GET', '/api/v1/rider/me')->assertStatus(401);
        $this->withToken2('garbage', 'GET', '/api/v1/rider/me')->assertStatus(401);
    }

    public function test_a_token_stops_working_after_an_hour(): void
    {
        $this->rider();
        $token = $this->issue()->json('data.token');

        $this->travel(59)->minutes();
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertOk();

        $this->travel(2)->minutes();
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);
    }

    public function test_refresh_replaces_the_token_and_the_old_one_dies(): void
    {
        $this->rider();
        $old = $this->issue()->json('data.token');

        $new = $this->withToken2($old, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(201)->json('data.token');

        $this->assertNotSame($old, $new);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, DB::table('devices')->count(), 'refreshing keeps the same device');
        $this->withToken2($old, 'GET', '/api/v1/rider/me')->assertStatus(401);
        $this->withToken2($new, 'GET', '/api/v1/rider/me')->assertOk();
        // A stolen old token cannot be used to mint a new one.
        $this->withToken2($old, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(401);
    }

    public function test_refresh_stops_thirty_days_after_sign_in(): void
    {
        $this->rider();
        $token = (string) $this->issue()->json('data.token');
        $device = (string) DB::table('devices')->value('id');

        // Day 29: refresh still works.
        DB::table('devices')->where('id', $device)->update(['created_at' => now()->subDays(29)]);
        $this->fresh();
        $token = (string) $this->withToken2($token, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(201)->json('data.token');

        // Day 30 and later: refused, the token and device are ended, and the rider must sign in again.
        DB::table('devices')->where('id', $device)->update(['created_at' => now()->subDays(30)->subMinute()]);
        $this->fresh();
        $this->withToken2($token, 'POST', '/api/v1/auth/mobile/token/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNotNull(DB::table('devices')->where('id', $device)->value('revoked_at'));
        $this->fresh();
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);

        $this->issue()->assertCreated();
    }

    public function test_a_refreshed_token_never_outlives_the_thirty_days(): void
    {
        $this->rider();
        $token = (string) $this->issue()->json('data.token');
        $started = now()->subDays(30)->addMinutes(20);
        DB::table('devices')->update(['created_at' => $started]);

        $this->fresh();
        $expires = (string) $this->withToken2($token, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(201)->json('data.expires_at');

        // 20 minutes left of the 30 days, not a fresh 60.
        $this->assertSame($started->copy()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z'), $expires);
    }

    public function test_sign_out_revokes_the_token_and_the_device(): void
    {
        $this->rider();
        $token = $this->issue()->json('data.token');

        $this->withToken2($token, 'DELETE', '/api/v1/auth/mobile/token')->assertNoContent();

        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNotNull(DB::table('devices')->value('revoked_at'));
    }

    public function test_a_rider_token_does_not_work_on_browser_or_admin_routes(): void
    {
        $user = $this->rider();
        $this->grantRole($user, 'platform_owner');
        $token = $this->issue()->json('data.token');

        $this->withToken2($token, 'GET', '/api/v1/me')->assertStatus(401);
        $this->withToken2($token, 'GET', '/api/v1/admin/cities')->assertStatus(401);

        $this->fresh();
        $this->withHeader('Authorization', 'Bearer '.$token)->via('admin')->getJson('/api/v1/admin/cities')->assertStatus(401);
        $this->fresh();
        $this->withHeader('Authorization', 'Bearer '.$token)->via('admin')->postJson('/api/v1/admin/cities', [])->assertStatus(401);
    }

    public function test_a_browser_session_does_not_work_on_rider_routes(): void
    {
        $this->rider();
        $this->login('customer', 'rider@example.com')->assertOk();

        // Signed in on the customer app, no bearer token.
        $this->via('customer')->getJson('/api/v1/rider/me')->assertStatus(401);
        $this->via('customer')->postJson('/api/v1/auth/mobile/token/refresh')->assertStatus(401);
    }

    public function test_suspension_cuts_off_an_existing_token_on_the_next_request(): void
    {
        $user = $this->rider();
        $token = $this->issue()->json('data.token');
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertOk();

        DB::table('riders')->where('user_id', $user->id)->update(['status' => 'suspended']);
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);

        DB::table('riders')->where('user_id', $user->id)->update(['status' => 'approved']);
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);
        $this->withToken2($token, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(401);
    }

    public function test_a_password_reset_revokes_every_token_and_device(): void
    {
        $rider = $this->rider();
        $first = (string) $this->issue()->assertCreated()->json('data.token');
        $second = (string) $this->issue()->assertCreated()->json('data.token');
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $rider->id)->count());

        // A reset link as PasswordRecovery stores it: only the hash of the token.
        $plain = Str::random(64);
        DB::table('password_reset_tokens')->insert(['email' => $rider->email, 'token' => hash('sha256', $plain), 'created_at' => now()]);

        // Someone who stole a rider's token must lose it when the rider resets the password.
        $this->assertTrue(app(PasswordRecovery::class)->reset($plain, 'a-brand-new-passphrase'));

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $rider->id)->count());
        $this->assertSame(0, DB::table('devices')->where('user_id', $rider->id)->whereNull('revoked_at')->count());
        foreach ([$first, $second] as $token) {
            $this->fresh();
            $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);
            $this->fresh();
            $this->withToken2($token, 'POST', '/api/v1/auth/mobile/token/refresh')->assertStatus(401);
        }

        // The new password works on a new device.
        $this->issue(password: 'a-brand-new-passphrase')->assertCreated();
    }

    public function test_a_revoked_device_cuts_off_its_token(): void
    {
        $this->rider();
        $token = $this->issue()->json('data.token');

        DB::table('devices')->update(['revoked_at' => now()]);

        $this->withToken2($token, 'GET', '/api/v1/rider/me')->assertStatus(401);
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $this->rider();

        for ($i = 0; $i < 5; $i++) {
            $this->issue('rider@example.com', 'wrong')->assertStatus(401);
        }

        $this->issue('rider@example.com', 'wrong')->assertStatus(429);
        $this->issue()->assertStatus(429);
    }

    public function test_device_details_are_validated(): void
    {
        $this->rider();

        $this->fresh();
        $this->postJson('/api/v1/auth/mobile/token', ['email' => 'rider@example.com', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['device_name', 'platform']]]);
        $this->postJson('/api/v1/auth/mobile/token', ['email' => 'rider@example.com', 'password' => self::PASSWORD, 'device_name' => 'x', 'platform' => 'web'])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}
