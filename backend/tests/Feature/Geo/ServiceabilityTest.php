<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the spec's ServiceabilityQueryTest, ServiceabilityContractTest and
 * PublicCitiesTest (10.1) and the geometry cases in
 * docs/qa/phase1-acceptance.md. Shapes are synthetic squares near longitude
 * 100, latitude 10; the pair is asymmetric so a swapped axis cannot pass.
 */
class ServiceabilityTest extends TestCase
{
    use RefreshDatabase;

    private function square(float $west, float $south, float $size = 0.02): string
    {
        return (string) json_encode(['type' => 'Polygon', 'coordinates' => [[
            [$west, $south], [$west + $size, $south], [$west + $size, $south + $size], [$west, $south + $size], [$west, $south],
        ]]]);
    }

    private function city(string $status = 'active'): City
    {
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => $status]);

        return $city;
    }

    private function zone(City $city, string $geoJson, string $status = 'active', int $priority = 0, ?string $name = null): string
    {
        $id = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, priority, coverage) VALUES (?, ?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$id, $city->id, $name ?? 'Zone '.$id, 'z-'.$id, $status, $priority, $geoJson],
        );

        return $id;
    }

    private function exclude(string $zoneId, string $geoJson, ?string $from = null, ?string $until = null): void
    {
        DB::insert(
            "INSERT INTO coverage_exclusions (id, delivery_zone_id, reason, geometry, active_from, active_until) VALUES (?, ?, 'test', ST_Multi(ST_GeomFromGeoJSON(?)), ?, ?)",
            [strtolower((string) Str::ulid()), $zoneId, $geoJson, $from, $until],
        );
    }

    /** @return TestResponse<JsonResponse> */
    private function check(mixed $latitude, mixed $longitude): TestResponse
    {
        return $this->getJson('/api/v1/locations/serviceability?'.http_build_query(['latitude' => $latitude, 'longitude' => $longitude]));
    }

    public function test_nothing_configured_is_not_serviceable_and_invents_no_location(): void
    {
        $response = $this->check(10.01, 100.01)->assertOk();

        $this->assertSame(
            ['serviceable' => false, 'reason' => 'NO_ACTIVE_ZONE', 'city' => null, 'zone' => null],
            $response->json('data'),
        );
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
    }

    public function test_point_inside_an_active_zone_of_an_active_city_is_serviceable(): void
    {
        $city = $this->city();
        $zoneId = $this->zone($city, $this->square(100.0, 10.0), name: 'Core');

        $response = $this->check(10.01, 100.01)->assertOk();

        $this->assertSame(
            ['serviceable' => true, 'reason' => null, 'city' => ['id' => $city->id, 'name' => $city->name], 'zone' => ['id' => $zoneId, 'name' => 'Core']],
            $response->json('data'),
        );
    }

    public function test_boundary_points_count_as_inside(): void
    {
        $this->zone($this->city(), $this->square(100.0, 10.0));

        $this->check(10.01, 100.0)->assertJsonPath('data.serviceable', true);
        $this->check(10.0, 100.0)->assertJsonPath('data.serviceable', true);
        $this->check(10.02, 100.02)->assertJsonPath('data.serviceable', true);
    }

    public function test_point_outside_every_zone_is_not_serviceable(): void
    {
        $this->zone($this->city(), $this->square(100.0, 10.0));

        $this->check(10.01, 100.05)->assertOk()
            ->assertJsonPath('data.serviceable', false)
            ->assertJsonPath('data.reason', 'OUTSIDE_COVERAGE')
            ->assertJsonPath('data.city', null);
    }

    public function test_swapped_axes_do_not_match(): void
    {
        $this->zone($this->city(), $this->square(100.0, 10.0));

        // latitude 100.01 is out of range, so this is a validation error, not a match.
        $this->check(100.01, 10.01)->assertStatus(422);
        // A symmetric-looking but wrong point must not be serviceable either.
        $this->check(10.01, 10.01)->assertJsonPath('data.serviceable', false);
    }

    public function test_draft_paused_and_archived_zones_or_cities_are_not_serviceable(): void
    {
        foreach (['draft', 'paused', 'archived'] as $status) {
            $this->zone($this->city(), $this->square(100.0, 10.0), status: $status);
            $this->zone($this->city($status), $this->square(100.0, 10.0));
        }

        $this->check(10.01, 100.01)->assertJsonPath('data.serviceable', false)->assertJsonPath('data.reason', 'NO_ACTIVE_ZONE');
    }

    public function test_an_active_exclusion_removes_its_area_including_its_boundary(): void
    {
        $zoneId = $this->zone($this->city(), $this->square(100.0, 10.0, 0.1));
        $this->exclude($zoneId, $this->square(100.04, 10.04, 0.02));

        $this->check(10.05, 100.05)->assertJsonPath('data.serviceable', false)->assertJsonPath('data.reason', 'OUTSIDE_COVERAGE');
        $this->check(10.04, 100.05)->assertJsonPath('data.serviceable', false);
        $this->check(10.01, 100.01)->assertJsonPath('data.serviceable', true);
    }

    public function test_exclusions_outside_their_time_window_do_not_apply(): void
    {
        $zoneId = $this->zone($this->city(), $this->square(100.0, 10.0, 0.1));
        $this->exclude($zoneId, $this->square(100.04, 10.04, 0.02), until: now()->subDay()->toIso8601String());
        $this->exclude($zoneId, $this->square(100.04, 10.04, 0.02), from: now()->addDay()->toIso8601String());

        $this->check(10.05, 100.05)->assertJsonPath('data.serviceable', true);
    }

    public function test_overlapping_zones_resolve_by_priority_then_id(): void
    {
        $city = $this->city();
        $low = $this->zone($city, $this->square(100.0, 10.0), priority: 1);
        $high = $this->zone($city, $this->square(100.0, 10.0), priority: 5);
        $this->check(10.01, 100.01)->assertJsonPath('data.zone.id', $high);

        DB::table('delivery_zones')->where('id', $high)->update(['priority' => 1]);
        $this->check(10.01, 100.01)->assertJsonPath('data.zone.id', min($low, $high));
    }

    public function test_a_point_excluded_from_the_top_zone_falls_through_to_the_next(): void
    {
        $city = $this->city();
        $top = $this->zone($city, $this->square(100.0, 10.0), priority: 5);
        $next = $this->zone($city, $this->square(100.0, 10.0), priority: 1);
        $this->exclude($top, $this->square(100.0, 10.0));

        $this->check(10.01, 100.01)->assertJsonPath('data.serviceable', true)->assertJsonPath('data.zone.id', $next);
    }

    public function test_invalid_coordinates_are_rejected_before_any_query(): void
    {
        DB::enableQueryLog();

        foreach ([[91, 100], [-91, 100], [10, 181], [10, -181], ['abc', 100], [10, ''], ['10; DROP TABLE cities', 100], ['NaN', 100], ['1e999', 100], [[10], 100]] as [$latitude, $longitude]) {
            $this->check($latitude, $longitude)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->getJson('/api/v1/locations/serviceability')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['latitude', 'longitude']]]);
        $this->getJson('/api/v1/locations/serviceability?latitude=10')->assertStatus(422);

        $spatial = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'delivery_zones'));
        $this->assertSame([], $spatial);
    }

    public function test_response_never_exposes_geometry_or_internal_fields(): void
    {
        $this->zone($this->city(), $this->square(100.0, 10.0));

        $body = (string) $this->check(10.01, 100.01)->getContent();

        foreach (['coverage', 'coordinates', 'priority', 'version', 'status', 'slug', 'timezone'] as $field) {
            $this->assertStringNotContainsString("\"{$field}\"", $body);
        }
    }

    public function test_public_cities_lists_only_active_cities_with_public_fields(): void
    {
        $this->getJson('/api/v1/public/cities')->assertOk()->assertJsonPath('data', []);

        $active = $this->city();
        $this->city('draft');
        $this->city('paused');
        $this->city('archived');

        $response = $this->getJson('/api/v1/public/cities')->assertOk();

        $this->assertSame([$active->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'name', 'country_code', 'currency_code', 'timezone'], array_keys($response->json('data.0')));
    }

    public function test_serviceability_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->check(10.01, 100.01)->assertOk();
        }

        $this->check(10.01, 100.01)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
