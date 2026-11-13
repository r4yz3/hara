<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * The staff directory and role grants behind "Roles and access" (backlog U-05):
 * GET /admin/users, GET /admin/roles, POST /admin/users/{user}/roles and
 * DELETE /admin/users/{user}/roles/{grant}. Denial and escalation cases come
 * first in spirit: every refusal must also leave the stored grants untouched.
 */
class StaffAccessTest extends AuthTestCase
{
    private City $mine;

    private City $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = City::factory()->create();
        $this->other = City::factory()->create();
    }

    /** A staff member who is not signed in. */
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

    private function grantIdOf(User $user, string $role): string
    {
        return (string) DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $user->id)->where('roles.slug', $role)->whereNull('revoked_at')
            ->value('user_role_scopes.id');
    }

    /** @return array<string, mixed> */
    private function body(string $role, ?City $city): array
    {
        return $city === null
            ? ['role' => $role, 'scope_type' => 'global']
            : ['role' => $role, 'scope_type' => 'city', 'scope_id' => $city->id];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, string $id): array
    {
        $row = array_column($rows, null, 'id')[$id] ?? null;
        $this->assertNotNull($row, "No row for {$id}");

        return $row;
    }

    private function liveGrants(): int
    {
        return DB::table('user_role_scopes')->whereNull('revoked_at')->count();
    }

    // ------------------------------------------------------------ access to the routes

    public function test_every_route_needs_a_session_and_the_admin_app(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');

        foreach ([['GET', '/users'], ['GET', '/roles'], ['POST', "/users/{$target->id}/roles"], ['DELETE', "/users/{$target->id}/roles/{$grant}"]] as [$method, $path]) {
            $this->via('admin')->json($method, "/api/v1/admin{$path}", [])->assertStatus(401);
        }

        $this->user('customer@example.com');
        $this->login('customer', 'customer@example.com')->assertOk();
        foreach ([['GET', '/users'], ['GET', '/roles'], ['POST', "/users/{$target->id}/roles"], ['DELETE', "/users/{$target->id}/roles/{$grant}"]] as [$method, $path]) {
            $this->via('customer')->json($method, "/api/v1/admin{$path}", [])->assertStatus(404);
        }
        $this->assertSame(1, $this->liveGrants());
    }

    /** @return array<string, array{string}> */
    public static function rolesWithoutUserAccess(): array
    {
        return ['dispatcher' => ['dispatcher'], 'finance' => ['finance']];
    }

    #[DataProvider('rolesWithoutUserAccess')]
    public function test_roles_without_users_read_get_403_on_the_lists_and_404_on_a_named_person(string $role): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');
        $this->signedIn($role, $this->mine);
        $before = $this->liveGrants();

        $this->via('admin')->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->via('admin')->getJson('/api/v1/admin/roles')->assertStatus(403);
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', $this->other))->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(404);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_support_can_read_but_never_assign(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');
        $this->signedIn('support');
        $before = $this->liveGrants();

        $this->via('admin')->getJson('/api/v1/admin/users')->assertOk();
        $this->via('admin')->getJson('/api/v1/admin/roles')->assertOk()->assertJsonCount(0, 'data');
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', $this->other))->assertStatus(403);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    // ------------------------------------------------------------ listing

    public function test_the_list_holds_staff_only_and_shows_their_grants(): void
    {
        $this->user('customer@example.com');
        $manager = $this->person('manager@example.com', 'city_manager', $this->mine);
        $this->signedIn('platform_owner');

        $response = $this->via('admin')->getJson('/api/v1/admin/users')->assertOk();

        $emails = array_column($response->json('data'), 'email');
        sort($emails);
        $this->assertSame(['actor@example.com', 'manager@example.com'], $emails);

        $row = $this->rowOf($response->json('data'), $manager->id);
        $this->assertSame('city_manager', $row['grants'][0]['role']);
        $this->assertSame($this->mine->name, $row['grants'][0]['city_name']);
        $this->assertFalse($row['mfa_enrolled']);
        $this->assertFalse($row['is_self']);
        $this->assertFalse($row['email_masked']);
        $this->assertNull($response->json('meta.next_cursor'));
        // Nothing sensitive leaves the server.
        $this->assertStringNotContainsString('password', (string) $response->getContent());
        $this->assertStringNotContainsString('remember_token', (string) $response->getContent());
    }

    public function test_a_city_manager_sees_only_people_and_grants_in_their_own_city(): void
    {
        $this->person('mate@example.com', 'dispatcher', $this->mine);
        $this->person('stranger@example.com', 'dispatcher', $this->other);
        $this->person('admin@example.com', 'platform_admin');
        // Someone with a grant in each city: only the visible one is shown.
        $both = $this->person('both@example.com', 'dispatcher', $this->mine);
        $this->grantRole($both, 'dispatcher', 'city', $this->other->id);
        $this->signedIn('city_manager', $this->mine);

        $response = $this->via('admin')->getJson('/api/v1/admin/users')->assertOk();

        $emails = array_column($response->json('data'), 'email');
        sort($emails);
        $this->assertSame(['actor@example.com', 'both@example.com', 'mate@example.com'], $emails);
        $row = $this->rowOf($response->json('data'), $both->id);
        $this->assertCount(1, $row['grants']);
        $this->assertSame($this->mine->id, $row['grants'][0]['scope_id']);
        $this->assertStringNotContainsString($this->other->id, (string) $response->getContent());
    }

    public function test_support_sees_masked_addresses(): void
    {
        $this->person('someone@example.com', 'dispatcher', $this->mine);
        $this->signedIn('support');

        $response = $this->via('admin')->getJson('/api/v1/admin/users')->assertOk();

        $this->assertStringNotContainsString('someone@example.com', (string) $response->getContent());
        $this->assertContains('s***@example.com', array_column($response->json('data'), 'email'));
        $this->assertSame([true], array_values(array_unique(array_column($response->json('data'), 'email_masked'))));
    }

    public function test_the_list_pages_by_cursor(): void
    {
        foreach (range(1, 3) as $n) {
            $this->person("p{$n}@example.com", 'dispatcher', $this->mine);
        }
        $this->signedIn('platform_owner');

        $first = $this->via('admin')->getJson('/api/v1/admin/users?limit=3')->assertOk();
        $this->assertCount(3, $first->json('data'));
        $second = $this->via('admin')->getJson('/api/v1/admin/users?limit=3&cursor='.urlencode((string) $first->json('meta.next_cursor')))->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));
        $this->via('admin')->getJson('/api/v1/admin/users?limit=0')->assertStatus(422);
        $this->via('admin')->getJson('/api/v1/admin/users?cursor=%25%25')->assertStatus(422);
    }

    public function test_can_revoke_tells_the_screen_what_would_be_allowed(): void
    {
        $dispatcher = $this->person('d@example.com', 'dispatcher', $this->mine);
        $peer = $this->person('peer@example.com', 'city_manager', $this->mine);
        $me = $this->signedIn('city_manager', $this->mine);

        $rows = array_column($this->via('admin')->getJson('/api/v1/admin/users')->assertOk()->json('data'), null, 'id');

        $this->assertTrue($rows[$dispatcher->id]['grants'][0]['can_revoke']);
        $this->assertFalse($rows[$peer->id]['grants'][0]['can_revoke']);
        $self = $rows[$me->id];
        $this->assertFalse($self['grants'][0]['can_revoke']);
    }

    // ------------------------------------------------------------ the roles on offer

    public function test_roles_on_offer_follow_what_the_caller_may_hand_out(): void
    {
        $this->signedIn('platform_owner');
        $owner = array_column($this->via('admin')->getJson('/api/v1/admin/roles')->assertOk()->json('data'), null, 'slug');

        $this->assertEqualsCanonicalizing(['platform_admin', 'city_manager', 'dispatcher', 'finance', 'support'], array_keys($owner));
        $this->assertTrue($owner['platform_admin']['global']);
        $this->assertTrue($owner['city_manager']['all_cities']);
        $this->assertFalse($owner['city_manager']['global']);
    }

    public function test_a_city_manager_is_offered_only_the_dispatcher_role_for_their_city(): void
    {
        $this->signedIn('city_manager', $this->mine);

        $roles = $this->via('admin')->getJson('/api/v1/admin/roles')->assertOk()->json('data');

        $this->assertCount(1, $roles);
        $this->assertSame('dispatcher', $roles[0]['slug']);
        $this->assertSame([$this->mine->id], $roles[0]['city_ids']);
        $this->assertFalse($roles[0]['all_cities']);
        $this->assertFalse($roles[0]['global']);
    }

    // ------------------------------------------------------------ granting

    public function test_a_platform_admin_grants_a_city_manager_role_and_it_is_audited(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->other);
        $actor = $this->signedIn('platform_admin');

        $response = $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('city_manager', $this->other))
            ->assertStatus(201);

        $roles = array_column($response->json('data.grants'), 'role');
        sort($roles);
        $this->assertSame(['city_manager', 'dispatcher'], $roles);
        $this->assertSame(1, DB::table('user_role_scopes')->where('user_id', $target->id)->whereNull('revoked_at')
            ->where('scope_id', $this->other->id)->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')->where('roles.slug', 'city_manager')->count());

        $audit = DB::table('audit_logs')->where('action', 'role.granted')->where('subject_id', $target->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame($this->other->id, $audit->scope_city_id);
    }

    public function test_a_city_manager_grants_a_dispatcher_in_their_own_city_only(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('city_manager', $this->mine);
        $before = $this->liveGrants();

        // Another city: not theirs to hand out.
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', $this->other))->assertStatus(403);
        // A global grant: not theirs either.
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', null))->assertStatus(403);
        $this->assertSame($before, $this->liveGrants());

        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('support', $this->mine))->assertStatus(403);
    }

    /** @return array<string, array{string}> */
    public static function rolesAboveACityManager(): array
    {
        return [
            'another city manager' => ['city_manager'],
            'platform admin' => ['platform_admin'],
            'platform owner' => ['platform_owner'],
            'finance' => ['finance'],
            'support' => ['support'],
            'a merchant role' => ['merchant_owner'],
            'rider' => ['rider'],
            'customer' => ['customer'],
        ];
    }

    #[DataProvider('rolesAboveACityManager')]
    public function test_a_city_manager_cannot_climb(string $role): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('city_manager', $this->mine);
        $before = $this->liveGrants();

        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body($role, $this->mine))->assertStatus(403);
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body($role, null))->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    /** @return array<string, array{string}> */
    public static function rolesNobodyMayGrant(): array
    {
        return ['platform owner' => ['platform_owner'], 'merchant owner' => ['merchant_owner'], 'rider' => ['rider'], 'customer' => ['customer']];
    }

    #[DataProvider('rolesNobodyMayGrant')]
    public function test_even_a_platform_owner_cannot_grant_these_roles(string $role): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('platform_owner');
        $before = $this->liveGrants();

        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body($role, $this->mine))->assertStatus(403);
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body($role, null))->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_a_platform_admin_cannot_make_another_platform_admin(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('platform_admin');
        $before = $this->liveGrants();

        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('platform_admin', null))->assertStatus(403);
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('finance', null))->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $owner = $this->signedIn('platform_owner');
        $this->person('manager@example.com', 'city_manager', $this->mine);
        $before = $this->liveGrants();

        $this->via('admin')->postJson("/api/v1/admin/users/{$owner->id}/roles", $this->body('support', null))
            ->assertStatus(403)->assertJsonPath('error.message', 'You cannot change your own roles.');
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$owner->id}/roles/{$this->grantIdOf($owner, 'platform_owner')}")->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_a_city_manager_cannot_revoke_or_add_to_their_own_grants(): void
    {
        $me = $this->signedIn('city_manager', $this->mine);
        $before = $this->liveGrants();

        $this->via('admin')->postJson("/api/v1/admin/users/{$me->id}/roles", $this->body('dispatcher', $this->mine))->assertStatus(403);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$me->id}/roles/{$this->grantIdOf($me, 'city_manager')}")->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_a_person_outside_the_callers_view_does_not_exist_for_them(): void
    {
        $theirs = $this->person('theirs@example.com', 'dispatcher', $this->other);
        $global = $this->person('global@example.com', 'platform_admin');
        $customer = $this->user('customer@example.com');
        $this->signedIn('city_manager', $this->mine);
        $before = $this->liveGrants();

        foreach ([$theirs->id, $global->id, $customer->id, '01jnotarealuser000000000000', 'not-an-id', strtoupper($theirs->id)] as $id) {
            $this->via('admin')->postJson("/api/v1/admin/users/{$id}/roles", $this->body('dispatcher', $this->mine))->assertStatus(404);
        }

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_input_is_validated(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('platform_owner');
        $url = "/api/v1/admin/users/{$target->id}/roles";
        $before = $this->liveGrants();

        $this->via('admin')->postJson($url, [])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'dispatcher', 'scope_type' => 'city'])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'dispatcher', 'scope_type' => 'global', 'scope_id' => $this->mine->id])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'dispatcher', 'scope_type' => 'merchant', 'scope_id' => $this->mine->id])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'dispatcher', 'scope_type' => 'city', 'scope_id' => 'nope'])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'Drop Table', 'scope_type' => 'global'])->assertStatus(422);
        $this->via('admin')->postJson($url, ['role' => 'no_such_role', 'scope_type' => 'global'])->assertStatus(422)->assertJsonPath('error.fields.role.0', 'That role does not exist.');
        // A well-formed city that does not exist.
        $this->via('admin')->postJson($url, ['role' => 'dispatcher', 'scope_type' => 'city', 'scope_id' => '01jnotarealcity00000000000'])->assertStatus(404);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_the_same_role_twice_is_a_conflict_and_a_suspended_person_is_refused(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $this->signedIn('platform_owner');
        $url = "/api/v1/admin/users/{$target->id}/roles";

        $this->via('admin')->postJson($url, $this->body('dispatcher', $this->mine))->assertStatus(409);

        DB::table('users')->where('id', $target->id)->update(['status' => 'suspended']);
        $this->via('admin')->postJson($url, $this->body('support', null))->assertStatus(409);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'role.granted')->count());
    }

    // ------------------------------------------------------------ revoking

    public function test_a_city_manager_revokes_a_dispatcher_and_it_is_audited(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');
        $actor = $this->signedIn('city_manager', $this->mine);

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(204);

        $this->assertNotNull(DB::table('user_role_scopes')->where('id', $grant)->value('revoked_at'));
        $audit = DB::table('audit_logs')->where('action', 'role.revoked')->where('subject_id', $target->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($actor->id, $audit->actor_user_id);

        // Gone from the list, and a second attempt finds nothing.
        $this->via('admin')->getJson('/api/v1/admin/users')->assertOk()->assertJsonMissing(['email' => 'target@example.com']);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(404);
    }

    public function test_revoking_the_last_staff_role_leaves_the_person_without_staff_grants(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');
        $this->signedIn('city_manager', $this->mine);

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(204);

        $this->assertSame(0, DB::table('user_role_scopes')->where('user_id', $target->id)->whereNull('revoked_at')->count());
        // No longer a staff member from this caller's view: not listed, not reachable.
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', $this->mine))->assertStatus(404);
    }

    public function test_revoking_is_refused_where_granting_would_be(): void
    {
        $peer = $this->person('peer@example.com', 'city_manager', $this->mine);
        $owner = $this->person('owner@example.com', 'platform_owner');
        $admin = $this->person('admin@example.com', 'platform_admin');
        $this->signedIn('city_manager', $this->mine);
        $before = $this->liveGrants();

        // A peer city manager: refused. The global grants are outside this caller's view.
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$peer->id}/roles/{$this->grantIdOf($peer, 'city_manager')}")->assertStatus(403);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$owner->id}/roles/{$this->grantIdOf($owner, 'platform_owner')}")->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$admin->id}/roles/{$this->grantIdOf($admin, 'platform_admin')}")->assertStatus(404);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_the_platform_owner_role_cannot_be_revoked_by_a_platform_admin(): void
    {
        $owner = $this->person('owner@example.com', 'platform_owner');
        $this->signedIn('platform_admin');
        $before = $this->liveGrants();

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$owner->id}/roles/{$this->grantIdOf($owner, 'platform_owner')}")->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_the_platform_owner_role_cannot_be_revoked_even_by_another_owner(): void
    {
        $second = $this->person('second@example.com', 'platform_owner');
        $this->signedIn('platform_owner');
        $before = $this->liveGrants();

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$second->id}/roles/{$this->grantIdOf($second, 'platform_owner')}")->assertStatus(403);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_a_grant_must_belong_to_the_person_in_the_path(): void
    {
        $a = $this->person('a@example.com', 'dispatcher', $this->mine);
        $b = $this->person('b@example.com', 'dispatcher', $this->mine);
        $this->signedIn('platform_owner');
        $before = $this->liveGrants();

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$a->id}/roles/{$this->grantIdOf($b, 'dispatcher')}")->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/users/{$a->id}/roles/not-an-id")->assertStatus(404);

        $this->assertSame($before, $this->liveGrants());
    }

    public function test_a_role_can_be_granted_again_after_it_was_revoked(): void
    {
        $target = $this->person('target@example.com', 'dispatcher', $this->mine);
        $grant = $this->grantIdOf($target, 'dispatcher');
        $this->grantRole($target, 'dispatcher', 'city', $this->other->id);
        $this->signedIn('platform_owner');

        $this->via('admin')->deleteJson("/api/v1/admin/users/{$target->id}/roles/{$grant}")->assertStatus(204);
        $this->via('admin')->postJson("/api/v1/admin/users/{$target->id}/roles", $this->body('dispatcher', $this->mine))->assertStatus(201);

        // History is kept: the old row stays, revoked, and a new live row exists.
        $this->assertSame(1, DB::table('user_role_scopes')->where('id', $grant)->whereNotNull('revoked_at')->count());
        $this->assertSame(2, DB::table('audit_logs')->whereIn('action', ['role.revoked', 'role.granted'])->count());
    }
}
