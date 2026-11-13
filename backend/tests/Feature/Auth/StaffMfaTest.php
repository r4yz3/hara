<?php

namespace Tests\Feature\Auth;

use App\Application\Commands\StaffMfa;
use App\Domain\Identity\Totp;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Backlog B-09 / ADR-011: the TOTP second step for staff.
 */
class StaffMfaTest extends AuthTestCase
{
    private const T0 = 1_700_000_010;

    private const GENERIC = [
        'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'These credentials do not match our records.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::T0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    /**
     * Staff with a confirmed factor, set up through the service at T0.
     *
     * @return array{user: User, secret: string, recovery: list<string>}
     */
    private function staffWithTotp(string $email = 'staff@example.com'): array
    {
        $user = $this->user($email);
        $this->grantRole($user, 'platform_owner');

        $this->travelSteps(0);
        $mfa = app(StaffMfa::class);
        $secret = $mfa->begin($user)['secret'] ?? $this->fail('enrolment did not start');
        $recovery = $mfa->confirm($user, Totp::code($secret, Totp::stepAt(self::T0)), '127.0.0.1') ?? $this->fail('confirm failed');

        // The confirming step is used up; move on to the next ones.
        $this->travelSteps(2);

        return ['user' => $user, 'secret' => $secret, 'recovery' => $recovery];
    }

    private function travelSteps(int $steps): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::T0 + $steps * Totp::PERIOD));
    }

    private function codeAt(string $secret, int $stepsFromT0): string
    {
        return Totp::code($secret, Totp::stepAt(self::T0) + $stepsFromT0);
    }

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<JsonResponse>
     */
    private function verify(array $body): TestResponse
    {
        return $this->via('admin')->postJson('/api/v1/auth/mfa/verify', $body);
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    public function test_login_with_a_confirmed_factor_asks_for_the_second_step_and_signs_nobody_in(): void
    {
        $this->staffWithTotp();

        $response = $this->login('admin', 'staff@example.com')->assertOk();

        $this->assertSame(['mfa_required' => true], $response->json('data'));
        $this->assertGuest();
        $this->via('admin')->getJson('/api/v1/me')->assertUnauthorized();
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
        $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertUnauthorized();
        $this->assertNull(DB::table('users')->where('email', 'staff@example.com')->value('last_login_at'));
    }

    public function test_a_wrong_password_still_gives_the_generic_answer_for_mfa_staff(): void
    {
        $this->staffWithTotp();

        $this->login('admin', 'staff@example.com', 'wrong-password')->assertStatus(401)->assertJson(self::GENERIC);
    }

    public function test_the_correct_code_completes_sign_in(): void
    {
        ['user' => $user, 'secret' => $secret] = $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->verify(['code' => $this->codeAt($secret, 2)])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.surface', 'admin')
            ->assertJsonPath('data.mfa_enabled', true)
            ->assertCookie('fd_admin_session');

        $this->assertAuthenticatedAs($user);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    public function test_sign_in_regenerates_the_session_id(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();
        $before = $this->app['session.store']->getId();

        $this->verify(['code' => $this->codeAt($secret, 2)])->assertOk();

        $this->assertNotSame($before, $this->app['session.store']->getId());
    }

    public function test_a_wrong_code_gets_the_same_401_as_a_wrong_password_and_is_audited(): void
    {
        ['user' => $user] = $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->verify(['code' => '000000'])->assertStatus(401)->assertJson(self::GENERIC);

        $this->assertGuest();
        $this->assertContains('auth.mfa_failed', $this->auditActions());
        $this->assertSame($user->id, DB::table('audit_logs')->where('action', 'auth.mfa_failed')->value('actor_user_id'));
    }

    public function test_a_malformed_code_is_a_failure_not_a_hint(): void
    {
        $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->verify(['code' => 'abcdef'])->assertStatus(401)->assertJson(self::GENERIC);
        $this->verify([])->assertStatus(422);
    }

    public function test_the_same_code_cannot_be_used_twice_in_one_step(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $code = $this->codeAt($secret, 2);

        $this->login('admin', 'staff@example.com')->assertOk();
        $this->verify(['code' => $code])->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->login('admin', 'staff@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->verify(['code' => $code])->assertStatus(401)->assertJson(self::GENERIC);
        // An older step than the last accepted one is no way round it.
        $this->verify(['code' => $this->codeAt($secret, 1)])->assertStatus(401);
        $this->assertGuest();
    }

    #[TestWith([-1])]
    #[TestWith([0])]
    #[TestWith([1])]
    public function test_clock_drift_of_one_step_is_accepted(int $offset): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->travelSteps(10);
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->verify(['code' => $this->codeAt($secret, 10 + $offset)])->assertOk();
    }

    #[TestWith([-2])]
    #[TestWith([2])]
    public function test_clock_drift_of_two_steps_is_refused(int $offset): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->travelSteps(10);
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->verify(['code' => $this->codeAt($secret, 10 + $offset)])->assertStatus(401);
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once_and_is_audited(): void
    {
        ['recovery' => $recovery] = $this->staffWithTotp();

        $this->login('admin', 'staff@example.com')->assertOk();
        // Case, spaces and dashes do not matter.
        $this->verify(['recovery_code' => '  '.strtoupper($recovery[0]).' '])->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->login('admin', 'staff@example.com')->assertOk();
        $this->verify(['recovery_code' => $recovery[0]])->assertStatus(401)->assertJson(self::GENERIC);
        $this->verify(['recovery_code' => $recovery[1]])->assertOk();

        $methods = DB::table('audit_logs')->where('action', 'auth.mfa_verified')->orderBy('id')->pluck('after_json')
            ->map(fn (string $json) => json_decode($json, true)['method'])->all();
        $this->assertSame(['recovery_code', 'recovery_code'], $methods);
        $this->assertSame(2, DB::table('user_mfa_recovery_codes')->whereNotNull('used_at')->count());
    }

    public function test_failed_verifications_are_rate_limited_per_account_and_address(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->staffWithTotp('other@example.com');

        $this->login('admin', 'staff@example.com')->assertOk();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify(['code' => '000000'])->assertStatus(401);
        }

        $this->verify(['code' => '000000'])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        // Even the right code is refused while the limit holds.
        $this->verify(['code' => $this->codeAt($secret, 2)])->assertStatus(429);
        // Another account from the same address is not locked out.
        $this->via('admin')->postJson('/api/v1/auth/logout');
        $this->login('admin', 'other@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
    }

    public function test_the_pending_marker_expires_after_five_minutes(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();

        Carbon::setTestNow(Carbon::now()->addSeconds(301));

        $this->verify(['code' => Totp::code($secret, Totp::stepAt(Carbon::now()->getTimestamp()))])
            ->assertStatus(401)->assertJson(self::GENERIC);
        $this->assertGuest();
    }

    public function test_verify_without_a_pending_sign_in_is_refused(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();

        $this->verify(['code' => $this->codeAt($secret, 2)])->assertStatus(401)->assertJson(self::GENERIC);
    }

    public function test_a_pending_marker_is_not_a_session_on_the_other_surfaces(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->via('customer')->postJson('/api/v1/auth/mfa/verify', ['code' => $this->codeAt($secret, 2)])->assertStatus(401);
        $this->assertGuest();
    }

    public function test_customer_and_merchant_sign_in_are_unchanged_even_with_a_factor_on_file(): void
    {
        ['user' => $user] = $this->staffWithTotp();

        $this->login('customer', 'staff@example.com')->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.mfa_required');
        $this->assertAuthenticatedAs($user);
        $this->via('customer')->postJson('/api/v1/auth/mfa/verify', ['code' => '123456'])->assertStatus(401);
    }

    public function test_a_session_whose_factor_appeared_later_must_sign_in_again(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk();
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();

        // Another session confirms a factor; this password-only one stops working.
        $secret = app(StaffMfa::class)->begin($user)['secret'];
        app(StaffMfa::class)->confirm($user, Totp::code($secret, Totp::stepAt(self::T0)), null);

        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
    }

    public function test_enrolment_flow_end_to_end(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk()->assertJsonPath('data.mfa_enabled', false);

        $start = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated();
        $secret = $start->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $uri = $start->json('data.otpauth_uri');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('issuer='.rawurlencode((string) config('app.name')), $uri);
        $this->assertStringContainsString('staff%40example.com', $uri);

        // Encrypted at rest: the column holds neither the secret nor anything base32-looking.
        $stored = (string) DB::table('user_mfa_factors')->where('user_id', $user->id)->value('secret');
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));

        // Starting again replaces the unconfirmed secret.
        $second = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated()->json('data.secret');
        $this->assertNotSame($secret, $second);
        $this->assertSame(1, DB::table('user_mfa_factors')->count());
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($secret, Totp::stepAt(self::T0))])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertFalse(app(StaffMfa::class)->isConfirmed($user->id));

        $confirm = $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($second, Totp::stepAt(self::T0))])
            ->assertOk();
        $codes = $confirm->json('data.recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertSame(['recovery_codes'], array_keys($confirm->json('data')));

        // Recovery codes are stored only as hashes.
        $hashes = DB::table('user_mfa_recovery_codes')->pluck('code_hash')->all();
        $this->assertCount(10, $hashes);
        foreach ($codes as $code) {
            $this->assertNotContains($code, $hashes);
            $this->assertNotContains(str_replace('-', '', $code), $hashes);
        }

        // Once confirmed: no second enrolment, no second confirmation.
        $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($second, Totp::stepAt(self::T0))])->assertStatus(409);
        $this->assertSame($second, Crypt::decryptString((string) DB::table('user_mfa_factors')->where('user_id', $user->id)->value('secret')));

        // The local session carries on, and /me says so without revealing anything.
        $me = $this->via('admin')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_enabled', true);
        $this->assertStringNotContainsString($second, $me->getContent());

        $this->assertSame(['auth.mfa_enrolled', 'auth.mfa_failed'], collect($this->auditActions())->sort()->values()->all());
    }

    public function test_confirming_with_nothing_started_is_refused_and_a_wrong_code_is_rate_limited(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => '123456'])->assertStatus(422);

        $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated();
        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => '000000'])->assertStatus(422);
        }
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => '000000'])->assertStatus(429);
    }

    public function test_only_the_admin_surface_can_enrol(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->postJson('/api/v1/me/mfa/totp')->assertNotFound();
        $this->via('customer')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => '123456'])->assertNotFound();
        $this->assertSame(0, DB::table('user_mfa_factors')->count());
    }

    public function test_enrolment_routes_need_a_session(): void
    {
        $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertUnauthorized();
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => '123456'])->assertUnauthorized();
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_a_deployed_staff_session_without_a_factor_can_only_enrol(string $environment): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->app['env'] = $environment;
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->login('admin', 'staff@example.com')->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $this->assertAuthenticatedAs($user);

        foreach (['/api/v1/me', '/api/v1/admin/cities', '/api/v1/admin/system/status'] as $path) {
            $this->via('admin')->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('error.message', 'Set up two-step sign-in before using the admin console.');
        }

        $secret = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated()->json('data.secret');
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($secret, Totp::stepAt(self::T0))])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes');

        // Confirming ends this session: the next sign-in needs the second step.
        $this->assertGuest();
        $this->via('admin')->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('admin', 'staff@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();

        $this->travelSteps(2);
        $this->verify(['code' => Totp::code($secret, Totp::stepAt(self::T0) + 2)])->assertOk();
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $this->via('admin')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_enabled', true);
    }

    public function test_an_enrolment_only_session_can_sign_out(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->login('admin', 'staff@example.com')->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_deployed_staff_with_a_factor_get_a_normal_session_after_the_second_step(): void
    {
        ['secret' => $secret] = $this->staffWithTotp();
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->login('admin', 'staff@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->verify(['code' => $this->codeAt($secret, 2)])->assertOk();

        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $this->via('admin')->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_deployed_session_with_no_second_step_marker_is_ended(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->app['env'] = 'production';
        $this->withSession([
            'surface' => 'admin',
            'password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()),
        ]);

        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_secrets_and_codes_never_reach_audit_rows_or_other_responses(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk();

        $secret = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->json('data.secret');
        $code = Totp::code($secret, Totp::stepAt(self::T0));
        $codes = $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => $code])->json('data.recovery_codes');
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $responses = [
            $this->login('admin', 'staff@example.com'),
            $this->verify(['code' => '000000']),
            $this->verify(['recovery_code' => $codes[0]]),
            $this->via('admin')->getJson('/api/v1/me'),
        ];
        $body = implode(' ', array_map(fn ($response): string => (string) $response->getContent(), $responses));
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString($codes[1], $body);

        $audit = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsString($code, $audit);
        foreach ($codes as $recoveryCode) {
            $this->assertStringNotContainsString($recoveryCode, $audit);
        }
        $this->assertEqualsCanonicalizing(
            ['auth.mfa_enrolled', 'auth.mfa_failed', 'auth.mfa_verified'],
            array_unique($this->auditActions()),
        );
    }
}
