<?php

namespace Tests\Integration;

use App\Application\Queries\ServiceabilityQuery;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 10.1 SQL checks: the spatial index exists and the serviceability
 * query uses it at a representative scale. The grid is synthetic: 2,500
 * small squares around longitude 100, latitude 10.
 */
class ServiceabilityPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_spatial_indexes_exist(): void
    {
        $indexes = array_column(DB::select("SELECT indexname FROM pg_indexes WHERE indexdef ILIKE '%USING gist%'"), 'indexname');

        $this->assertContains('delivery_zones_coverage_gix', $indexes);
        $this->assertContains('coverage_exclusions_geometry_gix', $indexes);
    }

    public function test_serviceability_uses_the_spatial_index_at_scale(): void
    {
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);

        DB::insert(<<<'SQL'
            INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage)
            SELECT lpad(n::text, 26, '0'), ?, 'Zone ' || n, 'zone-' || n, 'active',
                   ST_Multi(ST_MakeEnvelope(100 + (n % 50) * 0.02, 10 + (n / 50) * 0.02,
                                            100 + (n % 50) * 0.02 + 0.015, 10 + (n / 50) * 0.02 + 0.015, 4326))
            FROM generate_series(0, 2499) AS n
        SQL, [$city->id]);
        DB::statement('ANALYZE delivery_zones');

        $plan = implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::select('EXPLAIN '.ServiceabilityQuery::SQL, ['longitude' => 100.505, 'latitude' => 10.505]),
        ));

        $this->assertStringContainsString('delivery_zones_coverage_gix', $plan);
        $this->assertStringNotContainsString('Seq Scan on delivery_zones', $plan);

        // And the same query still answers correctly: the point is in zone 1275.
        $match = DB::selectOne(ServiceabilityQuery::SQL, ['longitude' => 100.505, 'latitude' => 10.505]);
        $this->assertSame(str_pad('1275', 26, '0', STR_PAD_LEFT), $match?->zone_id);
    }
}
