<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Covers the spec's ZoneActivateTest and the zone status transitions
 * (backlog C-05, C-06). Shapes are synthetic squares near longitude 100,
 * latitude 10.
 */
class AdminZoneStatusTest extends AuthTestCase
{
    private function staff(string $role, ?City $city = null, string $email = 'staff@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    private function city(string $status = 'active'): City
    {
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => $status]);

        return $city;
    }

    private function zone(City $city, string $status = 'draft'): string
    {
        $id = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$id, $city->id, 'Zone', 'z-'.$id, $status, '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}'],
        );

        return $id;
    }

    private function zoneStatus(string $zone): string
    {
        return (string) DB::table('delivery_zones')->where('id', $zone)->value('status');
    }

    public function test_activation_needs_explicit_confirmation(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['confirm']]]);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1, 'confirm' => false])
            ->assertStatus(422);

        $this->assertSame('draft', $this->zoneStatus($zone));
    }

    public function test_a_draft_zone_in_an_active_city_activates_and_becomes_serviceable(): void
    {
        $owner = $this->staff('platform_owner');
        $city = $this->city();
        $zone = $this->zone($city);

        $this->getJson('/api/v1/locations/serviceability?latitude=10.01&longitude=100.01')->assertJsonPath('data.serviceable', false);

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1, 'confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.version', 2);

        $this->getJson('/api/v1/locations/serviceability?latitude=10.01&longitude=100.01')
            ->assertJsonPath('data.serviceable', true)->assertJsonPath('data.zone.id', $zone);

        $audit = DB::table('audit_logs')->where('action', 'zone.status_changed')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($city->id, $audit->scope_city_id);
        $this->assertSame('active', json_decode((string) $audit->after_json, true)['status']);
    }

    public function test_a_zone_cannot_activate_unless_its_city_is_active(): void
    {
        $this->staff('platform_owner');

        foreach (['draft', 'paused', 'archived'] as $cityStatus) {
            $zone = $this->zone($this->city($cityStatus));
            $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1, 'confirm' => true])
                ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
            $this->assertSame('draft', $this->zoneStatus($zone));
        }
    }

    public function test_pause_needs_a_reason_and_records_it(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city(), 'active');

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/pause", ['version' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/pause", ['version' => 1, 'reason' => '   '])
            ->assertStatus(422);
        $this->assertSame('active', $this->zoneStatus($zone));

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/pause", ['version' => 1, 'reason' => 'Road closed'])
            ->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.version', 2);

        $audit = DB::table('audit_logs')->where('action', 'zone.status_changed')->sole();
        $this->assertSame('Road closed', json_decode((string) $audit->after_json, true)['reason']);
        $this->getJson('/api/v1/locations/serviceability?latitude=10.01&longitude=100.01')->assertJsonPath('data.serviceable', false);
    }

    public function test_status_moves_only_along_allowed_transitions(): void
    {
        $this->staff('platform_owner');
        $city = $this->city();
        $refused = [
            ['draft', 'pause', ['reason' => 'x']],
            ['active', 'activate', ['confirm' => true]],
            ['active', 'archive', []],
            ['paused', 'pause', ['reason' => 'x']],
            ['archived', 'activate', ['confirm' => true]],
            ['archived', 'pause', ['reason' => 'x']],
            ['archived', 'archive', []],
        ];

        foreach ($refused as [$from, $action, $body]) {
            $zone = $this->zone($city, $from);
            $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/{$action}", ['version' => 1] + $body)->assertStatus(409);
            $this->assertSame($from, $this->zoneStatus($zone));
        }

        // Allowed: paused back to active, and draft or paused to archived.
        $paused = $this->zone($city, 'paused');
        $this->via('admin')->postJson("/api/v1/admin/zones/{$paused}/activate", ['version' => 1, 'confirm' => true])->assertOk();
        foreach (['draft', 'paused'] as $from) {
            $zone = $this->zone($city, $from);
            $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/archive", ['version' => 1])
                ->assertOk()->assertJsonPath('data.status', 'archived');
        }

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'zone.status_changed')->whereNull('before_json')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'zone.status_changed')->count());
    }

    public function test_a_stale_version_is_a_conflict(): void
    {
        $this->staff('platform_owner');
        $zone = $this->zone($this->city());
        $this->via('admin')->patchJson("/api/v1/admin/zones/{$zone}", ['version' => 1, 'name' => 'Edited'])->assertOk();

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1, 'confirm' => true])->assertStatus(409);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['confirm' => true])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['version']]]);

        $this->assertSame('draft', $this->zoneStatus($zone));
    }

    public function test_permission_and_city_scope_are_enforced(): void
    {
        $mine = $this->city();
        $other = $this->city();
        $own = $this->zone($mine);
        $foreign = $this->zone($other);

        // A dispatcher can read zones but not change their status.
        $this->staff('dispatcher', $mine, 'dispatch@example.com');
        $this->via('admin')->postJson("/api/v1/admin/zones/{$own}/activate", ['version' => 1, 'confirm' => true])->assertStatus(403);
        $this->via('admin')->postJson('/api/v1/auth/logout')->assertNoContent();

        // A city manager acts in their own city only.
        $this->staff('city_manager', $mine);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$foreign}/activate", ['version' => 1, 'confirm' => true])->assertStatus(404);
        $this->via('admin')->postJson("/api/v1/admin/zones/{$own}/activate", ['version' => 1, 'confirm' => true])->assertOk();

        $this->assertSame('draft', $this->zoneStatus($foreign));
    }

    public function test_status_routes_need_a_session(): void
    {
        $zone = $this->zone($this->city());

        foreach (['activate', 'pause', 'archive'] as $action) {
            $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/{$action}", ['version' => 1])->assertStatus(401);
        }
    }
}
