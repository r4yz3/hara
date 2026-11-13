<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Covers the spec's ZoneCreateTest and the stale-update case (backlog C-04).
 * Shapes are synthetic squares near longitude 100, latitude 10; no real
 * place is described.
 */
class AdminZoneTest extends AuthTestCase
{
    /** @return array<string, mixed> */
    private function square(float $west = 100.0, float $south = 10.0, float $size = 0.02): array
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
        return $overrides + ['name' => 'North', 'slug' => 'north', 'priority' => 0, 'geometry' => $this->square()];
    }

    private function staff(string $role, ?City $city = null, string $email = 'staff@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function create(City $city, array $overrides = []): string
    {
        return (string) $this->via('admin')
            ->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid($overrides))
            ->assertStatus(201)
            ->json('data.id');
    }

    public function test_zone_routes_need_a_session(): void
    {
        $city = City::factory()->create();

        $this->via('admin')->getJson("/api/v1/admin/cities/{$city->id}/zones")->assertStatus(401);
        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid())->assertStatus(401);
        $this->assertSame(0, DB::table('delivery_zones')->count());
    }

    public function test_owner_creates_a_draft_zone_with_version_one_and_it_is_audited(): void
    {
        $owner = $this->staff('platform_owner');
        $city = City::factory()->create();

        $response = $this->via('admin')
            ->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid(['status' => 'active', 'version' => 9]))
            ->assertStatus(201);

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.city_id', $city->id)
            ->assertJsonPath('data.geometry.type', 'MultiPolygon');
        $this->assertSame(
            ['id', 'city_id', 'name', 'slug', 'status', 'priority', 'version', 'geometry', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );

        $audit = DB::table('audit_logs')->where('action', 'zone.created')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($city->id, $audit->scope_city_id);
        // The audit row records the change, not the shape.
        $this->assertStringNotContainsString('coordinates', (string) $audit->after_json);
    }

    public function test_structurally_invalid_geometry_is_rejected(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();

        $open = ['type' => 'Polygon', 'coordinates' => [[[100, 10], [100.02, 10], [100.02, 10.02], [100, 10.02]]]];
        $point = ['type' => 'Point', 'coordinates' => [100, 10]];
        $huge = $this->square(100, 10, 5);

        foreach ([$open, $point, $huge, 'not geometry', null] as $geometry) {
            $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid(['geometry' => $geometry]))
                ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);
        }
        $this->assertSame(0, DB::table('delivery_zones')->count());
    }

    public function test_self_intersecting_geometry_is_rejected_by_postgis(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[100, 10], [100.02, 10.02], [100.02, 10], [100, 10.02], [100, 10]]]];

        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid(['geometry' => $bowtie]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);
        $this->assertSame(0, DB::table('delivery_zones')->count());
    }

    public function test_other_fields_are_validated_and_slug_is_unique_per_city(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $other = City::factory()->create();

        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid(['name' => '', 'slug' => 'Bad Slug', 'priority' => 'high']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'slug', 'priority']]]);

        $this->create($city);
        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid())
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['slug']]]);
        // The same slug in another city is fine.
        $this->create($other);

        $this->assertSame(2, DB::table('delivery_zones')->count());
    }

    public function test_an_archived_city_takes_no_new_zones(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'archived']);

        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid())->assertStatus(409);
    }

    public function test_city_scope_is_enforced(): void
    {
        $mine = City::factory()->create();
        $other = City::factory()->create();
        $this->staff('platform_owner', null, 'owner@example.com');
        $foreign = $this->create($other, ['slug' => 'foreign']);
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->staff('city_manager', $mine);
        $own = $this->create($mine);

        $this->via('admin')->getJson("/api/v1/admin/cities/{$mine->id}/zones")->assertOk()->assertJsonCount(1, 'data');
        $this->via('admin')->getJson("/api/v1/admin/cities/{$other->id}/zones")->assertStatus(404);
        $this->via('admin')->postJson("/api/v1/admin/cities/{$other->id}/zones", $this->valid())->assertStatus(404);
        $this->via('admin')->getJson("/api/v1/admin/zones/{$foreign}")->assertStatus(404);
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$foreign}", ['version' => 1, 'name' => 'Mine now'])->assertStatus(404);
        $this->via('admin')->getJson("/api/v1/admin/zones/{$own}")->assertOk();

        $this->assertSame('North', DB::table('delivery_zones')->where('id', $foreign)->value('name'));
    }

    public function test_read_only_staff_cannot_write_zones(): void
    {
        $city = City::factory()->create();
        $this->staff('platform_owner', null, 'owner@example.com');
        $zone = $this->create($city);
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->staff('dispatcher', $city);

        $this->via('admin')->getJson("/api/v1/admin/zones/{$zone}")->assertOk();
        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", $this->valid(['slug' => 'x']))->assertStatus(403);
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'name' => 'X'])->assertStatus(403);
    }

    public function test_update_bumps_the_version_and_is_audited(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $zone = $this->create($city);

        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", [
            'version' => 1, 'name' => 'North Wide', 'priority' => 5, 'geometry' => $this->square(100, 10, 0.05), 'slug' => 'ignored', 'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.name', 'North Wide')
            ->assertJsonPath('data.priority', 5)
            ->assertJsonPath('data.slug', 'north')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.geometry.coordinates.0.0.2', [100.05, 10.05]);

        $audit = DB::table('audit_logs')->where('action', 'zone.updated')->sole();
        $this->assertSame(1, json_decode((string) $audit->before_json, true)['version']);
        $this->assertSame(2, json_decode((string) $audit->after_json, true)['version']);
    }

    public function test_a_stale_version_is_a_conflict_and_changes_nothing(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $zone = $this->create($city);

        // Two editors both loaded version 1; the first one wins.
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'name' => 'First'])->assertOk();
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'name' => 'Second'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $row = DB::table('delivery_zones')->where('id', $zone)->sole();
        $this->assertSame('First', $row->name);
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'zone.updated')->count());
    }

    public function test_update_needs_a_version_and_valid_geometry(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $zone = $this->create($city);
        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[100, 10], [100.02, 10.02], [100.02, 10], [100, 10.02], [100, 10]]]];

        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['name' => 'X'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['version']]]);
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'geometry' => $bowtie])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['geometry']]]);

        $this->assertSame(1, (int) DB::table('delivery_zones')->where('id', $zone)->value('version'));
    }

    public function test_an_archived_zone_cannot_be_edited(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $zone = $this->create($city);
        DB::table('delivery_zones')->where('id', $zone)->update(['status' => 'archived']);

        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'name' => 'X'])->assertStatus(409);
    }

    public function test_list_is_paged_and_omits_geometry(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        foreach (['a', 'b', 'c'] as $slug) {
            $this->create($city, ['slug' => $slug]);
        }

        $first = $this->via('admin')->getJson("/api/v1/admin/cities/{$city->id}/zones?limit=2")->assertOk();
        $first->assertJsonCount(2, 'data');
        $this->assertArrayNotHasKey('geometry', $first->json('data.0'));

        $cursor = (string) $first->json('meta.next_cursor');
        $this->via('admin')->getJson("/api/v1/admin/cities/{$city->id}/zones?limit=2&cursor={$cursor}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_malformed_ids_are_not_found(): void
    {
        $this->staff('platform_owner');

        $this->via('admin')->getJson('/api/v1/admin/zones/nope')->assertStatus(404);
        $this->via('admin')->getJson('/api/v1/admin/zones/'.str_repeat('0', 26))->assertStatus(404);
    }
}
