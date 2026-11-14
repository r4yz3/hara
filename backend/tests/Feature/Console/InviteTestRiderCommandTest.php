<?php

namespace Tests\Feature\Console;

use App\Mail\TestRiderInvitation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestCase;

/** ADR-021: `rider:invite-test`, a synthetic rider whose password the operator chooses by email link. */
class InviteTestRiderCommandTest extends AuthTestCase
{
    private const CHOSEN = 'a-brand-new-passphrase';

    private const EMAIL = 'test.rider@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{int, string}
     */
    private function invite(array $args = ['email' => self::EMAIL]): array
    {
        $code = Artisan::call('rider:invite-test', $args);

        return [$code, Artisan::output()];
    }

    private function sentUrl(): string
    {
        $url = '';
        Mail::assertSent(TestRiderInvitation::class, function (TestRiderInvitation $m) use (&$url): bool {
            $url = $m->url;

            return true;
        });

        return $url;
    }

    private function tokenOf(string $url): string
    {
        return substr($url, strpos($url, '#token=') + 7);
    }

    /** @return array<string, string> */
    private function device(): array
    {
        return ['email' => self::EMAIL, 'password' => self::CHOSEN, 'device_name' => 'Phone', 'platform' => 'android'];
    }

    public function test_it_creates_an_approved_rider_and_mails_a_fragment_link_to_the_customer_app(): void
    {
        [$code, $out] = $this->invite(['email' => ' Test.Rider@Example.com ', '--name' => 'QA rider']);

        $this->assertSame(0, $code, $out);
        $user = DB::table('users')->where('email', self::EMAIL)->sole();
        $this->assertSame('QA rider', $user->name);
        $rider = DB::table('riders')->where('user_id', $user->id)->sole();
        $this->assertSame('approved', $rider->status);
        $this->assertNotNull($rider->approved_at);
        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('customer_profiles')->count());
        foreach (['rider_city_access', 'rider_vehicles'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }

        Mail::assertSentCount(1);
        Mail::assertSent(TestRiderInvitation::class, fn (TestRiderInvitation $m): bool => $m->hasTo(self::EMAIL));
        $url = $this->sentUrl();
        $this->assertMatchesRegularExpression('~^http://localhost:3000/reset-password#token=[0-9a-f]{64}$~', $url);
        $this->assertStringNotContainsString('?', $url);
        $this->assertStringNotContainsString($this->tokenOf($url), $out);
        $this->assertStringNotContainsString(self::EMAIL, $out);
    }

    public function test_the_operator_sets_a_password_and_the_rider_then_gets_a_rider_token(): void
    {
        $this->invite();
        $token = $this->tokenOf($this->sentUrl());

        // No password works before the link is used.
        $this->withoutHeader('Origin');
        $this->postJson('/api/v1/auth/mobile/token', $this->device())->assertStatus(401);

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::CHOSEN])->assertOk();

        $this->withoutHeader('Origin');
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/v1/auth/mobile/token', $this->device())->assertStatus(201);
        $this->assertSame(['rider:profile'], $response->json('data.abilities'));

        // The link works once.
        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'another-passphrase-1'])->assertStatus(422);
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        $this->invite();
        $token = $this->tokenOf($this->sentUrl());

        $this->assertSame(hash('sha256', $token), DB::table('password_reset_tokens')->where('email', self::EMAIL)->value('token'));
    }

    public function test_running_it_again_reuses_the_account_and_replaces_the_link(): void
    {
        $this->invite();
        $firstHash = DB::table('password_reset_tokens')->value('token');
        [$code, $out] = $this->invite();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('reused', $out);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('riders')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        Mail::assertSentCount(2);
        $this->assertNotSame($firstHash, DB::table('password_reset_tokens')->value('token'));
    }

    public function test_it_refuses_staff_customers_and_riders_it_did_not_make(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'support');
        $customer = $this->user('customer@example.com');
        DB::table('customer_profiles')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        $real = $this->user('real.rider@example.com');
        DB::table('riders')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $real->id, 'status' => 'approved', 'approved_at' => now()]);

        foreach (['staff@example.com', 'customer@example.com', 'real.rider@example.com'] as $email) {
            [$code, $out] = $this->invite(['email' => $email]);
            $this->assertSame(1, $code, $email);
            $this->assertStringNotContainsString($email, $out);
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'rider_test.%')->count());
    }

    public function test_it_refuses_a_suspended_test_rider(): void
    {
        $this->invite();
        DB::table('riders')->update(['status' => 'suspended']);

        [$code] = $this->invite();

        $this->assertSame(1, $code);
        Mail::assertSentCount(1);
    }

    public function test_it_rejects_a_bad_address_and_is_rate_limited(): void
    {
        RateLimiter::clear('rider-invite-test:'.hash('sha256', self::EMAIL));
        [$code] = $this->invite(['email' => 'not-an-address']);
        $this->assertSame(2, $code);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $this->invite()[0]);
        }
        [$code, $out] = $this->invite();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Too many', $out);
        Mail::assertSentCount(3);
    }

    public function test_the_audit_log_records_the_console_actor_and_no_token_or_address(): void
    {
        $this->invite();
        $token = $this->tokenOf($this->sentUrl());

        $rows = DB::table('audit_logs')->where('action', 'like', 'rider_test.%')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->actor_user_id);
            $this->assertSame('system/console', json_decode($row->after_json, true)['actor']);
            $this->assertStringNotContainsString(self::EMAIL, json_encode($row));
            $this->assertStringNotContainsString($token, json_encode($row));
        }
        $created = $rows->firstWhere('action', 'rider_test.created');
        $this->assertTrue(json_decode($created->after_json, true)['synthetic']);
    }

    public function test_it_adds_no_http_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('invite-test', $route->uri());
            $this->assertStringNotContainsString('rider-invite', $route->uri());
        }
    }
}
