<?php

namespace Tests\Integration;

use App\Application\Commands\RoleGrantService;
use App\Application\Queries\AccessChecker;
use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\Scope;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoleGrantServiceTest extends RbacTestCase
{
    private function grants(): RoleGrantService
    {
        return app(RoleGrantService::class);
    }

    private function assertDenied(string $reason, callable $action): void
    {
        $grantsBefore = DB::table('user_role_scopes')->count();
        $auditBefore = DB::table('audit_logs')->count();

        try {
            $action();
            $this->fail("Expected denial ({$reason}).");
        } catch (AccessDenied $e) {
            $this->assertSame($reason, $e->reason);
        }

        $this->assertSame($grantsBefore, DB::table('user_role_scopes')->count(), 'a denied grant must store nothing');
        $this->assertSame($auditBefore, DB::table('audit_logs')->count(), 'a denied grant must not be audited as done');
    }

    public function test_owner_grants_a_role_and_it_is_audited_in_the_same_step(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();
        $city = City::factory()->create();

        $grantId = $this->grants()->grant($owner, $target, 'city_manager', Scope::city($city->id));

        $this->assertTrue(app(AccessChecker::class)->allows($target, 'zones.write', Scope::city($city->id)));

        $audit = DB::table('audit_logs')->where('action', 'role.granted')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame($city->id, $audit->scope_city_id);
        // jsonb does not keep key order, so compare without it.
        $this->assertEquals(
            ['grant_id' => $grantId, 'role' => 'city_manager', 'scope_type' => 'city', 'scope_id' => $city->id],
            json_decode($audit->after_json, true),
        );
    }

    public function test_nobody_can_grant_themselves_a_role(): void
    {
        $owner = $this->userWith('platform_owner');

        $this->assertDenied(AccessDenied::SELF_GRANT, fn () => $this->grants()->grant($owner, $owner, 'platform_admin', Scope::global()));
    }

    public function test_a_customer_cannot_grant_anything(): void
    {
        $customer = $this->userWith('customer');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::NOT_PERMITTED, fn () => $this->grants()->grant($customer, $target, 'support', Scope::global()));
    }

    public function test_platform_owner_is_never_grantable_even_by_an_owner(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($owner, $target, 'platform_owner', Scope::global()));
    }

    public function test_onboarding_roles_are_not_grantable_through_this_service(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        foreach (['merchant_owner', 'rider', 'customer'] as $role) {
            $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($owner, $target, $role, Scope::global()));
        }
    }

    public function test_an_admin_cannot_grant_a_role_at_or_above_their_own(): void
    {
        $admin = $this->userWith('platform_admin');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($admin, $target, 'platform_admin', Scope::global()));
        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($admin, $target, 'finance', Scope::global()));
    }

    public function test_a_city_manager_can_grant_dispatcher_in_their_city_and_nothing_else(): void
    {
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $manager = $this->userWith('city_manager', $cityA);
        $target = User::factory()->create();

        $this->grants()->grant($manager, $target, 'dispatcher', Scope::city($cityA->id));
        $this->assertTrue(app(AccessChecker::class)->allows($target, 'dispatch.read', Scope::city($cityA->id)));

        $other = User::factory()->create();
        $this->assertDenied(AccessDenied::NOT_PERMITTED, fn () => $this->grants()->grant($manager, $other, 'dispatcher', Scope::city($cityB->id)));
        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($manager, $other, 'city_manager', Scope::city($cityA->id)));
        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->grant($manager, $other, 'support', Scope::city($cityA->id)));
    }

    public function test_a_city_scoped_admin_cannot_reach_outside_that_city(): void
    {
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $admin = $this->userWith('platform_admin', $cityA);
        $target = User::factory()->create();

        $this->grants()->grant($admin, $target, 'city_manager', Scope::city($cityA->id));

        $other = User::factory()->create();
        $this->assertDenied(AccessDenied::NOT_PERMITTED, fn () => $this->grants()->grant($admin, $other, 'city_manager', Scope::city($cityB->id)));
        $this->assertDenied(AccessDenied::NOT_PERMITTED, fn () => $this->grants()->grant($admin, $other, 'support', Scope::global()));
    }

    public function test_a_role_cannot_be_granted_at_a_scope_it_does_not_support(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::SCOPE_NOT_ALLOWED_FOR_ROLE, fn () => $this->grants()->grant($owner, $target, 'city_manager', Scope::global()));
    }

    public function test_a_grant_needs_a_scope_that_exists(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::SCOPE_TARGET_MISSING, fn () => $this->grants()->grant($owner, $target, 'city_manager', Scope::city($this->ulid())));
    }

    public function test_unknown_roles_and_inactive_targets_are_refused(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        $this->assertDenied(AccessDenied::UNKNOWN_ROLE, fn () => $this->grants()->grant($owner, $target, 'superuser', Scope::global()));

        DB::table('users')->where('id', $target->id)->update(['status' => 'suspended']);
        $this->assertDenied(AccessDenied::TARGET_INACTIVE, fn () => $this->grants()->grant($owner, $target, 'support', Scope::global()));
    }

    public function test_the_same_grant_cannot_be_made_twice(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();

        $this->grants()->grant($owner, $target, 'support', Scope::global());

        $this->assertDenied(AccessDenied::ALREADY_GRANTED, fn () => $this->grants()->grant($owner, $target, 'support', Scope::global()));
    }

    public function test_revoking_removes_access_and_is_audited(): void
    {
        $owner = $this->userWith('platform_owner');
        $target = User::factory()->create();
        $city = City::factory()->create();
        $grantId = $this->grants()->grant($owner, $target, 'city_manager', Scope::city($city->id));

        $this->grants()->revoke($owner, $grantId);

        $this->assertFalse(app(AccessChecker::class)->allows($target, 'zones.write', Scope::city($city->id)));
        $this->assertNotNull(DB::table('user_role_scopes')->where('id', $grantId)->value('revoked_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'role.revoked')->where('subject_id', $target->id)->count());

        // The history row stays, and the role can be granted again later.
        $this->grants()->grant($owner, $target, 'city_manager', Scope::city($city->id));
        $this->assertSame(2, DB::table('user_role_scopes')->where('user_id', $target->id)->count());
    }

    public function test_only_someone_who_could_grant_a_role_can_revoke_it(): void
    {
        $city = City::factory()->create();
        $owner = $this->userWith('platform_owner');
        $manager = $this->userWith('city_manager', $city);
        $admin = User::factory()->create();
        $adminGrant = $this->grants()->grant($owner, $admin, 'platform_admin', Scope::city($city->id));

        $this->assertDenied(AccessDenied::ROLE_NOT_ASSIGNABLE, fn () => $this->grants()->revoke($manager, $adminGrant));
        $this->assertDenied(AccessDenied::GRANT_NOT_FOUND, fn () => $this->grants()->revoke($owner, $this->ulid()));

        $this->grants()->revoke($owner, $adminGrant);
        $this->assertDenied(AccessDenied::GRANT_NOT_FOUND, fn () => $this->grants()->revoke($owner, $adminGrant));
    }

    public function test_a_revoked_grantor_can_no_longer_grant(): void
    {
        $city = City::factory()->create();
        $manager = $this->userWith('city_manager', $city);
        DB::table('user_role_scopes')->where('user_id', $manager->id)->update(['revoked_at' => now()]);

        $this->assertDenied(
            AccessDenied::NOT_PERMITTED,
            fn () => $this->grants()->grant($manager, User::factory()->create(), 'dispatcher', Scope::city($city->id)),
        );
    }
}
