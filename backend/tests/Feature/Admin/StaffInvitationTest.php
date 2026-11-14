<?php

namespace Tests\Feature\Admin;

use App\Mail\StaffInvitation;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Backlog U-05: staff invitations. POST/GET /admin/invitations,
 * DELETE /admin/invitations/{id} for staff, and the two public routes
 * POST /auth/invitations/inspect and /accept for the invitee.
 *
 * Every refusal case also checks that nothing was stored or sent.
 */
class StaffInvitationTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    private City $mine;

    private City $other;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = City::factory()->create();
        $this->other = City::factory()->create();
        Mail::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
    }

    private function signedIn(string $role, ?City $city = null, string $email = 'actor@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $role = 'dispatcher', ?City $city = null, string $email = 'new.person@example.com'): array
    {
        $scope = $city === null
            ? ['scope_type' => 'global']
            : ['scope_type' => 'city', 'scope_id' => $city->id];

        return ['name' => 'New Person', 'email' => $email, 'role' => $role] + $scope;
    }

    /** @param array<string, mixed> $body
     * @return TestResponse<JsonResponse> */
    private function invite(array $body): TestResponse
    {
        return $this->via('admin')->postJson('/api/v1/admin/invitations', $body);
    }

    private function lastToken(): string
    {
        return (string) Mail::sent(StaffInvitation::class)->last()?->token;
    }

    /** @return TestResponse<JsonResponse> */
    private function inspect(string $token): TestResponse
    {
        return $this->via('admin')->postJson('/api/v1/auth/invitations/inspect', ['token' => $token]);
    }

    /** @return TestResponse<JsonResponse> */
    private function accept(string $token, string $password = self::NEW_PASSWORD): TestResponse
    {
        return $this->via('admin')->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => $password]);
    }

    private function invitations(): int
    {
        return DB::table('staff_invitations')->count();
    }

    private function assertNothingHappened(): void
    {
        $this->assertSame(0, $this->invitations());
        Mail::assertNothingSent();
    }

    /** Signs the actor out so the invitee calls are made without a session. */
    private function signOut(): void
    {
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    // ------------------------------------------------------------ access to the routes

    public function test_the_admin_routes_need_a_session_and_the_admin_app(): void
    {
        foreach ([['GET', '/invitations'], ['POST', '/invitations'], ['DELETE', '/invitations/01h00000000000000000000000']] as [$method, $path]) {
            $this->via('admin')->json($method, "/api/v1/admin{$path}", [])->assertStatus(401);
        }

        $this->user('customer@example.com');
        $this->login('customer', 'customer@example.com')->assertOk();
        foreach ([['GET', '/invitations'], ['POST', '/invitations'], ['DELETE', '/invitations/01h00000000000000000000000']] as [$method, $path]) {
            $this->via('customer')->json($method, "/api/v1/admin{$path}", $this->body())->assertStatus(404);
        }
        $this->assertNothingHappened();
    }

    public function test_the_public_routes_need_a_known_admin_origin(): void
    {
        $payload = ['token' => str_repeat('a', 64), 'password' => self::NEW_PASSWORD];

        foreach (['inspect', 'accept'] as $action) {
            $this->withoutHeader('Origin')->postJson("/api/v1/auth/invitations/{$action}", $payload)->assertStatus(403);
            $this->via('customer')->postJson("/api/v1/auth/invitations/{$action}", $payload)->assertStatus(403);
            $this->via('merchant')->postJson("/api/v1/auth/invitations/{$action}", $payload)->assertStatus(403);
            $this->via('admin')->postJson("/api/v1/auth/invitations/{$action}", [])->assertStatus(422);
        }
    }

    // ------------------------------------------------------------ who may invite

    /** @return array<string, array{string, ?string, array{string, ?string}, int}> */
    public static function matrix(): array
    {
        // [actor role, actor city ('mine'|null), request, expected status]. Request: role, city.
        return [
            'owner: dispatcher in a city' => ['platform_owner', null, ['dispatcher', 'mine'], 202],
            'owner: global support' => ['platform_owner', null, ['support', null], 202],
            'owner: global finance' => ['platform_owner', null, ['finance', null], 202],
            'owner: platform admin' => ['platform_owner', null, ['platform_admin', null], 202],
            'owner: cannot make an owner' => ['platform_owner', null, ['platform_owner', null], 403],
            'admin: city manager in a city' => ['platform_admin', null, ['city_manager', 'mine'], 202],
            'admin: support in a city' => ['platform_admin', null, ['support', 'other'], 202],
            'admin: cannot make an admin' => ['platform_admin', null, ['platform_admin', null], 403],
            'admin: finance is not theirs to give' => ['platform_admin', null, ['finance', null], 403],
            'manager: dispatcher in own city' => ['city_manager', 'mine', ['dispatcher', 'mine'], 202],
            'manager: dispatcher in another city' => ['city_manager', 'mine', ['dispatcher', 'other'], 403],
            'manager: another manager' => ['city_manager', 'mine', ['city_manager', 'mine'], 403],
            'manager: support' => ['city_manager', 'mine', ['support', 'mine'], 403],
            'manager: global dispatcher is not a thing' => ['city_manager', 'mine', ['dispatcher', null], 403],
            'dispatcher cannot invite' => ['dispatcher', 'mine', ['dispatcher', 'mine'], 403],
            'finance cannot invite' => ['finance', null, ['dispatcher', 'mine'], 403],
            'support cannot invite' => ['support', null, ['dispatcher', 'mine'], 403],
        ];
    }

    /**
     * @param  array{0: string, 1: ?string}  $request
     */
    #[DataProvider('matrix')]
    public function test_the_permission_matrix_follows_the_grant_rules(string $role, ?string $actorCity, array $request, int $expected): void
    {
        $cities = ['mine' => $this->mine, 'other' => $this->other, '' => null];
        $this->signedIn($role, $actorCity === null ? null : $cities[$actorCity]);

        $response = $this->invite($this->body($request[0], $request[1] === null ? null : $cities[$request[1]]));

        $response->assertStatus($expected);
        if ($expected === 202) {
            $this->assertSame(1, $this->invitations());
            Mail::assertSentCount(1);
        } else {
            $this->assertNothingHappened();
        }
    }

    public function test_a_role_that_is_not_staff_or_does_not_exist_is_refused(): void
    {
        $this->signedIn('platform_owner');

        foreach (['customer', 'merchant_owner', 'rider', 'wizard', 'Dispatcher', ''] as $role) {
            $this->invite($this->body($role, $this->mine))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['role']]]);
        }
        $this->assertNothingHappened();
    }

    public function test_you_cannot_invite_yourself(): void
    {
        $this->signedIn('platform_owner');

        $this->invite($this->body('dispatcher', $this->mine, 'actor@example.com'))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
        $this->invite($this->body('dispatcher', $this->mine, '  ACTOR@Example.com '))->assertStatus(422);
        $this->assertNothingHappened();
    }

    public function test_an_unknown_city_is_not_found_and_scope_input_is_checked(): void
    {
        $this->signedIn('platform_owner');

        $this->invite(['scope_id' => '01h00000000000000000000000'] + $this->body('dispatcher', $this->mine))->assertStatus(404);
        $this->invite(['scope_type' => 'city'] + $this->body('dispatcher'))->assertStatus(422);
        $this->invite(['scope_type' => 'global', 'scope_id' => $this->mine->id] + $this->body('support'))->assertStatus(422);
        $this->invite(['scope_type' => 'branch', 'scope_id' => $this->mine->id] + $this->body('support'))->assertStatus(422);
        $this->invite(['email' => 'not-an-email'] + $this->body('dispatcher', $this->mine))->assertStatus(422);
        $this->invite(['name' => ''] + $this->body('dispatcher', $this->mine))->assertStatus(422);
        $this->assertNothingHappened();
    }

    public function test_a_client_cannot_name_who_is_inviting(): void
    {
        $other = $this->user('someone@example.com');
        $this->grantRole($other, 'platform_admin');
        $this->signedIn('city_manager', $this->mine);

        // Extra fields are ignored: the inviter is the session user, so the manager's limits apply.
        $this->invite(['invited_by' => $other->id] + $this->body('city_manager', $this->mine))->assertStatus(403);
        $this->assertNothingHappened();
    }

    // ------------------------------------------------------------ what is stored and sent

    public function test_an_invitation_stores_a_hash_expires_in_72_hours_and_is_audited_without_the_address(): void
    {
        $inviter = $this->signedIn('platform_owner');
        $this->pinToSecond();

        $response = $this->invite($this->body('dispatcher', $this->mine, ' New.Person@Example.com '))->assertStatus(202);
        $token = $this->lastToken();

        $row = DB::table('staff_invitations')->sole();
        $this->assertSame($row->id, $response->json('data.id'));
        $this->assertSame('new.person@example.com', $row->email);
        $this->assertSame('New Person', $row->name);
        $this->assertSame($inviter->id, $row->invited_by);
        $this->assertSame('city', $row->scope_type);
        $this->assertSame($this->mine->id, $row->scope_id);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertGreaterThanOrEqual(64, strlen($token));
        $this->assertNull($row->accepted_at);
        $this->assertNull($row->revoked_at);
        $this->assertSame(now()->addHours(72)->getTimestamp(), Carbon::parse($row->expires_at)->getTimestamp());

        $audit = DB::table('audit_logs')->where('action', 'staff_invitation.created')->sole();
        $this->assertSame($row->id, $audit->subject_id);
        $this->assertSame($inviter->id, $audit->actor_user_id);
        $this->assertSame($this->mine->id, $audit->scope_city_id);
        $this->assertStringNotContainsString($token, json_encode($audit));
        $this->assertStringNotContainsString('new.person@example.com', strtolower(json_encode($audit)));
        $this->assertStringNotContainsString($row->token_hash, json_encode($audit));

        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', '!=', $inviter->id)->count());
    }

    public function test_the_email_goes_to_the_invitee_with_the_token_in_the_fragment_of_the_admin_link(): void
    {
        $this->signedIn('platform_owner');

        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);

        Mail::assertSent(StaffInvitation::class, function (StaffInvitation $mail): bool {
            return $mail->hasTo('new.person@example.com')
                && str_starts_with($mail->url, 'http://localhost:3002/invite#token=')
                && $mail->url === 'http://localhost:3002/invite#token='.$mail->token
                && ! str_contains($mail->url, '?')
                && ! str_contains($mail->url, 'new.person')
                && $mail->roleLabel === 'Dispatcher'
                && $mail->scopeLabel === $this->mine->name
                && $mail->hoursValid === 72;
        });
        Mail::assertSentCount(1);
    }

    public function test_the_response_carries_no_token_and_the_server_never_logs_one(): void
    {
        $this->signedIn('platform_owner');

        $response = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();

        $this->assertSame(['id', 'status'], array_keys($response->json('data')));
        $this->assertStringNotContainsString($token, (string) $response->getContent());
        $this->assertStringNotContainsString('new.person@example.com', (string) $response->getContent());

        $this->inspect($token)->assertOk();
        $this->signOut();
        $this->accept($token)->assertOk();
        $this->accept($token)->assertStatus(404);
        $this->inspect(str_repeat('b', 64))->assertStatus(404);

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $event) {
            $text = $event->message.json_encode($event->context);
            $this->assertStringNotContainsString($token, $text);
            $this->assertStringNotContainsString(hash('sha256', $token), $text);
            $this->assertStringNotContainsString('new.person@example.com', $text);
            $this->assertStringNotContainsString(self::NEW_PASSWORD, $text);
        }
        foreach (DB::table('audit_logs')->get() as $audit) {
            $this->assertStringNotContainsString($token, json_encode($audit));
            $this->assertStringNotContainsString('new.person@example.com', json_encode($audit));
        }
        $events = array_map(fn (MessageLogged $e): string => $e->message, $this->logged);
        $this->assertContains('staff_invitation.created', $events);
        $this->assertContains('staff_invitation.accepted', $events);
        $this->assertContains('staff_invitation.accept.failed', $events);
    }

    // ------------------------------------------------------------ no account-existence leak

    public function test_known_and_unknown_addresses_get_the_same_answer_and_the_same_work(): void
    {
        $this->signedIn('platform_owner');
        $this->user('customer@example.com');
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'dispatcher', 'city', $this->other->id);
        $gone = $this->user('suspended@example.com');
        DB::table('users')->where('id', $gone->id)->update(['status' => 'suspended']);

        $responses = [];
        foreach (['nobody@example.com', 'customer@example.com', 'staff@example.com', 'suspended@example.com'] as $email) {
            $responses[$email] = $this->invite($this->body('dispatcher', $this->mine, $email));
        }

        $shapes = [];
        foreach ($responses as $email => $response) {
            $response->assertStatus(202);
            $shapes[$email] = [array_keys($response->json('data')), $response->json('data.status'), array_keys($response->json()), $response->headers->get('Content-Type')];
            Mail::assertSent(StaffInvitation::class, fn (StaffInvitation $m): bool => $m->hasTo($email));
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $shapes)));
        $this->assertSame(4, $this->invitations());
        Mail::assertSentCount(4);
        // No second account is created and nothing is granted yet.
        $this->assertSame(1, DB::table('users')->where('email', 'customer@example.com')->count());
        $this->assertSame(0, DB::table('users')->where('email', 'nobody@example.com')->count());
        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', $staff->id)->where('scope_id', $this->mine->id)->count());
    }

    public function test_a_duplicate_pending_invitation_says_the_same_for_known_and_unknown_addresses(): void
    {
        $this->signedIn('platform_owner');
        $this->user('customer@example.com');

        $this->invite($this->body('dispatcher', $this->mine, 'nobody@example.com'))->assertStatus(202);
        $this->invite($this->body('dispatcher', $this->mine, 'customer@example.com'))->assertStatus(202);

        $first = $this->invite($this->body('dispatcher', $this->mine, 'nobody@example.com'))->assertStatus(409);
        $second = $this->invite($this->body('dispatcher', $this->mine, 'customer@example.com'))->assertStatus(409);

        $this->assertSame($first->json('error.message'), $second->json('error.message'));
        $this->assertSame($first->json('error.code'), $second->json('error.code'));
        $this->assertSame(2, $this->invitations());
        Mail::assertSentCount(2);
    }

    // ------------------------------------------------------------ duplicates and limits

    public function test_one_live_invitation_per_address_role_and_scope(): void
    {
        $this->signedIn('platform_owner');

        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(409);
        $this->invite($this->body('dispatcher', $this->mine, 'NEW.person@example.com'))->assertStatus(409);

        // A different role, place or person is a different invitation.
        $this->invite($this->body('dispatcher', $this->other))->assertStatus(202);
        $this->invite($this->body('support', $this->mine))->assertStatus(202);
        $this->invite($this->body('dispatcher', $this->mine, 'someone.else@example.com'))->assertStatus(202);

        $this->assertSame(4, $this->invitations());
        Mail::assertSentCount(4);
    }

    public function test_a_revoked_or_expired_invitation_does_not_block_a_new_one(): void
    {
        $this->signedIn('platform_owner');

        $id = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202)->json('data.id');
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertNoContent();
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);

        $this->travel(73)->hours();
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);

        $this->assertSame(3, $this->invitations());
        $this->assertSame(1, DB::table('staff_invitations')->whereNull('revoked_at')->where('expires_at', '>', now())->count());
    }

    public function test_mail_to_one_address_is_capped_per_day_whether_or_not_it_has_an_account(): void
    {
        $this->signedIn('platform_owner');

        foreach (range(1, 5) as $n) {
            $role = ['dispatcher', 'support', 'finance', 'platform_admin', 'city_manager'][$n - 1];
            $this->invite($this->body($role, in_array($role, ['dispatcher', 'city_manager'], true) ? $this->mine : null))->assertStatus(202);
        }

        $this->invite($this->body('dispatcher', $this->other))->assertStatus(429);
        Mail::assertSentCount(5);
        $this->assertSame(5, $this->invitations());
    }

    public function test_the_invite_route_is_throttled(): void
    {
        $this->signedIn('platform_owner');

        for ($i = 0; $i < 20; $i++) {
            $this->invite(['name' => '', 'email' => "p{$i}@example.com", 'role' => 'dispatcher'])->assertStatus(422);
        }
        $this->invite($this->body('dispatcher', $this->mine, 'late@example.com'))->assertStatus(429);
        $this->assertNothingHappened();
    }

    // ------------------------------------------------------------ inspect

    public function test_inspect_shows_only_name_role_place_and_expiry(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $response = $this->inspect($token)->assertOk();

        $this->assertSame(['name', 'role_label', 'scope_label', 'expires_at'], array_keys($response->json('data')));
        $this->assertSame('New Person', $response->json('data.name'));
        $this->assertSame('Dispatcher', $response->json('data.role_label'));
        $this->assertSame($this->mine->name, $response->json('data.scope_label'));
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $response->json('data.expires_at'));
        $this->assertStringNotContainsString('new.person@example.com', (string) $response->getContent());
        $this->assertStringNotContainsString($this->mine->id, (string) $response->getContent());
        // Looking does not use the invitation up.
        $this->inspect($token)->assertOk();
    }

    public function test_every_unusable_token_gets_the_same_404(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine, 'used@example.com'))->assertStatus(202);
        $used = $this->lastToken();
        $this->invite($this->body('dispatcher', $this->mine, 'revoked@example.com'))->assertStatus(202);
        $revoked = $this->lastToken();
        $revokedId = DB::table('staff_invitations')->where('email', 'revoked@example.com')->value('id');
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$revokedId}")->assertNoContent();
        $this->invite($this->body('dispatcher', $this->mine, 'old@example.com'))->assertStatus(202);
        $expired = $this->lastToken();
        DB::table('staff_invitations')->where('email', 'old@example.com')->update(['expires_at' => now()->subSecond()]);
        $this->signOut();
        $this->accept($used)->assertOk();

        $answers = [];
        foreach ([$used, $revoked, $expired, str_repeat('a', 64), 'short', strtoupper($used)] as $token) {
            $answers[] = $this->inspect($token)->assertStatus(404)->json();
        }

        $this->assertCount(1, array_unique(array_map(fn (array $a): string => json_encode([$a['error']['code'], $a['error']['message']]), $answers)));
    }

    public function test_a_token_in_the_query_string_is_not_read(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $this->via('admin')->postJson("/api/v1/auth/invitations/inspect?token={$token}", [])->assertStatus(422);
        $this->via('admin')->postJson("/api/v1/auth/invitations/accept?token={$token}&password=".self::NEW_PASSWORD, [])->assertStatus(422);
        $this->via('admin')->getJson("/api/v1/auth/invitations/inspect?token={$token}")->assertStatus(405);
        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
    }

    // ------------------------------------------------------------ accept

    public function test_accepting_creates_the_account_the_grant_and_the_audit_rows_and_signs_nobody_in(): void
    {
        $inviter = $this->signedIn('city_manager', $this->mine);
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $response = $this->accept($token)->assertOk();

        $this->assertSame(['status' => 'invitation_accepted'], $response->json('data'));
        $this->assertGuest();

        $user = DB::table('users')->where('email', 'new.person@example.com')->sole();
        $this->assertSame('New Person', $user->name);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotSame(self::NEW_PASSWORD, $user->password);

        $grant = DB::table('user_role_scopes')->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_id', $user->id)->whereNull('revoked_at')->get(['roles.slug', 'scope_type', 'scope_id']);
        $this->assertCount(1, $grant);
        $this->assertSame(['dispatcher', 'city', $this->mine->id], [$grant[0]->slug, $grant[0]->scope_type, $grant[0]->scope_id]);
        $this->assertSame(0, DB::table('customer_profiles')->where('user_id', $user->id)->count());

        $this->assertNotNull(DB::table('staff_invitations')->value('accepted_at'));

        $granted = DB::table('audit_logs')->where('action', 'role.granted')->where('subject_id', $user->id)->sole();
        $this->assertSame($inviter->id, $granted->actor_user_id);
        $accepted = DB::table('audit_logs')->where('action', 'staff_invitation.accepted')->sole();
        $this->assertSame($user->id, $accepted->actor_user_id);
        $this->assertSame(DB::table('staff_invitations')->value('id'), $accepted->subject_id);
        $this->assertSame($this->mine->id, $accepted->scope_city_id);
        $this->assertStringNotContainsString($token, json_encode($accepted));
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($accepted));
        $this->assertTrue((bool) json_decode((string) $accepted->after_json, true)['account_created']);

        // The new account can sign in to the admin app, and the invitation is gone.
        $this->login('admin', 'new.person@example.com', self::NEW_PASSWORD)->assertOk();
        $this->inspect($token)->assertStatus(404);
    }

    public function test_an_invitation_works_once(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $this->accept($token)->assertOk();
        $this->accept($token, 'another-passphrase-entirely')->assertStatus(404);

        $this->assertSame(1, DB::table('users')->where('email', 'new.person@example.com')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'staff_invitation.accepted')->count());
        // The second attempt did not change the password.
        $this->login('admin', 'new.person@example.com', self::NEW_PASSWORD)->assertOk();
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $this->travel(71)->hours();
        $this->inspect($token)->assertOk();
        $this->travel(2)->hours();

        $this->inspect($token)->assertStatus(404);
        $this->accept($token)->assertStatus(404);
        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
    }

    public function test_a_revoked_invitation_cannot_be_accepted(): void
    {
        $this->signedIn('platform_owner');
        $id = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202)->json('data.id');
        $token = $this->lastToken();
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertNoContent();
        $this->signOut();

        $this->accept($token)->assertStatus(404);
        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
    }

    public function test_a_wrong_token_is_refused_and_leaves_the_real_one_usable(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $this->accept(substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a'))->assertStatus(404);
        $this->accept('')->assertStatus(422);
        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());

        $this->accept($token)->assertOk();
    }

    public function test_a_weak_password_is_refused_and_the_invitation_stays_usable(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();

        $this->accept($token, 'short')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $this->accept($token, str_repeat('x', 1025))->assertStatus(422);
        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());

        $this->accept($token)->assertOk();
    }

    public function test_repeated_wrong_attempts_on_one_token_are_rate_limited(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->user('someone@example.com');
        DB::table('staff_invitations')->update(['email' => 'someone@example.com']);
        $this->signOut();

        for ($i = 0; $i < 5; $i++) {
            $this->accept($token, 'wrong-password-guess')->assertStatus(404);
        }

        $this->accept($token, 'wrong-password-guess')->assertStatus(429);
        // Even the right password waits out the window.
        $this->accept($token, self::PASSWORD)->assertStatus(429);
        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', DB::table('users')->where('email', 'someone@example.com')->value('id'))->count());
    }

    public function test_the_public_routes_are_throttled_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->inspect(str_repeat('a', 64))->assertStatus(404);
        }

        $this->inspect(str_repeat('a', 64))->assertStatus(429);
    }

    public function test_other_signed_out_routes_do_not_use_up_the_invitation_budget(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->via('admin')->getJson('/api/v1/auth/csrf')->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->inspect(str_repeat('a', 64))->assertStatus(404);
        }
        // Inspecting a link does not use up the acceptance budget either.
        $this->accept(str_repeat('a', 64))->assertStatus(404);
    }

    // ------------------------------------------------------------ an address that already has an account

    public function test_an_existing_account_gets_the_role_only_with_its_own_password_and_is_not_duplicated(): void
    {
        $existing = $this->user('new.person@example.com');
        $inviter = $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();
        $hashBefore = DB::table('users')->where('id', $existing->id)->value('password');

        // The invitee-facing answers are the same as for a new address.
        $this->inspect($token)->assertOk()->assertJsonPath('data.name', 'New Person');
        $this->accept($token, 'not-the-account-password')->assertStatus(404);
        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', $existing->id)->count());

        $this->accept($token, self::PASSWORD)->assertOk()->assertJsonPath('data.status', 'invitation_accepted');

        $this->assertSame(1, DB::table('users')->where('email', 'new.person@example.com')->count());
        $this->assertSame($hashBefore, DB::table('users')->where('id', $existing->id)->value('password'));
        $grant = DB::table('user_role_scopes')->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_id', $existing->id)->first(['roles.slug', 'scope_id']);
        $this->assertSame(['dispatcher', $this->mine->id], [$grant->slug, $grant->scope_id]);
        $this->assertSame($inviter->id, DB::table('audit_logs')->where('action', 'role.granted')->where('subject_id', $existing->id)->value('actor_user_id'));
        $this->assertFalse((bool) json_decode((string) DB::table('audit_logs')->where('action', 'staff_invitation.accepted')->value('after_json'), true)['account_created']);
        $this->assertSame(1, DB::table('staff_invitations')->whereNotNull('accepted_at')->count());
    }

    public function test_a_suspended_account_cannot_be_given_a_role_through_an_invitation(): void
    {
        $existing = $this->user('new.person@example.com');
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        DB::table('users')->where('id', $existing->id)->update(['status' => 'suspended']);
        $this->signOut();

        $this->accept($token, self::PASSWORD)->assertStatus(404);

        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', $existing->id)->count());
        $this->assertNull(DB::table('staff_invitations')->value('accepted_at'));
    }

    public function test_an_account_that_already_holds_the_role_is_refused_without_saying_so(): void
    {
        $existing = $this->user('new.person@example.com');
        $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->grantRole($existing, 'dispatcher', 'city', $this->mine->id);
        $this->signOut();

        $this->accept($token, self::PASSWORD)->assertStatus(404);

        $this->assertSame(1, DB::table('user_role_scopes')->where('user_id', $existing->id)->count());
    }

    // ------------------------------------------------------------ the inviter's rights at acceptance

    public function test_an_inviter_who_lost_the_right_cannot_be_the_source_of_a_grant(): void
    {
        $inviter = $this->signedIn('city_manager', $this->mine);
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();
        DB::table('user_role_scopes')->where('user_id', $inviter->id)->update(['revoked_at' => now()]);

        $this->accept($token)->assertStatus(404);

        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
        $this->assertNull(DB::table('staff_invitations')->value('accepted_at'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'staff_invitation.accepted')->count());
    }

    public function test_a_suspended_inviter_cannot_be_the_source_of_a_grant(): void
    {
        $inviter = $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();
        DB::table('users')->where('id', $inviter->id)->update(['status' => 'suspended']);

        $this->accept($token)->assertStatus(404);

        $this->assertSame(0, DB::table('users')->where('email', 'new.person@example.com')->count());
    }

    // ------------------------------------------------------------ the second step still applies

    public function test_a_new_staff_account_must_enrol_two_step_sign_in_before_the_console_in_deployed_environments(): void
    {
        $this->signedIn('platform_owner');
        $this->invite($this->body('platform_admin'))->assertStatus(202);
        $token = $this->lastToken();
        $this->signOut();
        $this->accept($token)->assertOk();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app['env'] = 'production';

        try {
            $this->login('admin', 'new.person@example.com', self::NEW_PASSWORD)->assertOk()->assertJsonPath('data.mfa_enabled', false);

            foreach (['/api/v1/me', '/api/v1/admin/cities', '/api/v1/admin/users', '/api/v1/admin/invitations'] as $path) {
                $this->via('admin')->getJson($path)
                    ->assertForbidden()
                    ->assertJsonPath('error.message', 'Set up two-step sign-in before using the admin console.');
            }
            $this->via('admin')->postJson('/api/v1/admin/invitations', $this->body('support', null, 'x@example.com'))->assertForbidden();
            $this->via('admin')->postJson('/api/v1/me/mfa/totp')->assertCreated();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, $this->invitations());
    }

    // ------------------------------------------------------------ listing

    public function test_the_list_shows_pending_invitations_within_reach_and_never_token_data(): void
    {
        $actor = $this->signedIn('platform_owner');
        $this->invite($this->body('dispatcher', $this->mine, 'a@example.com'))->assertStatus(202);
        $this->invite($this->body('dispatcher', $this->other, 'b@example.com'))->assertStatus(202);
        $this->invite($this->body('support', null, 'c@example.com'))->assertStatus(202);
        $token = $this->lastToken();
        $this->invite($this->body('finance', null, 'used@example.com'))->assertStatus(202);
        $usedToken = $this->lastToken();
        $this->invite($this->body('finance', null, 'gone@example.com'))->assertStatus(202);
        $this->invite($this->body('finance', null, 'old@example.com'))->assertStatus(202);
        DB::table('staff_invitations')->where('email', 'gone@example.com')->update(['revoked_at' => now()]);
        DB::table('staff_invitations')->where('email', 'old@example.com')->update(['expires_at' => now()->subMinute()]);
        $this->signOut();
        $this->accept($usedToken)->assertOk();
        $this->login('admin', 'actor@example.com')->assertOk();

        $response = $this->via('admin')->getJson('/api/v1/admin/invitations')->assertOk();

        $emails = array_column($response->json('data'), 'email');
        sort($emails);
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);
        $row = array_column($response->json('data'), null, 'email')['a@example.com'];
        $this->assertSame(
            ['id', 'name', 'email', 'email_masked', 'role', 'role_label', 'scope_type', 'scope_id', 'city_name', 'invited_by_me', 'created_at', 'expires_at', 'can_revoke'],
            array_keys($row),
        );
        $this->assertTrue($row['invited_by_me']);
        $this->assertTrue($row['can_revoke']);
        $this->assertFalse($row['email_masked']);
        $this->assertSame($this->mine->name, $row['city_name']);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString(hash('sha256', $token), $body);
        $this->assertStringNotContainsString('token', strtolower($body));
    }

    public function test_a_city_manager_sees_and_revokes_only_invitations_in_their_own_city(): void
    {
        $owner = $this->user('owner@example.com');
        $this->grantRole($owner, 'platform_owner');
        $this->login('admin', 'owner@example.com')->assertOk();
        $this->invite($this->body('dispatcher', $this->mine, 'mine@example.com'))->assertStatus(202);
        $this->invite($this->body('dispatcher', $this->other, 'theirs@example.com'))->assertStatus(202);
        $this->invite($this->body('support', null, 'global@example.com'))->assertStatus(202);
        $mineId = (string) DB::table('staff_invitations')->where('email', 'mine@example.com')->value('id');
        $theirsId = (string) DB::table('staff_invitations')->where('email', 'theirs@example.com')->value('id');
        $globalId = (string) DB::table('staff_invitations')->where('email', 'global@example.com')->value('id');
        $this->signOut();
        $this->signedIn('city_manager', $this->mine);

        $response = $this->via('admin')->getJson('/api/v1/admin/invitations')->assertOk();

        $this->assertSame(['mine@example.com'], array_column($response->json('data'), 'email'));
        $this->assertStringNotContainsString($this->other->id, (string) $response->getContent());
        $this->assertStringNotContainsString('theirs@example.com', (string) $response->getContent());

        // Out of reach reads as "not there".
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$theirsId}")->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$globalId}")->assertStatus(404);
        $this->assertSame(3, DB::table('staff_invitations')->whereNull('revoked_at')->count());

        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$mineId}")->assertNoContent();
        $this->assertSame(2, DB::table('staff_invitations')->whereNull('revoked_at')->count());
    }

    public function test_support_sees_masked_addresses_and_cannot_revoke(): void
    {
        $owner = $this->user('owner@example.com');
        $this->grantRole($owner, 'platform_owner');
        $this->login('admin', 'owner@example.com')->assertOk();
        $id = $this->invite($this->body('dispatcher', $this->mine, 'someone@example.com'))->assertStatus(202)->json('data.id');
        $this->signOut();
        $this->signedIn('support');

        $response = $this->via('admin')->getJson('/api/v1/admin/invitations')->assertOk();

        $this->assertStringNotContainsString('someone@example.com', (string) $response->getContent());
        $this->assertSame('s***@example.com', $response->json('data.0.email'));
        $this->assertTrue($response->json('data.0.email_masked'));
        $this->assertFalse($response->json('data.0.can_revoke'));
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertStatus(403);
        $this->assertNull(DB::table('staff_invitations')->value('revoked_at'));
    }

    #[DataProvider('rolesWithoutUserAccess')]
    public function test_roles_without_users_read_get_403_on_the_list_and_404_on_a_named_invitation(string $role): void
    {
        $owner = $this->user('owner@example.com');
        $this->grantRole($owner, 'platform_owner');
        $this->login('admin', 'owner@example.com')->assertOk();
        $id = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202)->json('data.id');
        $this->signOut();
        $this->signedIn($role, $this->mine);

        $this->via('admin')->getJson('/api/v1/admin/invitations')->assertStatus(403);
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertStatus(404);
        $this->assertNull(DB::table('staff_invitations')->value('revoked_at'));
    }

    /** @return array<string, array{string}> */
    public static function rolesWithoutUserAccess(): array
    {
        return ['dispatcher' => ['dispatcher'], 'finance' => ['finance']];
    }

    public function test_the_list_pages_by_cursor(): void
    {
        $this->signedIn('platform_owner');
        foreach (range(1, 3) as $n) {
            $this->invite($this->body('dispatcher', $this->mine, "p{$n}@example.com"))->assertStatus(202);
        }

        $first = $this->via('admin')->getJson('/api/v1/admin/invitations?limit=2')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->via('admin')->getJson('/api/v1/admin/invitations?limit=2&cursor='.$cursor)->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));
        $this->via('admin')->getJson('/api/v1/admin/invitations?cursor=garbage')->assertStatus(422);
    }

    // ------------------------------------------------------------ revoking

    public function test_revoking_withdraws_the_invitation_and_is_audited(): void
    {
        $actor = $this->signedIn('platform_owner');
        $id = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202)->json('data.id');

        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertNoContent();

        $this->assertNotNull(DB::table('staff_invitations')->where('id', $id)->value('revoked_at'));
        $audit = DB::table('audit_logs')->where('action', 'staff_invitation.revoked')->sole();
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($id, $audit->subject_id);
        $this->assertSame($this->mine->id, $audit->scope_city_id);
        $this->assertStringNotContainsString('new.person@example.com', json_encode($audit));
        $this->via('admin')->getJson('/api/v1/admin/invitations')->assertOk()->assertJsonCount(0, 'data');

        // Already gone, malformed, and unknown ids all read the same.
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertStatus(404);
        $this->via('admin')->deleteJson('/api/v1/admin/invitations/not-an-id')->assertStatus(404);
        $this->via('admin')->deleteJson('/api/v1/admin/invitations/01h00000000000000000000000')->assertStatus(404);
    }

    public function test_someone_who_could_not_have_invited_them_cannot_revoke(): void
    {
        $this->signedIn('platform_admin', null, 'admin@example.com');
        $id = $this->invite($this->body('city_manager', $this->mine))->assertStatus(202)->json('data.id');
        $this->signOut();
        $this->signedIn('city_manager', $this->mine);

        $list = $this->via('admin')->getJson('/api/v1/admin/invitations')->assertOk();
        $this->assertFalse($list->json('data.0.can_revoke'));
        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertStatus(403);
        $this->assertNull(DB::table('staff_invitations')->value('revoked_at'));
    }

    public function test_an_accepted_invitation_cannot_be_revoked(): void
    {
        $this->signedIn('platform_owner');
        $id = $this->invite($this->body('dispatcher', $this->mine))->assertStatus(202)->json('data.id');
        $token = $this->lastToken();
        $this->signOut();
        $this->accept($token)->assertOk();
        $this->login('admin', 'actor@example.com')->assertOk();

        $this->via('admin')->deleteJson("/api/v1/admin/invitations/{$id}")->assertStatus(404);
        $this->assertNull(DB::table('staff_invitations')->value('revoked_at'));
    }

    private function pinToSecond(): void
    {
        $this->travelTo(now()->startOfSecond());
    }
}
