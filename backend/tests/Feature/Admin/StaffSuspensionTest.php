<?php

namespace Tests\Feature\Admin;

use App\Application\Commands\StaffSuspension;
use App\Mail\StaffInvitation;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * POST /admin/users/{user}/suspend and /reactivate (product owner decision
 * 2026-11-14: global administrators only). Every refusal also checks that the
 * stored account is unchanged.
 */
class StaffSuspensionTest extends AuthTestCase
{
    private const REASON = 'Left the company on Friday, access no longer needed.';

    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    private City $mine;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = City::factory()->create();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
    }

    private function person(string $email, string $role, ?City $city = null): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);

        return $user;
    }

    private function signedIn(string $role, ?City $city = null, string $email = 'actor@example.com'): User
    {
        $user = $this->person($email, $role, $city);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    private function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function suspend(string $id, array $body = ['reason' => self::REASON]): TestResponse
    {
        return $this->via('admin')->postJson("/api/v1/admin/users/{$id}/suspend", $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function reactivate(string $id, array $body = []): TestResponse
    {
        return $this->via('admin')->postJson("/api/v1/admin/users/{$id}/reactivate", $body);
    }

    private function statusOf(User $user): string
    {
        return (string) DB::table('users')->where('id', $user->id)->value('status');
    }

    private function auditCount(): int
    {
        return DB::table('audit_logs')->whereIn('action', ['user.suspended', 'user.reactivated'])->count();
    }

    private function giveToken(User $user, string $name): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => $name,
            'token' => hash('sha256', $name), 'abilities' => '["rider"]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ who may

    public function test_a_global_platform_admin_can_suspend_and_the_audit_row_holds_the_reason(): void
    {
        $actor = $this->signedIn('platform_admin');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        $this->suspend($target->id)->assertNoContent();

        $this->assertSame('suspended', $this->statusOf($target));
        $audit = DB::table('audit_logs')->where('action', 'user.suspended')->sole();
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame(self::REASON, json_decode($audit->after_json, true)['reason']);
        $this->assertSame('active', json_decode($audit->before_json, true)['status']);
    }

    public function test_a_platform_owner_can_suspend_a_global_platform_admin(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'platform_admin');

        $this->suspend($target->id)->assertNoContent();

        $this->assertSame('suspended', $this->statusOf($target));
    }

    /** @return array<string, array{string, bool}> */
    public static function callersWithoutTheGlobalPermission(): array
    {
        return [
            'city-scoped platform admin (holds users.roles.assign)' => ['platform_admin', true],
            'city manager (holds users.roles.assign)' => ['city_manager', true],
            'city-scoped support' => ['support', true],
            'global support' => ['support', false],
            'dispatcher (no users permission at all)' => ['dispatcher', true],
            'global finance' => ['finance', false],
        ];
    }

    #[DataProvider('callersWithoutTheGlobalPermission')]
    public function test_everyone_else_is_refused_on_both_routes_and_nothing_changes(string $role, bool $cityScoped): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn($role, $cityScoped ? $this->mine : null);

        $this->suspend($target->id)->assertForbidden();
        DB::table('users')->where('id', $target->id)->update(['status' => 'suspended']);
        $this->reactivate($target->id)->assertForbidden();

        $this->assertSame('suspended', $this->statusOf($target));
        $this->assertSame(0, $this->auditCount());
    }

    public function test_the_permission_is_carried_only_by_the_two_global_admin_roles(): void
    {
        $holders = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.slug', 'users.suspend')->orderBy('roles.slug')->pluck('roles.slug')->all();

        $this->assertSame(['platform_admin', 'platform_owner'], $holders);
    }

    public function test_the_routes_need_a_session_and_the_admin_app(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        foreach (['suspend', 'reactivate'] as $action) {
            $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/{$action}", ['reason' => self::REASON])->assertUnauthorized();
        }

        $this->user('customer@example.com');
        $this->login('customer', 'customer@example.com')->assertOk();
        foreach (['suspend', 'reactivate'] as $action) {
            $this->via('customer')->postJson("/api/v1/admin/users/{$target->id}/{$action}", ['reason' => self::REASON])->assertNotFound();
        }
        $this->assertSame('active', $this->statusOf($target));
    }

    // ------------------------------------------------------------ targets

    public function test_nobody_can_suspend_themselves(): void
    {
        $actor = $this->signedIn('platform_owner');

        $this->suspend($actor->id)->assertForbidden()->assertJsonPath('error.message', 'You cannot suspend or reactivate your own account.');
        $this->assertSame('active', $this->statusOf($actor));
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_platform_owner_cannot_be_suspended_even_by_another_owner(): void
    {
        $this->signedIn('platform_owner');
        $other = $this->person('owner2@example.com', 'platform_owner');

        $this->suspend($other->id)->assertForbidden();
        $this->assertSame('active', $this->statusOf($other));
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_suspended_owner_can_be_reactivated_only_by_another_owner(): void
    {
        $owner = $this->person('owner2@example.com', 'platform_owner');
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);

        $this->signedIn('platform_admin');
        $this->reactivate($owner->id)->assertForbidden();
        $this->assertSame('suspended', $this->statusOf($owner));
        $this->assertSame(0, $this->auditCount());

        $this->freshBrowser();
        $this->signedIn('platform_owner', null, 'owner1@example.com');
        $this->reactivate($owner->id)->assertNoContent();
        $this->assertSame('active', $this->statusOf($owner));
    }

    public function test_a_person_who_is_not_staff_or_does_not_exist_is_404(): void
    {
        $this->signedIn('platform_admin');
        $customer = $this->user('customer@example.com');

        $this->suspend($customer->id)->assertNotFound();
        $this->reactivate($customer->id)->assertNotFound();
        $this->suspend(strtolower((string) Str::ulid()))->assertNotFound();
        $this->suspend('not-an-id')->assertNotFound();

        $this->assertSame('active', $this->statusOf($customer));
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_target_who_lost_their_staff_grants_is_404(): void
    {
        $this->signedIn('platform_admin');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        DB::table('user_role_scopes')->where('user_id', $target->id)->update(['revoked_at' => now()]);

        $this->suspend($target->id)->assertNotFound();
        $this->assertSame('active', $this->statusOf($target));
    }

    public function test_suspending_twice_and_reactivating_an_active_account_are_409(): void
    {
        $this->signedIn('platform_admin');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        $this->reactivate($target->id)->assertStatus(409);
        $this->suspend($target->id)->assertNoContent();
        $this->suspend($target->id)->assertStatus(409);

        $this->assertSame(1, $this->auditCount());
    }

    // ------------------------------------------------------------ input

    /** @return array<string, array{array<string, mixed>}> */
    public static function badReasons(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['reason' => '']],
            'blank' => [['reason' => '   ']],
            'too long' => [['reason' => str_repeat('a', 501)]],
            'not a string' => [['reason' => ['x']]],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('badReasons')]
    public function test_the_reason_is_required_and_limited_to_500_characters(array $body): void
    {
        $this->signedIn('platform_admin');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        $this->suspend($target->id, $body)->assertStatus(422);

        $this->assertSame('active', $this->statusOf($target));
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_reason_of_exactly_500_characters_is_accepted(): void
    {
        $this->signedIn('platform_admin');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        $this->suspend($target->id, ['reason' => str_repeat('a', 500)])->assertNoContent();
    }

    // ------------------------------------------------------------ effects

    public function test_a_suspended_user_is_refused_on_every_admin_route_on_the_next_request(): void
    {
        $target = $this->signedIn('platform_admin', email: 'target@example.com');
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $old = $this->app['session.store']->all();

        $this->freshBrowser();
        $this->signedIn('platform_owner', email: 'boss@example.com');
        $this->suspend($target->id)->assertNoContent();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1/admin/'))
            ->map(fn ($r): array => [$r->methods()[0], preg_replace('/\{[^}]+\}/', strtolower((string) Str::ulid()), $r->uri())])
            ->values();
        $this->assertGreaterThan(10, $routes->count());

        foreach ($routes as [$method, $uri]) {
            // The old browser session, replayed; each refusal ends it, so put it back every time.
            $this->freshBrowser();
            $this->withSession($old);

            $this->via('admin')->json($method, '/'.$uri, [])->assertUnauthorized();
        }
    }

    public function test_a_suspended_user_cannot_sign_in(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->suspend($target->id)->assertNoContent();

        $this->freshBrowser();
        $this->login('admin', 'target@example.com')->assertStatus(401);
    }

    public function test_suspending_deletes_the_targets_stored_web_sessions_only(): void
    {
        $actor = $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $bystander = $this->person('bystander@example.com', 'dispatcher', $this->mine);
        config(['session.driver' => 'database']);

        foreach ([$target->id, $target->id, $bystander->id] as $userId) {
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }

        app(StaffSuspension::class)->suspend($actor, $target->id, self::REASON);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $bystander->id)->count());
    }

    public function test_suspending_revokes_the_targets_rider_tokens_only(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $bystander = $this->user('rider@example.com');
        $this->giveToken($target, 'device-1');
        $this->giveToken($target, 'device-2');
        $this->giveToken($bystander, 'device-3');

        $this->suspend($target->id)->assertNoContent();

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $bystander->id)->count());
    }

    public function test_reactivating_restores_the_status_but_not_the_tokens_or_sessions(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->giveToken($target, 'device');

        $this->suspend($target->id)->assertNoContent();
        $this->reactivate($target->id, ['reason' => 'Back from leave.'])->assertNoContent();

        $this->assertSame('active', $this->statusOf($target));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $audit = DB::table('audit_logs')->where('action', 'user.reactivated')->sole();
        $this->assertSame('Back from leave.', json_decode($audit->after_json, true)['reason']);

        // They have to sign in again.
        $this->freshBrowser();
        $this->login('admin', 'target@example.com')->assertOk();
    }

    public function test_the_reason_is_optional_when_reactivating(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->suspend($target->id)->assertNoContent();

        $this->reactivate($target->id)->assertNoContent();
        $this->suspend($target->id)->assertNoContent();
        $this->reactivate($target->id, ['reason' => ''])->assertNoContent();
        $this->suspend($target->id)->assertNoContent();
        $this->reactivate($target->id, ['reason' => str_repeat('a', 501)])->assertStatus(422);
    }

    public function test_a_pending_invitation_from_a_suspended_user_can_no_longer_be_accepted(): void
    {
        Mail::fake();
        $inviter = $this->signedIn('platform_admin', email: 'inviter@example.com');
        $this->via('admin')->postJson('/api/v1/admin/invitations', [
            'name' => 'New Person', 'email' => 'new.person@example.com', 'role' => 'dispatcher',
            'scope_type' => 'city', 'scope_id' => $this->mine->id,
        ])->assertStatus(202);
        $token = (string) Mail::sent(StaffInvitation::class)->last()?->token;

        $this->freshBrowser();
        $this->signedIn('platform_owner', email: 'boss@example.com');
        $this->suspend($inviter->id)->assertNoContent();
        $this->freshBrowser();

        $this->via('admin')->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertNotFound();

        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
        $this->assertNull(DB::table('staff_invitations')->value('accepted_at'));
    }

    // ------------------------------------------------------------ the list

    public function test_the_list_says_who_can_be_suspended_or_reactivated(): void
    {
        $actor = $this->signedIn('platform_admin');
        $dispatcher = $this->person('dispatcher@example.com', 'dispatcher', $this->mine);
        $gone = $this->person('gone@example.com', 'support', $this->mine);
        $owner = $this->person('owner@example.com', 'platform_owner');
        DB::table('users')->where('id', $gone->id)->update(['status' => 'suspended']);

        $rows = array_column($this->via('admin')->getJson('/api/v1/admin/users')->assertOk()->json('data'), null, 'id');

        $this->assertFalse($rows[$actor->id]['can_suspend']);
        $this->assertFalse($rows[$actor->id]['can_reactivate']);
        $this->assertTrue($rows[$dispatcher->id]['can_suspend']);
        $this->assertFalse($rows[$dispatcher->id]['can_reactivate']);
        $this->assertFalse($rows[$gone->id]['can_suspend']);
        $this->assertTrue($rows[$gone->id]['can_reactivate']);
        $this->assertSame('suspended', $rows[$gone->id]['status']);
        $this->assertFalse($rows[$owner->id]['can_suspend']);
    }

    public function test_the_list_offers_neither_action_to_a_city_scoped_viewer(): void
    {
        $this->signedIn('platform_admin', $this->mine);
        $this->person('dispatcher@example.com', 'dispatcher', $this->mine);

        $rows = $this->via('admin')->getJson('/api/v1/admin/users')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertFalse($row['can_suspend']);
            $this->assertFalse($row['can_reactivate']);
        }
    }

    // ------------------------------------------------------------ logs

    public function test_the_log_events_hold_ids_only(): void
    {
        $this->signedIn('platform_owner');
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);

        $this->suspend($target->id, ['reason' => 'sensitive-reason-text'])->assertNoContent();
        $this->reactivate($target->id, ['reason' => 'another-sensitive-reason'])->assertNoContent();

        $events = array_map(fn (MessageLogged $e): string => $e->message, $this->logged);
        $this->assertContains('user.suspended', $events);
        $this->assertContains('user.reactivated', $events);
        foreach ($this->logged as $event) {
            $text = $event->message.json_encode($event->context);
            $this->assertStringNotContainsString('target@example.com', $text);
            $this->assertStringNotContainsString('sensitive-reason-text', $text);
            $this->assertStringNotContainsString('another-sensitive-reason', $text);
        }
    }
}
