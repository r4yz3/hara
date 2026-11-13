<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Spec 10.1 CrossTenantIsolationTest (backlog C-12), city scope. Staff whose
 * roles are anchored to one city try every admin route that names a record
 * against another city's records. Each attempt must look like the record
 * does not exist (404, ADR-005) and must change nothing.
 *
 * The attempt list is checked against the registered routes, so adding an
 * admin route with a path parameter fails this test until it is covered.
 *
 * Merchant scope is not covered: no merchant endpoint exists in Phase 1 yet.
 */
class CrossTenantIsolationTest extends AuthTestCase
{
    private const SQUARE = '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}';

    private City $mine;

    private City $other;

    private string $zone;

    private string $exclusion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = City::factory()->create();
        $this->other = City::factory()->create();
        DB::table('cities')->update(['status' => 'active']);

        $this->zone = strtolower((string) Str::ulid());
        DB::insert(
            "INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, 'Theirs', 'theirs', 'draft', ST_Multi(ST_GeomFromGeoJSON(?)))",
            [$this->zone, $this->other->id, self::SQUARE],
        );
        $this->exclusion = strtolower((string) Str::ulid());
        DB::insert(
            "INSERT INTO coverage_exclusions (id, delivery_zone_id, reason, geometry) VALUES (?, ?, 'Theirs', ST_Multi(ST_GeomFromGeoJSON(?)))",
            [$this->exclusion, $this->zone, self::SQUARE],
        );
    }

    /**
     * Route name => [method, path, body], each aimed at the other city.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    private function attempts(): array
    {
        $city = $this->other->id;
        $geometry = json_decode(self::SQUARE, true);

        return [
            'admin.cities.show' => ['GET', "cities/{$city}", []],
            'admin.cities.update' => ['PATCH', "cities/{$city}", ['name' => 'Taken over', 'status' => 'archived']],
            'admin.zones.index' => ['GET', "cities/{$city}/zones", []],
            'admin.zones.store' => ['POST', "cities/{$city}/zones", ['name' => 'Planted', 'slug' => 'planted', 'geometry' => $geometry]],
            'admin.zones.show' => ['GET', "zones/{$this->zone}", []],
            'admin.zones.update' => ['PATCH', "zones/{$this->zone}", ['version' => 1, 'name' => 'Taken over']],
            'admin.zones.activate' => ['POST', "zones/{$this->zone}/activate", ['version' => 1, 'confirm' => true]],
            'admin.zones.pause' => ['POST', "zones/{$this->zone}/pause", ['version' => 1, 'reason' => 'x']],
            'admin.zones.archive' => ['POST', "zones/{$this->zone}/archive", ['version' => 1]],
            'admin.exclusions.index' => ['GET', "zones/{$this->zone}/exclusions", []],
            'admin.exclusions.store' => ['POST', "zones/{$this->zone}/exclusions", ['reason' => 'Planted', 'geometry' => $geometry]],
            'admin.exclusions.destroy' => ['DELETE', "exclusions/{$this->exclusion}", []],
        ];
    }

    /** Everything that belongs to the other city, as stored. */
    private function snapshot(): string
    {
        return (string) json_encode([
            DB::table('cities')->where('id', $this->other->id)->get(),
            DB::table('delivery_zones')->where('city_id', $this->other->id)->orderBy('id')->get(['id', 'name', 'slug', 'status', 'priority', 'version', 'updated_at']),
            DB::table('coverage_exclusions')->where('delivery_zone_id', $this->zone)->orderBy('id')->get(['id', 'reason', 'active_from', 'active_until']),
            DB::table('audit_logs')->where('scope_city_id', $this->other->id)->count(),
        ]);
    }

    public function test_every_parameterised_admin_route_is_in_the_attempt_list(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/admin/') && $route->parameterNames() !== [])
            // Not tied to a city record: deployment-wide `users.mfa.reset`, 403 for any city grant
            // (StaffMfaRecoveryTest::test_city_scoped_staff_get_403_even_if_their_role_were_granted_the_permission).
            ->reject(fn (RoutingRoute $route): bool => $route->getName() === 'admin.users.mfa.reset')
            ->map(fn (RoutingRoute $route): string => (string) $route->getName())
            ->sort()->values()->all();

        $covered = array_keys($this->attempts());
        sort($covered);

        $this->assertSame($registered, $covered);
    }

    /** @return array<string, array{string}> */
    public static function cityBoundRoles(): array
    {
        // Every staff role that can be anchored to a city.
        return [
            'city manager' => ['city_manager'],
            'city-scoped platform admin' => ['platform_admin'],
            'dispatcher' => ['dispatcher'],
            'city-scoped support' => ['support'],
            'city-scoped finance' => ['finance'],
        ];
    }

    #[DataProvider('cityBoundRoles')]
    public function test_staff_of_one_city_cannot_see_or_change_another_city(string $role): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, $role, 'city', $this->mine->id);
        $this->login('admin', 'staff@example.com')->assertOk();
        $before = $this->snapshot();

        foreach ($this->attempts() as $name => [$method, $path, $body]) {
            $response = $this->via('admin')->json($method, "/api/v1/admin/{$path}", $body);

            $this->assertSame(404, $response->status(), "{$role} on {$name}");
            // The refusal says nothing about the record.
            $this->assertStringNotContainsString('Theirs', (string) $response->getContent(), "{$role} on {$name}");
        }

        $this->assertSame($before, $this->snapshot(), "{$role} changed the other city");
    }

    #[DataProvider('cityBoundRoles')]
    public function test_lists_never_include_the_other_city(string $role): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, $role, 'city', $this->mine->id);
        $this->login('admin', 'staff@example.com')->assertOk();
        DB::table('audit_logs')->insert(['id' => strtolower((string) Str::ulid()), 'action' => 'test.theirs', 'subject_type' => 'thing', 'scope_city_id' => $this->other->id]);

        $cities = $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $this->assertSame([$this->mine->id], array_column($cities->json('data'), 'id'));
        $this->via('admin')->getJson('/api/v1/admin/cities?status=active&limit=100')->assertOk()->assertJsonCount(1, 'data');

        $audit = $this->via('admin')->getJson("/api/v1/admin/audit-logs?city_id={$this->other->id}");
        // Either the role has no audit access at all, or it sees nothing of the other city.
        $this->assertContains($audit->status(), [200, 403]);
        $this->assertStringNotContainsString('test.theirs', (string) $audit->getContent());
    }

    public function test_a_grant_for_one_city_is_not_widened_by_request_input(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'city_manager', 'city', $this->mine->id);
        $this->login('admin', 'staff@example.com')->assertOk();
        $before = $this->snapshot();

        // A city id in the body or query is ignored: the path and stored rows decide.
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$this->zone}?city_id={$this->mine->id}", [
            'version' => 1, 'name' => 'Taken over', 'city_id' => $this->mine->id,
        ])->assertStatus(404);
        $this->via('admin')->postJson("/api/v1/admin/cities/{$this->mine->id}/zones", [
            'name' => 'Mine', 'slug' => 'mine', 'geometry' => json_decode(self::SQUARE, true), 'city_id' => $this->other->id,
        ])->assertStatus(201)->assertJsonPath('data.city_id', $this->mine->id);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_revoked_grant_stops_working_at_once(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'city_manager', 'city', $this->mine->id);
        $this->login('admin', 'staff@example.com')->assertOk();
        $this->via('admin')->getJson("/api/v1/admin/cities/{$this->mine->id}")->assertOk();

        DB::table('user_role_scopes')->where('user_id', $user->id)->update(['revoked_at' => now()]);

        // Same session, next request: access is read from storage every time.
        // With no staff role left, the admin app refuses the session itself.
        $this->via('admin')->getJson("/api/v1/admin/cities/{$this->mine->id}")->assertStatus(401);
        $this->via('admin')->getJson("/api/v1/admin/cities/{$this->mine->id}/zones")->assertStatus(401);
    }

    public function test_losing_one_city_keeps_the_other(): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'city_manager', 'city', $this->mine->id);
        $this->grantRole($user, 'city_manager', 'city', $this->other->id);
        $this->login('admin', 'staff@example.com')->assertOk();
        $this->via('admin')->getJson("/api/v1/admin/zones/{$this->zone}")->assertOk();

        DB::table('user_role_scopes')->where('user_id', $user->id)->where('scope_id', $this->other->id)->update(['revoked_at' => now()]);

        $this->via('admin')->getJson("/api/v1/admin/zones/{$this->zone}")->assertStatus(404);
        $this->via('admin')->getJson("/api/v1/admin/cities/{$this->mine->id}")->assertOk();
    }
}
