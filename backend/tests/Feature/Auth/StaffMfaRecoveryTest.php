<?php

namespace Tests\Feature\Auth;

use App\Application\Commands\StaffMfa;
use App\Domain\Identity\Totp;
use App\Mail\RecoveryCodeUsed;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

/**
 * Recovery for staff two-step sign-in (ADR-011, backlog B-09): the operator
 * reset and self-service regeneration of recovery codes.
 */
class StaffMfaRecoveryTest extends AuthTestCase
{
    private const T0 = 1_700_000_010;

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
     * A staff member with a confirmed factor, set up at T0 and moved on to T0 + 2 steps.
     *
     * @return array{user: User, secret: string, recovery: list<string>}
     */
    private function staff(string $email, string $role = 'platform_owner', string $scopeType = 'global', ?string $scopeId = null): array
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $scopeType, $scopeId);

        $this->travelSteps(0);
        $mfa = app(StaffMfa::class);
        $secret = $mfa->begin($user)['secret'] ?? $this->fail('enrolment did not start');
        $recovery = $mfa->confirm($user, Totp::code($secret, Totp::stepAt(self::T0)), '127.0.0.1') ?? $this->fail('confirm failed');
        $this->travelSteps(2);

        return ['user' => $user, 'secret' => $secret, 'recovery' => $recovery];
    }

    private function travelSteps(int $steps): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::T0 + $steps * Totp::PERIOD));
    }

    private function codeAt(string $secret, int $steps): string
    {
        return Totp::code($secret, Totp::stepAt(self::T0) + $steps);
    }

    /** Signs in through the second step on the admin surface. */
    private function signInWithCode(string $email, string $secret, int $steps = 2): void
    {
        $this->login('admin', $email)->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['code' => $this->codeAt($secret, $steps)])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function reset(string $targetId, array $body = ['reason' => 'Lost phone and recovery codes, identity checked by phone.']): TestResponse
    {
        return $this->via('admin')->postJson("/api/v1/admin/users/{$targetId}/mfa/reset", $body);
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    /** @return array<string, mixed> */
    private function captureSession(): array
    {
        return $this->app['session.store']->all();
    }

    /**
     * Puts a captured session back, as a browser still holding the old cookie would.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function switchTo(array $attributes): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withSession($attributes);
    }

    /** Starts a clean browser: no session, no cached guard user. */
    private function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    // --- Operator reset -----------------------------------------------------

    public function test_reset_clears_the_factor_and_codes_and_audits_the_reason(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($target['user']->id, ['reason' => 'Lost phone'])->assertNoContent();

        $this->assertSame(0, DB::table('user_mfa_factors')->where('user_id', $target['user']->id)->count());
        $this->assertSame(0, DB::table('user_mfa_recovery_codes')->where('user_id', $target['user']->id)->count());
        // The owner's own factor and codes are untouched.
        $this->assertSame(1, DB::table('user_mfa_factors')->where('user_id', $owner['user']->id)->count());
        $this->assertSame(10, DB::table('user_mfa_recovery_codes')->where('user_id', $owner['user']->id)->count());

        $row = DB::table('audit_logs')->where('action', 'auth.mfa_reset')->sole();
        $this->assertSame($owner['user']->id, $row->actor_user_id);
        $this->assertSame('user', $row->subject_type);
        $this->assertSame($target['user']->id, $row->subject_id);
        $this->assertNull($row->scope_city_id);
        $this->assertSame(['reason' => 'Lost phone', 'target_user_id' => $target['user']->id], json_decode($row->after_json, true));
    }

    public function test_a_reset_user_can_enrol_again_and_old_codes_are_dead(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);
        $this->reset($target['user']->id)->assertNoContent();
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        // Locally the password alone now signs them in; an old recovery code is no second step.
        $this->login('admin', 'support@example.com')->assertOk()->assertJsonMissingPath('data.mfa_required')
            ->assertJsonPath('data.mfa_enabled', false);
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $target['recovery'][0]])->assertStatus(401);

        $this->login('admin', 'support@example.com')->assertOk();
        $secret = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated()->json('data.secret');
        $this->assertNotSame($target['secret'], $secret);
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($secret, Totp::stepAt(self::T0) + 2)])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes');
    }

    public function test_a_reset_user_must_enrol_again_when_deployed(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);
        $this->reset($target['user']->id)->assertNoContent();
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->login('admin', 'support@example.com')->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertForbidden()
            ->assertJsonPath('error.message', 'Set up two-step sign-in before using the admin console.');

        $secret = $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated()->json('data.secret');
        $this->via('admin')->postJson('/api/v1/me/mfa/totp/confirm', ['code' => Totp::code($secret, Totp::stepAt(self::T0) + 2)])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->login('admin', 'support@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
    }

    public function test_the_targets_old_session_fails_on_its_next_request_locally(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');

        $this->signInWithCode('support@example.com', $target['secret']);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $old = $this->captureSession();
        $this->assertTrue($old['mfa_verified']);

        $this->freshBrowser();
        $this->signInWithCode('owner@example.com', $owner['secret'], 3);
        $this->reset($target['user']->id)->assertNoContent();

        $this->switchTo($old);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_the_targets_old_session_fails_on_its_next_request_when_deployed(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');

        $this->signInWithCode('support@example.com', $target['secret']);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $old = $this->captureSession();

        $this->freshBrowser();
        $this->signInWithCode('owner@example.com', $owner['secret'], 3);
        $this->reset($target['user']->id)->assertNoContent();

        $this->switchTo($old);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_an_old_session_does_not_come_back_when_the_user_enrols_again(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('support@example.com', $target['secret']);
        $old = $this->captureSession();

        $this->freshBrowser();
        $this->signInWithCode('owner@example.com', $owner['secret'], 3);
        $this->reset($target['user']->id)->assertNoContent();

        // Before the old session sends a request, the user enrols a new factor elsewhere.
        $secret = app(StaffMfa::class)->begin($target['user'])['secret'];
        app(StaffMfa::class)->confirm($target['user'], Totp::code($secret, Totp::stepAt(self::T0) + 3), null);

        $this->switchTo($old);
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertUnauthorized();
    }

    public function test_reset_removes_the_targets_stored_sessions_when_sessions_live_in_the_database(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        // Through the command: switching the driver mid-request would drop this test's own session.
        config(['session.driver' => 'database']);

        foreach ([$target['user']->id, $owner['user']->id] as $userId) {
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }

        app(StaffMfa::class)->reset($owner['user'], $target['user']->id, 'Lost device', null);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target['user']->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $owner['user']->id)->count());
    }

    public function test_a_user_cannot_reset_their_own_factor(): void
    {
        $owner = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($owner['user']->id)->assertForbidden();

        $this->assertTrue(app(StaffMfa::class)->isConfirmed($owner['user']->id));
        $this->assertSame(10, DB::table('user_mfa_recovery_codes')->where('user_id', $owner['user']->id)->count());
        $this->assertNotContains('auth.mfa_reset', $this->auditActions());
    }

    public function test_staff_without_the_permission_get_403_and_nothing_changes(): void
    {
        $admin = $this->staff('admin@example.com', 'platform_admin');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('admin@example.com', $admin['secret']);

        $this->reset($target['user']->id)->assertForbidden();

        $this->assertTrue(app(StaffMfa::class)->isConfirmed($target['user']->id));
        $this->assertNotContains('auth.mfa_reset', $this->auditActions());
    }

    public function test_the_permission_is_held_by_the_platform_owner_alone(): void
    {
        $this->assertSame(['platform_owner'], DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.slug', 'users.mfa.reset')->pluck('roles.slug')->all());
    }

    public function test_city_scoped_staff_get_403_even_if_their_role_were_granted_the_permission(): void
    {
        $cityId = strtolower((string) Str::ulid());
        $manager = $this->staff('manager@example.com', 'city_manager', 'city', $cityId);
        $target = $this->staff('support@example.com', 'support');

        $this->giveRole('city_manager', 'users.mfa.reset');
        $this->signInWithCode('manager@example.com', $manager['secret']);

        // A city grant never covers a deployment-wide action.
        $this->reset($target['user']->id)->assertForbidden();
        $this->assertTrue(app(StaffMfa::class)->isConfirmed($target['user']->id));
    }

    public function test_only_a_platform_owner_may_reset_a_platform_owner(): void
    {
        $admin = $this->staff('admin@example.com', 'platform_admin');
        $otherOwner = $this->staff('owner2@example.com');
        $support = $this->staff('support@example.com', 'support');

        // Suppose the permission were ever granted more widely: the owner rule still holds.
        $this->giveRole('platform_admin', 'users.mfa.reset');
        $this->signInWithCode('admin@example.com', $admin['secret']);

        $this->reset($otherOwner['user']->id)->assertForbidden();
        $this->assertTrue(app(StaffMfa::class)->isConfirmed($otherOwner['user']->id));

        $this->reset($support['user']->id)->assertNoContent();
        $this->assertFalse(app(StaffMfa::class)->isConfirmed($support['user']->id));
    }

    public function test_a_platform_owner_may_reset_another_platform_owner(): void
    {
        $owner = $this->staff('owner@example.com');
        $other = $this->staff('owner2@example.com');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($other['user']->id)->assertNoContent();

        $this->assertFalse(app(StaffMfa::class)->isConfirmed($other['user']->id));
    }

    public function test_unknown_malformed_and_non_staff_targets_answer_404(): void
    {
        $owner = $this->staff('owner@example.com');
        $customer = $this->user('customer@example.com');
        DB::table('user_mfa_factors')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $customer->id,
            'secret' => 'x',
            'confirmed_at' => now(),
        ]);
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset(strtolower((string) Str::ulid()))->assertNotFound();
        $this->reset('not-an-id')->assertNotFound();
        $this->reset($customer->id)->assertNotFound();

        $this->assertSame(1, DB::table('user_mfa_factors')->where('user_id', $customer->id)->count());
        $this->assertNotContains('auth.mfa_reset', $this->auditActions());
    }

    public function test_a_user_whose_staff_grant_was_revoked_is_not_staff(): void
    {
        $owner = $this->staff('owner@example.com');
        $former = $this->staff('former@example.com', 'support');
        DB::table('user_role_scopes')->where('user_id', $former['user']->id)->update(['revoked_at' => now()]);
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($former['user']->id)->assertNotFound();
    }

    /** @param array<string, mixed> $body */
    #[TestWith([[]])]
    #[TestWith([['reason' => '']])]
    #[TestWith([['reason' => '   ']])]
    #[TestWith([['reason' => [1]]])]
    public function test_a_missing_or_empty_reason_is_a_422(array $body): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($target['user']->id, $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertTrue(app(StaffMfa::class)->isConfirmed($target['user']->id));
    }

    public function test_the_reason_is_capped_at_255_characters(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $this->reset($target['user']->id, ['reason' => str_repeat('a', 256)])->assertStatus(422);
        $this->reset($target['user']->id, ['reason' => str_repeat('a', 255)])->assertNoContent();
    }

    public function test_reset_needs_a_session_and_the_admin_surface(): void
    {
        $target = $this->staff('support@example.com', 'support');
        $this->reset($target['user']->id)->assertUnauthorized();

        $this->staff('owner@example.com');
        $this->login('customer', 'owner@example.com')->assertOk();
        $this->via('customer')->postJson("/api/v1/admin/users/{$target['user']->id}/mfa/reset", ['reason' => 'x'])->assertStatus(404);
        $this->assertTrue(app(StaffMfa::class)->isConfirmed($target['user']->id));
    }

    public function test_no_secret_or_code_reaches_a_reset_response_or_audit_row(): void
    {
        $owner = $this->staff('owner@example.com');
        $target = $this->staff('support@example.com', 'support');
        $this->signInWithCode('owner@example.com', $owner['secret']);

        $response = $this->reset($target['user']->id);

        $audit = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);
        $seen = $response->getContent().$audit;
        foreach ([$target['secret'], $owner['secret'], ...$target['recovery'], ...$owner['recovery']] as $value) {
            $this->assertStringNotContainsString($value, $seen);
            $this->assertStringNotContainsString(str_replace('-', '', $value), $seen);
        }
    }

    // --- Alert when a recovery code is used --------------------------------

    public function test_using_a_recovery_code_emails_the_account_with_the_remaining_count_and_no_secret(): void
    {
        Mail::fake();
        $staff = $this->staff('owner@example.com');
        $this->login('admin', 'owner@example.com')->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $staff['recovery'][0]])->assertOk();

        Mail::assertSentCount(1);
        Mail::assertSent(RecoveryCodeUsed::class, function (RecoveryCodeUsed $mail) use ($staff): bool {
            $this->assertTrue($mail->hasTo('owner@example.com'));
            $this->assertSame(9, $mail->remaining);
            $this->assertFalse($mail->isLow());

            $body = $mail->render();
            $this->assertStringContainsString('Recovery codes left: 9', $body);
            $this->assertStringContainsString('If this was not you', $body);
            $this->assertStringNotContainsString('running low', $body);
            $this->assertStringNotContainsString('127.0.0.1', $body);
            foreach ([...$staff['recovery'], $staff['secret']] as $value) {
                $this->assertStringNotContainsString($value, $body);
                $this->assertStringNotContainsString(str_replace('-', '', $value), $body);
            }

            return true;
        });
    }

    public function test_the_mail_warns_when_two_or_fewer_codes_are_left(): void
    {
        Mail::fake();
        $staff = $this->staff('owner@example.com');
        DB::table('user_mfa_recovery_codes')->where('user_id', $staff['user']->id)->orderBy('id')->limit(7)->update(['used_at' => now()]);

        $this->login('admin', 'owner@example.com')->assertOk();
        $unused = array_values(array_filter($staff['recovery'], fn (string $code): bool => DB::table('user_mfa_recovery_codes')
            ->where('user_id', $staff['user']->id)->whereNull('used_at')
            ->where('code_hash', hash_hmac('sha256', str_replace('-', '', $code), (string) config('app.key')))->exists()));
        $this->assertCount(3, $unused);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $unused[0]])->assertOk();

        Mail::assertSent(RecoveryCodeUsed::class, function (RecoveryCodeUsed $mail): bool {
            $this->assertSame(2, $mail->remaining);
            $this->assertTrue($mail->isLow());
            $this->assertStringContainsString('running low', $mail->render());

            return true;
        });
    }

    public function test_totp_sign_in_and_failed_attempts_send_no_recovery_mail(): void
    {
        Mail::fake();
        $staff = $this->staff('owner@example.com');

        $this->login('admin', 'owner@example.com')->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => '0000-0000-0000-0000'])->assertStatus(401);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(401);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['code' => $this->codeAt($staff['secret'], 2)])->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(10, DB::table('user_mfa_recovery_codes')->whereNull('used_at')->count());
    }

    public function test_a_mail_failure_does_not_block_a_recovery_code_sign_in(): void
    {
        $staff = $this->staff('owner@example.com');
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail down'));
        $this->login('admin', 'owner@example.com')->assertOk();

        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $staff['recovery'][0]])->assertOk();

        $this->assertContains('auth.mfa_verified', $this->auditActions());
    }

    // --- Regenerating recovery codes ---------------------------------------

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function regenerate(array $body): TestResponse
    {
        return $this->via('admin')->postJson('/api/v1/me/mfa/recovery-codes', $body);
    }

    public function test_regenerating_returns_ten_new_codes_and_kills_the_old_ones(): void
    {
        $staff = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $staff['secret']);

        $response = $this->regenerate(['code' => $this->codeAt($staff['secret'], 3)])->assertOk();
        $codes = $response->json('data.recovery_codes');
        $this->assertSame(['recovery_codes'], array_keys($response->json('data')));
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertSame([], array_intersect($codes, $staff['recovery']));
        $this->assertSame(10, DB::table('user_mfa_recovery_codes')->where('user_id', $staff['user']->id)->count());

        // Hashes only.
        foreach (DB::table('user_mfa_recovery_codes')->pluck('code_hash') as $hash) {
            $this->assertNotContains($hash, $codes);
        }

        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->login('admin', 'owner@example.com')->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $staff['recovery'][0]])->assertStatus(401);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertOk();

        // Single use.
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->login('admin', 'owner@example.com')->assertOk();
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertStatus(401);
        $this->via('admin')->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[1]])->assertOk();
    }

    public function test_regenerating_is_audited_without_codes(): void
    {
        $staff = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $staff['secret']);
        $codes = $this->regenerate(['code' => $this->codeAt($staff['secret'], 3)])->assertOk()->json('data.recovery_codes');

        $row = DB::table('audit_logs')->where('action', 'auth.mfa_recovery_codes_regenerated')->sole();
        $this->assertSame($staff['user']->id, $row->actor_user_id);
        $this->assertSame($staff['user']->id, $row->subject_id);

        $audit = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);
        foreach ([...$codes, ...$staff['recovery'], $staff['secret'], $this->codeAt($staff['secret'], 3)] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
    }

    public function test_a_wrong_code_changes_nothing_and_is_a_422_like_confirm(): void
    {
        $staff = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $staff['secret']);
        $before = DB::table('user_mfa_recovery_codes')->orderBy('id')->pluck('code_hash')->all();

        $this->regenerate(['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->regenerate([])->assertStatus(422);
        $this->regenerate(['code' => 'abcdef'])->assertStatus(422);

        $this->assertSame($before, DB::table('user_mfa_recovery_codes')->orderBy('id')->pluck('code_hash')->all());
        $this->assertNotContains('auth.mfa_recovery_codes_regenerated', $this->auditActions());
        $this->assertContains('auth.mfa_failed', $this->auditActions());
    }

    public function test_the_code_that_signed_in_cannot_be_replayed_to_regenerate(): void
    {
        $staff = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $staff['secret']);

        $this->regenerate(['code' => $this->codeAt($staff['secret'], 2)])->assertStatus(422);
        $this->regenerate(['code' => $this->codeAt($staff['secret'], 3)])->assertOk();
        $this->regenerate(['code' => $this->codeAt($staff['secret'], 3)])->assertStatus(422);
    }

    public function test_regeneration_failures_are_rate_limited(): void
    {
        $staff = $this->staff('owner@example.com');
        $this->signInWithCode('owner@example.com', $staff['secret']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->regenerate(['code' => '000000'])->assertStatus(422);
        }

        $this->regenerate(['code' => '000000'])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        // Even the right code is refused while the limit holds.
        $this->regenerate(['code' => $this->codeAt($staff['secret'], 3)])->assertStatus(429);
    }

    public function test_regeneration_is_for_the_admin_surface_only(): void
    {
        $this->staff('owner@example.com');
        $this->login('customer', 'owner@example.com')->assertOk();

        $this->via('customer')->postJson('/api/v1/me/mfa/recovery-codes', ['code' => '123456'])->assertNotFound();
        $this->assertSame(10, DB::table('user_mfa_recovery_codes')->count());
    }

    public function test_regeneration_needs_a_session(): void
    {
        $this->regenerate(['code' => '123456'])->assertUnauthorized();
    }

    public function test_regeneration_without_a_confirmed_factor_is_a_409(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->regenerate(['code' => '123456'])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $this->assertSame(0, DB::table('user_mfa_recovery_codes')->count());
    }

    public function test_a_deployed_enrolment_only_session_cannot_regenerate(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->regenerate(['code' => '123456'])->assertForbidden();
    }

    /** Grants a catalogue permission to a role, as a hypothetical future change might. */
    private function giveRole(string $role, string $permission): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => DB::table('roles')->where('slug', $role)->value('id'),
            'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'),
        ]);
    }
}
