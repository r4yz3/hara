<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;

/**
 * "Delete my account" for customers (threat model T31, ADR-017). Everything
 * personal goes in one transaction; audit rows stay and hold the id only.
 * Positions and names here are synthetic.
 */
class AccountDeletionTest extends AuthTestCase
{
    private const URL = '/api/v1/me';

    private const EMAIL = 'alice.quillmoor@example.com';

    private const NAME = 'Alice Quillmoor';

    /** @var list<MessageLogged> */
    private array $logged = [];

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
        RateLimiter::clear('account-writes');
        $this->alice = $this->customer(self::EMAIL, self::NAME);
    }

    /** A customer as registration makes one: profile row and the customer role grant. */
    private function customer(string $email, string $name = 'Some Person'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => self::PASSWORD]);
        DB::table('customer_profiles')->insert(['id' => $this->ulid(), 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grantRole($user, 'customer');

        return $user;
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    private function address(User $user, string $line = 'Quillmoor Boulevard 4471'): void
    {
        DB::statement(
            "INSERT INTO saved_addresses (id, user_id, tag, address_line, landmark, is_default, point, created_at, updated_at)
             VALUES (?, ?, 'Home', ?, 'Wexhallow Gate', false, ST_SetSRID(ST_MakePoint(100.0123489, 10.0187651), 4326), now(), now())",
            [$this->ulid(), $user->id, $line],
        );
    }

    private function device(User $user): void
    {
        DB::table('devices')->insert(['id' => $this->ulid(), 'user_id' => $user->id, 'platform' => 'android', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function signIn(string $email = self::EMAIL, string $surface = 'customer'): void
    {
        $this->app['auth']->forgetGuards();
        $this->login($surface, $email)->assertOk();
    }

    /** @return TestResponse<JsonResponse> */
    private function deleteAccount(string $password = self::PASSWORD, string $surface = 'customer'): TestResponse
    {
        return $this->via($surface)->deleteJson(self::URL, ['password' => $password]);
    }

    /** @return array<string, int> */
    private function counts(User $user): array
    {
        $id = $user->id;

        return [
            'users' => DB::table('users')->where('id', $id)->count(),
            'addresses' => DB::table('saved_addresses')->where('user_id', $id)->count(),
            'profiles' => DB::table('customer_profiles')->where('user_id', $id)->count(),
            'grants' => DB::table('user_role_scopes')->where('user_id', $id)->count(),
            'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $id)->count(),
            'devices' => DB::table('devices')->where('user_id', $id)->count(),
            'staff' => DB::table('merchant_staff')->where('user_id', $id)->count(),
            'riders' => DB::table('riders')->where('user_id', $id)->count(),
            'factors' => DB::table('user_mfa_factors')->where('user_id', $id)->count(),
        ];
    }

    private function merchantStaff(User $user, string $status = 'active'): void
    {
        $merchant = $this->ulid();
        DB::table('merchants')->insert(['id' => $merchant, 'legal_name' => 'Sample Kitchen', 'display_name' => 'Sample Kitchen', 'slug' => 'k-'.$merchant, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('merchant_staff')->insert(['id' => $this->ulid(), 'merchant_id' => $merchant, 'user_id' => $user->id, 'role' => 'owner', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- success ----

    public function test_a_customer_deletes_their_account_and_everything_personal_goes(): void
    {
        $other = $this->customer('bob@example.com');
        $this->address($this->alice);
        $this->address($this->alice, 'Zephyrine Row 8820');
        $this->address($other);
        $this->device($this->alice);
        $this->alice->createToken('legacy');
        DB::table('password_reset_tokens')->insert(['email' => self::EMAIL, 'token' => 'hash', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sess-a', 'user_id' => $this->alice->id, 'payload' => '', 'last_activity' => time()]);
        $this->signIn();

        $this->deleteAccount()->assertNoContent();

        $this->assertSame(array_fill_keys(['users', 'addresses', 'profiles', 'grants', 'tokens', 'devices', 'staff', 'riders', 'factors'], 0), $this->counts($this->alice));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', self::EMAIL)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->alice->id)->count());
        // Another customer is untouched.
        $this->assertSame(1, $this->counts($other)['users']);
        $this->assertSame(1, $this->counts($other)['addresses']);
        $this->assertSame(1, $this->counts($other)['grants']);
    }

    public function test_the_old_session_and_a_second_session_both_stop_working(): void
    {
        $this->signIn();
        $this->via('customer')->getJson(self::URL)->assertOk();
        // A second browser holds the same valid session facts.
        $second = $this->app['session.store']->all();

        $this->deleteAccount()->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->via('customer')->getJson(self::URL)->assertUnauthorized();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($second)->via('customer')->getJson(self::URL)->assertUnauthorized();
    }

    public function test_the_old_credentials_fail_with_the_generic_failure_and_the_address_can_register_again(): void
    {
        $this->signIn();
        $this->deleteAccount()->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->login('customer', self::EMAIL)->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');

        config(['features.public_registration' => true]);
        $this->via('customer')->postJson('/api/v1/auth/register', ['name' => 'Alice Again', 'email' => self::EMAIL, 'password' => 'another-long-password'])->assertStatus(202);
        $fresh = User::query()->where('email', self::EMAIL)->first();
        $this->assertNotNull($fresh);
        $this->assertNotSame($this->alice->id, $fresh->id);
        $this->assertSame(0, DB::table('saved_addresses')->where('user_id', $fresh->id)->count());
    }

    public function test_the_user_row_is_removed_and_the_audit_row_holds_the_id_only(): void
    {
        $this->address($this->alice);
        $this->signIn();
        $this->deleteAccount()->assertNoContent();

        $this->assertNull(DB::table('users')->where('id', $this->alice->id)->first());
        $audit = DB::table('audit_logs')->where('action', 'account.deleted')->get();
        $this->assertCount(1, $audit);
        $row = $audit->first();
        $this->assertSame($this->alice->id, $row->actor_user_id);
        $this->assertSame('user', $row->subject_type);
        $this->assertSame($this->alice->id, $row->subject_id);
        $this->assertNull($row->before_json);
        $this->assertNull($row->after_json);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_earlier_audit_rows_survive(): void
    {
        $this->signIn();
        $this->via('customer')->patchJson(self::URL, ['name' => 'Alice Renamed'])->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user.profile_updated')->count());

        $this->deleteAccount()->assertNoContent();

        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.profile_updated')->exists());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'account.deleted')->count());
    }

    public function test_no_log_line_or_audit_row_holds_an_address_position_email_or_name(): void
    {
        $this->address($this->alice);
        $this->customer('carol@example.com');
        $this->signIn();
        $this->deleteAccount()->assertNoContent();
        $this->signIn('carol@example.com');
        $refused = $this->deleteAccount('wrong-password-here')->assertStatus(422);

        $needles = ['Quillmoor', 'Wexhallow', '10.0187', '100.0123', '4471', 'alice.', self::EMAIL, self::NAME, 'carol@example.com', 'wrong-password-here', self::PASSWORD];
        $this->assertNotEmpty($this->logged);
        $log = json_encode(array_map(fn (MessageLogged $e): array => [$e->message, $e->context], $this->logged), JSON_THROW_ON_ERROR);
        $audit = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $log, "log holds {$needle}");
            $this->assertStringNotContainsString($needle, $audit, "audit holds {$needle}");
            $this->assertStringNotContainsString($needle, (string) $refused->getContent(), "error body holds {$needle}");
        }
    }

    // ---- password ----

    public function test_a_wrong_password_is_422_on_the_password_field_and_changes_nothing(): void
    {
        $this->address($this->alice);
        $this->signIn();
        $before = $this->counts($this->alice);

        $this->deleteAccount('not-the-password')->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('password', 'error.fields');

        $this->assertSame($before, $this->counts($this->alice));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'account.deleted')->count());
        $this->via('customer')->getJson(self::URL)->assertOk();
    }

    public function test_a_missing_or_non_string_password_is_422(): void
    {
        $this->signIn();

        $this->via('customer')->deleteJson(self::URL)->assertStatus(422);
        $this->via('customer')->deleteJson(self::URL, ['password' => ['x']])->assertStatus(422);
        $this->via('customer')->deleteJson(self::URL, ['password' => ''])->assertStatus(422);
        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_wrong_passwords_are_limited_per_account_and_the_right_one_is_then_refused_too(): void
    {
        $this->signIn();

        for ($i = 0; $i < 5; $i++) {
            $this->deleteAccount('wrong-password-'.$i)->assertStatus(422);
        }
        $this->deleteAccount('wrong-again')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->deleteAccount()->assertStatus(429);
        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_the_limit_belongs_to_the_account_and_not_to_the_network(): void
    {
        $this->customer('bob@example.com');
        $this->signIn();
        for ($i = 0; $i < 5; $i++) {
            $this->deleteAccount('wrong-password-'.$i)->assertStatus(422);
        }
        $this->deleteAccount()->assertStatus(429);

        $this->signIn('bob@example.com');
        $this->deleteAccount()->assertNoContent();
    }

    // ---- who may delete ----

    public function test_a_live_staff_role_refuses_with_409_and_changes_nothing(): void
    {
        $this->address($this->alice);
        $this->grantRole($this->alice, 'support');
        $this->signIn();
        $before = $this->counts($this->alice);

        $this->deleteAccount()->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.message', 'This account is also used for work. Ask an administrator to close it.');

        $this->assertSame($before, $this->counts($this->alice));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'account.deleted')->count());
    }

    public function test_active_merchant_staff_refuses_with_409(): void
    {
        $this->address($this->alice);
        $this->merchantStaff($this->alice);
        $this->signIn();
        $before = $this->counts($this->alice);

        $this->deleteAccount()->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->assertSame($before, $this->counts($this->alice));
    }

    public function test_a_rider_record_refuses_with_409(): void
    {
        $this->address($this->alice);
        DB::table('riders')->insert(['id' => $this->ulid(), 'user_id' => $this->alice->id, 'status' => 'pending']);
        $this->signIn();
        $before = $this->counts($this->alice);

        $this->deleteAccount()->assertStatus(409);

        $this->assertSame($before, $this->counts($this->alice));
    }

    public function test_a_confirmed_mfa_factor_refuses_with_409(): void
    {
        $this->address($this->alice);
        DB::table('user_mfa_factors')->insert(['id' => $this->ulid(), 'user_id' => $this->alice->id, 'secret' => 'x', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn();
        $before = $this->counts($this->alice);

        $this->deleteAccount()->assertStatus(409);

        $this->assertSame($before, $this->counts($this->alice));
    }

    public function test_an_unconfirmed_mfa_enrolment_does_not_block_and_goes_with_the_account(): void
    {
        DB::table('user_mfa_factors')->insert(['id' => $this->ulid(), 'user_id' => $this->alice->id, 'secret' => 'x', 'confirmed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn();

        $this->deleteAccount()->assertNoContent();

        $this->assertSame(0, DB::table('user_mfa_factors')->where('user_id', $this->alice->id)->count());
    }

    public function test_the_wrong_password_is_reported_before_the_work_conflict(): void
    {
        $this->grantRole($this->alice, 'support');
        $this->signIn();

        $this->deleteAccount('nope-nope-nope')->assertStatus(422);
    }

    // ---- anonymise fallback (history that restricts deletion) ----

    public function test_past_work_history_forces_anonymising_instead_of_deleting(): void
    {
        $this->address($this->alice);
        $this->merchantStaff($this->alice, 'revoked');
        $this->grantRole($this->alice, 'support');
        DB::table('user_role_scopes')
            ->where('user_id', $this->alice->id)
            ->where('role_id', DB::table('roles')->where('slug', 'support')->value('id'))
            ->update(['revoked_at' => now()]);
        $this->signIn();

        $this->deleteAccount()->assertNoContent();

        $row = DB::table('users')->where('id', $this->alice->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Deleted account', $row->name);
        $this->assertSame("deleted-{$this->alice->id}@invalid.example", $row->email);
        $this->assertSame('suspended', $row->status);
        $this->assertNull($row->phone_e164);
        $this->assertNull($row->phone_verified_at);
        $this->assertNull($row->email_verified_at);
        $this->assertFalse(Hash::check(self::PASSWORD, $row->password));
        $this->assertSame(0, DB::table('saved_addresses')->where('user_id', $this->alice->id)->count());
        $this->assertSame(0, DB::table('customer_profiles')->where('user_id', $this->alice->id)->count());
        // The customer grant is gone, the work history stays.
        $this->assertSame(0, DB::table('user_role_scopes')->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')->where('user_id', $this->alice->id)->where('slug', 'customer')->count());
        $this->assertSame(1, DB::table('user_role_scopes')->where('user_id', $this->alice->id)->count());
        $this->assertSame(1, DB::table('merchant_staff')->where('user_id', $this->alice->id)->count());

        $this->app['auth']->forgetGuards();
        $this->login('customer', self::EMAIL)->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->login('customer', "deleted-{$this->alice->id}@invalid.example")->assertStatus(401);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'account.deleted')->count());
    }

    public function test_an_anonymised_account_leaves_the_old_sessions_dead(): void
    {
        $this->merchantStaff($this->alice, 'revoked');
        $this->signIn();
        $second = $this->app['session.store']->all();

        $this->deleteAccount()->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->via('customer')->getJson(self::URL)->assertUnauthorized();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($second)->via('customer')->getJson(self::URL)->assertUnauthorized();
    }

    // ---- surfaces and tokens ----

    public function test_the_admin_and_merchant_surfaces_answer_404(): void
    {
        $this->merchantStaff($this->alice);
        $this->grantRole($this->alice, 'support');
        $this->signIn(surface: 'admin');
        $this->deleteAccount(surface: 'admin')->assertNotFound();

        $this->signIn(surface: 'merchant');
        $this->deleteAccount(surface: 'merchant')->assertNotFound();

        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_without_a_session_it_is_401(): void
    {
        $this->deleteAccount()->assertUnauthorized();
        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_a_rider_token_is_refused(): void
    {
        DB::table('riders')->insert(['id' => $this->ulid(), 'user_id' => $this->alice->id, 'status' => 'approved', 'approved_at' => now()]);
        $this->withoutHeader('Origin');
        $token = $this->postJson('/api/v1/auth/mobile/token', [
            'email' => self::EMAIL, 'password' => self::PASSWORD, 'device_name' => 'Test phone', 'platform' => 'android',
        ])->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->deleteJson(self::URL, ['password' => self::PASSWORD])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', self::ORIGINS['customer'])->withToken($token)
            ->deleteJson(self::URL, ['password' => self::PASSWORD])->assertUnauthorized();
        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_a_suspended_account_cannot_reach_the_route(): void
    {
        $this->signIn();
        DB::table('users')->where('id', $this->alice->id)->update(['status' => 'suspended']);

        $this->deleteAccount()->assertUnauthorized();
        $this->assertSame(1, $this->counts($this->alice)['users']);
    }

    public function test_the_route_shares_the_account_writes_bucket(): void
    {
        $route = app('router')->getRoutes()->getByName('me.destroy');
        $this->assertNotNull($route);
        $this->assertContains('throttle:account-writes', $route->gatherMiddleware());
    }
}
