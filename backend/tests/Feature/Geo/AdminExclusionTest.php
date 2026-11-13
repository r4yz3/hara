<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Coverage exclusions through the admin API (backlog C-07). The zone is a
 * synthetic square from (100, 10) to (100.02, 10.02); exclusions are smaller
 * squares inside it.
 */
class AdminExclusionTest extends AuthTestCase
{
    /** @return array<string, mixed> */
    private function square(float $west, float $south, float $size): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[
            [$west, $south], [$west + $size, $south], [$west + $size, $south + $size], [$west, $south + $size], [$west, $south],
        ]]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return $overrides + ['reason' => 'Flooded street', 'geometry' => $this->square(100.005, 10.005, 0.01)];
    }

    private function staff(string $role, ?City $city = null, string $email = 'staff@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    private function city(): City
    {
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);

        return $city;
    }

    private function zone(City $city, string $status = 'active'): string
    {
        $id = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$id, $city->id, 'Zone', 'z-'.$id, $status, (string) json_encode($this->square(100, 10, 0.02))],
        );

        return $id;
    }

    private function serviceable(): bool
    {
        return (bool) $this->getJson('/api/v1/locations/serviceability?latitude=10.01&longitude=100.01')->json('data.serviceable');
    }

    public function test_exclusion_routes_need_a_session(): void
    {
        $zone = $this->zone($this->city());

        $this->via('admin')->getJson("/api/v1/admin/zones/{$zone}/exclusions")->assertStatus(401);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid())->assertStatus(401);
        $this->via('admin')->deleteJson('/api/v1/admin/exclusions/'.str_repeat('0', 26))->assertStatus(401);
    }

    public function test_an_exclusion_is_created_takes_effect_and_is_audited(): void
    {
        $owner = $this->staff('platform_owner');
        $city = $this->city();
        $zone = $this->zone($city);
        $this->assertTrue($this->serviceable());

        $response = $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid())->assertStatus(201);

        $response->assertJsonPath('data.zone_id', $zone)
            ->assertJsonPath('data.reason', 'Flooded street')
            ->assertJsonPath('data.active_from', null)
            ->assertJsonPath('data.active_until', null)
            ->assertJsonPath('data.geometry.type', 'MultiPolygon');
        $this->assertSame(
            ['id', 'zone_id', 'reason', 'active_from', 'active_until', 'geometry', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
        $this->assertFalse($this->serviceable());

        $audit = DB::table('audit_logs')->where('action', 'exclusion.created')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($city->id, $audit->scope_city_id);
        $this->assertStringNotContainsString('coordinates', (string) $audit->after_json);
    }

    public function test_a_time_window_is_stored_in_utc_and_respected(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());

        // Starts tomorrow: not in force yet.
        $from = now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z');
        $until = now()->addDays(2)->setTimezone('+08:00')->format('Y-m-d\TH:i:sP');
        $response = $this->via('admin')
            ->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid(['active_from' => $from, 'active_until' => $until]))
            ->assertStatus(201)
            ->assertJsonPath('data.active_from', $from);

        $this->assertStringEndsWith('Z', (string) $response->json('data.active_until'));
        $this->assertTrue($this->serviceable());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());
        $url = "/api/v1/admin/zones/{$zone}/exclusions";
        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[100.005, 10.005], [100.015, 10.015], [100.015, 10.005], [100.005, 10.015], [100.005, 10.005]]]];

        $this->via('admin')->postJson($url, $this->valid(['reason' => '']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->via('admin')->postJson($url, $this->valid(['geometry' => ['type' => 'Point', 'coordinates' => [100.01, 10.01]]]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);
        $this->via('admin')->postJson($url, $this->valid(['geometry' => $bowtie]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);
        $this->via('admin')->postJson($url, $this->valid(['active_from' => 'tomorrow']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['active_from']]]);
        $this->via('admin')->postJson($url, $this->valid(['active_from' => '2030-01-02T00:00:00Z', 'active_until' => '2030-01-01T00:00:00Z']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['active_until']]]);

        $this->assertSame(0, DB::table('coverage_exclusions')->count());
    }

    public function test_an_exclusion_must_touch_its_zone(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid(['geometry' => $this->square(100.5, 10.5, 0.01)]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);
        $this->assertSame(0, DB::table('coverage_exclusions')->count());
    }

    public function test_an_archived_zone_takes_no_exclusions(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city(), 'archived');

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid())->assertStatus(409);
    }

    public function test_list_and_delete(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());
        $first = (string) $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid())->json('data.id');
        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid(['reason' => 'Second']))->assertStatus(201);

        $page = $this->via('admin')->getJson("/api/v1/admin/zones/{$zone}/exclusions?limit=1")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($first, $page->json('data.0.id'));
        $cursor = (string) $page->json('meta.next_cursor');
        $this->via('admin')->getJson("/api/v1/admin/zones/{$zone}/exclusions?limit=1&cursor={$cursor}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Second');

        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$first}")->assertNoContent();
        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$first}")->assertStatus(404);
        $this->via('admin')->deleteJson('/api/v1/admin/exclusions/nope')->assertStatus(404);

        $this->assertSame(1, DB::table('coverage_exclusions')->count());
        $audit = DB::table('audit_logs')->where('action', 'exclusion.deleted')->sole();
        $this->assertSame('Flooded street', json_decode((string) $audit->before_json, true)['reason']);
    }

    public function test_deleting_the_last_exclusion_restores_service(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());
        $id = (string) $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/exclusions", $this->valid())->json('data.id');
        $this->assertFalse($this->serviceable());

        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$id}")->assertNoContent();
        $this->assertTrue($this->serviceable());
    }

    public function test_permission_and_city_scope_are_enforced(): void
    {
        $mine = $this->city();
        $other = $this->city();
        $own = $this->zone($mine);
        $foreign = $this->zone($other);

        $this->staff('platform_owner', null, 'owner@example.com');
        $foreignExclusion = (string) $this->via('admin')->postJson("/api/v1/admin/zones/{$foreign}/exclusions", $this->valid())->json('data.id');
        $ownExclusion = (string) $this->via('admin')->postJson("/api/v1/admin/zones/{$own}/exclusions", $this->valid())->json('data.id');
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        // A dispatcher reads but cannot write.
        $this->staff('dispatcher', $mine, 'dispatch@example.com');
        $this->via('admin')->getJson("/api/v1/admin/zones/{$own}/exclusions")->assertOk()->assertJsonCount(1, 'data');
        $this->via('admin')->postJson("/api/v1/admin/zones/{$own}/exclusions", $this->valid())->assertStatus(403);
        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$ownExclusion}")->assertStatus(403);
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        // A city manager acts in their own city only.
        $this->staff('city_manager', $mine);
        $this->via('admin')->getJson("/api/v1/admin/zones/{$foreign}/exclusions")->assertStatus(404);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$foreign}/exclusions", $this->valid())->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$foreignExclusion}")->assertStatus(404);
        $this->via('admin')->deleteJson("/api/v1/admin/exclusions/{$ownExclusion}")->assertNoContent();

        $this->assertSame(1, DB::table('coverage_exclusions')->count());
    }
}
