<?php

namespace Tests\Integration;

use App\Models\City;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Backlog C-03 fuzz test, spatial half. Random polygons (random vertex
 * order makes many of them cross themselves; some get a hole, inside or
 * not) are posted to the real zone-create endpoint. The answer is compared
 * with PostGIS asked directly: 201 exactly when ST_IsValid says the shape is
 * valid, 422 otherwise, never anything else. Every stored shape must be
 * valid and identical to what was sent.
 *
 * Synthetic coordinates near longitude 100, latitude 10. Seeded; the seed
 * is in each failure message and FUZZ_SEED=<n> replays it.
 */
class ZoneGeometryPostgisFuzzTest extends AuthTestCase
{
    private const CASES = 150;

    private function fuzzSeed(): int
    {
        $env = getenv('FUZZ_SEED');

        return is_string($env) && ctype_digit($env) ? (int) $env : 20261113;
    }

    /** @return list<array{float, float}> */
    private function ring(float $west, float $south, float $size, int $points): array
    {
        $ring = [];
        for ($i = 0; $i < $points; $i++) {
            $ring[] = [round($west + mt_rand(0, 1000) / 1000 * $size, 6), round($south + mt_rand(0, 1000) / 1000 * $size, 6)];
        }
        $ring[] = $ring[0];

        return $ring;
    }

    /** @return array{type: string, coordinates: mixed} */
    private function randomPolygon(): array
    {
        $outer = $this->ring(100.0, 10.0, 0.05, mt_rand(3, 9));
        $rings = [$outer];

        if (mt_rand(0, 3) === 0) {
            // A hole: sometimes well inside the outer box, sometimes anywhere.
            $rings[] = mt_rand(0, 1) === 0
                ? $this->ring(100.02, 10.02, 0.005, mt_rand(3, 5))
                : $this->ring(100.0, 10.0, 0.06, mt_rand(3, 5));
        }

        if (mt_rand(0, 4) === 0) {
            // A second polygon that may overlap the first: invalid in a MultiPolygon if it does.
            return ['type' => 'MultiPolygon', 'coordinates' => [$rings, [$this->ring(100.0 + mt_rand(0, 8) / 100, 10.0, 0.03, mt_rand(3, 6))]]];
        }

        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /** @param  array<string, mixed>  $geoJson */
    private function postgisSaysValid(array $geoJson): bool
    {
        return DB::selectOne('SELECT ST_IsValid(ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))) AS v', [json_encode($geoJson)])->v === true;
    }

    public function test_the_api_agrees_with_postgis_on_every_random_shape(): void
    {
        $seed = $this->fuzzSeed();
        mt_srand($seed);

        $user = $this->user('owner@example.com');
        $this->grantRole($user, 'platform_owner');
        $this->login('admin', 'owner@example.com')->assertOk();
        $city = City::factory()->create();

        $outcomes = [201 => 0, 422 => 0];

        for ($case = 0; $case < self::CASES; $case++) {
            $geometry = $this->randomPolygon();
            $where = "seed {$seed} case {$case}: ".json_encode($geometry);
            $expected = $this->postgisSaysValid($geometry) ? 201 : 422;

            $response = $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", [
                'name' => "Fuzz {$case}", 'slug' => "fuzz-{$case}", 'geometry' => $geometry,
            ]);

            $this->assertSame($expected, $response->status(), $where);
            $outcomes[$expected]++;

            if ($expected === 422) {
                $this->assertArrayHasKey('geometry', (array) $response->json('error.fields'), $where);
                // The refusal never echoes the coordinates back.
                preg_match('/1\d\d\.\d{3,}/', (string) json_encode($geometry), $first);
                $this->assertStringNotContainsString($first[0], (string) $response->getContent(), $where);

                continue;
            }

            $row = DB::selectOne(
                'SELECT ST_IsValid(coverage) AS valid, ST_Equals(coverage, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))) AS same FROM delivery_zones WHERE id = ?',
                [json_encode($geometry), $response->json('data.id')],
            );
            $this->assertTrue($row->valid, "{$where}: stored an invalid shape");
            $this->assertTrue($row->same, "{$where}: stored shape differs from the one sent");
        }

        $this->assertGreaterThan(10, $outcomes[201], "seed {$seed}: too few valid shapes generated");
        $this->assertGreaterThan(10, $outcomes[422], "seed {$seed}: too few invalid shapes generated");
        $this->assertSame($outcomes[201], DB::table('delivery_zones')->where('city_id', $city->id)->count());
    }
}
