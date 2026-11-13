<?php

namespace Tests\Integration;

use App\Application\Queries\AccessChecker;
use App\Domain\Identity\Scope;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Covers the spec's PermissionResolverTest and ScopePolicyTest (10.1) and the
 * authorization cases in docs/qa/phase1-acceptance.md.
 */
class AccessCheckerTest extends RbacTestCase
{
    private function access(): AccessChecker
    {
        return app(AccessChecker::class);
    }

    public function test_a_user_without_grants_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $city = City::factory()->create();

        foreach (config('rbac.permissions') as $permission) {
            $this->assertFalse($this->access()->allows($user, $permission, Scope::global()), $permission);
            $this->assertFalse($this->access()->allows($user, $permission, Scope::city($city->id)), $permission);
        }
    }

    public function test_customer_and_rider_roles_carry_no_permissions(): void
    {
        foreach (['customer', 'rider'] as $role) {
            $user = $this->userWith($role);

            foreach (config('rbac.permissions') as $permission) {
                $this->assertFalse($this->access()->allows($user, $permission, Scope::global()), "{$role}: {$permission}");
            }
        }
    }

    public function test_global_grant_covers_every_city(): void
    {
        $owner = $this->userWith('platform_owner');
        $city = City::factory()->create();

        $this->assertTrue($this->access()->allows($owner, 'cities.write', Scope::global()));
        $this->assertTrue($this->access()->allows($owner, 'zones.write', Scope::city($city->id)));
    }

    public function test_city_grant_covers_its_own_city_only(): void
    {
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $manager = $this->userWith('city_manager', $cityA);

        $this->assertTrue($this->access()->allows($manager, 'zones.write', Scope::city($cityA->id)));
        $this->assertFalse($this->access()->allows($manager, 'zones.write', Scope::city($cityB->id)), 'another city');
        $this->assertFalse($this->access()->allows($manager, 'zones.write', Scope::global()), 'global target');
    }

    public function test_scope_does_not_add_permissions_the_role_lacks(): void
    {
        $city = City::factory()->create();
        $manager = $this->userWith('city_manager', $city);
        $dispatcher = $this->userWith('dispatcher', $city);

        $this->assertFalse($this->access()->allows($manager, 'cities.write', Scope::city($city->id)));
        $this->assertFalse($this->access()->allows($manager, 'audit.read', Scope::city($city->id)));
        $this->assertFalse($this->access()->allows($dispatcher, 'zones.write', Scope::city($city->id)));
        $this->assertTrue($this->access()->allows($dispatcher, 'zones.read', Scope::city($city->id)));
    }

    public function test_only_the_owner_may_change_feature_flags(): void
    {
        $this->assertTrue($this->access()->allows($this->userWith('platform_owner'), 'flags.write', Scope::global()));
        $this->assertFalse($this->access()->allows($this->userWith('platform_admin'), 'flags.write', Scope::global()));
    }

    public function test_revoked_grant_stops_working_immediately(): void
    {
        $city = City::factory()->create();
        $manager = $this->userWith('city_manager', $city);
        $this->assertTrue($this->access()->allows($manager, 'zones.write', Scope::city($city->id)));

        DB::table('user_role_scopes')->where('user_id', $manager->id)->update(['revoked_at' => now()]);

        // Same model instance, no reload: the answer must come from storage.
        $this->assertFalse($this->access()->allows($manager, 'zones.write', Scope::city($city->id)));
    }

    public function test_suspended_user_is_denied_even_with_a_live_grant(): void
    {
        $owner = $this->userWith('platform_owner');
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);

        $this->assertFalse($this->access()->allows($owner, 'cities.read', Scope::global()));
        $this->assertSame([], $this->access()->rolesCovering($owner, Scope::global()));
    }

    public function test_city_grant_reaches_branches_in_that_city_only(): void
    {
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $branchInA = $this->insertMerchantWithBranch($cityA);
        $branchInB = $this->insertMerchantWithBranch($cityB);
        $manager = $this->userWith('city_manager', $cityA);

        $this->assertTrue($this->access()->allows($manager, 'merchants.read', Scope::branch($branchInA)));
        $this->assertFalse($this->access()->allows($manager, 'merchants.read', Scope::branch($branchInB)));
    }

    public function test_city_reach_lists_cities_for_query_filtering(): void
    {
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();
        $manager = $this->userWith('city_manager', $cityA);
        DB::table('user_role_scopes')->insert([
            'id' => $this->ulid(), 'user_id' => $manager->id,
            'role_id' => DB::table('roles')->where('slug', 'dispatcher')->value('id'),
            'scope_type' => 'city', 'scope_id' => $cityB->id,
        ]);

        $this->assertSame(['all' => false, 'city_ids' => [$cityA->id]], $this->access()->cityReach($manager, 'zones.write'));
        $this->assertEqualsCanonicalizing([$cityA->id, $cityB->id], $this->access()->cityReach($manager, 'zones.read')['city_ids']);
        $this->assertSame(['all' => true, 'city_ids' => []], $this->access()->cityReach($this->userWith('platform_owner'), 'zones.write'));
        $this->assertSame(['all' => false, 'city_ids' => []], $this->access()->cityReach(User::factory()->create(), 'zones.read'));
    }

    public function test_scope_rejects_malformed_values(): void
    {
        foreach ([['planet', null], ['global', '01jexample0000000000000000'], ['city', null], ['city', 'not-a-ulid'], ['city', '01JEXAMPLE0000000000000000']] as [$type, $id]) {
            try {
                Scope::of($type, $id);
                $this->fail("Scope::of({$type}) accepted a malformed value.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
