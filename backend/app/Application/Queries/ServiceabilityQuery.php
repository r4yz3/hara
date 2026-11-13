<?php

namespace App\Application\Queries;

use App\Domain\Geo\Coordinates;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a point can be served (spec 5.4, ADR-002).
 *
 * Polygon containment only: no route check and no distance (ADR-008).
 * ST_Covers makes a zone's boundary inside the zone and an exclusion's
 * boundary inside the exclusion. Overlaps resolve by priority, then id.
 */
class ServiceabilityQuery
{
    /**
     * @return array{serviceable: bool, reason: 'NO_ACTIVE_ZONE'|'OUTSIDE_COVERAGE'|null, city: array{id: string, name: string}|null, zone: array{id: string, name: string}|null}
     */
    public function at(Coordinates $point): array
    {
        // ST_MakePoint takes x, y: longitude first.
        $match = DB::selectOne(<<<'SQL'
            WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:longitude, :latitude), 4326) AS geom)
            SELECT z.id AS zone_id, z.name AS zone_name, c.id AS city_id, c.name AS city_name
            FROM delivery_zones z
            JOIN cities c ON c.id = z.city_id
            CROSS JOIN p
            WHERE z.status = 'active'
              AND c.status = 'active'
              AND ST_Covers(z.coverage, p.geom)
              AND NOT EXISTS (
                  SELECT 1 FROM coverage_exclusions e
                  WHERE e.delivery_zone_id = z.id
                    AND (e.active_from IS NULL OR e.active_from <= NOW())
                    AND (e.active_until IS NULL OR e.active_until > NOW())
                    AND ST_Covers(e.geometry, p.geom)
              )
            ORDER BY z.priority DESC, z.id ASC
            LIMIT 1
        SQL, ['longitude' => $point->longitude, 'latitude' => $point->latitude]);

        if ($match !== null) {
            return [
                'serviceable' => true,
                'reason' => null,
                'city' => ['id' => $match->city_id, 'name' => $match->city_name],
                'zone' => ['id' => $match->zone_id, 'name' => $match->zone_name],
            ];
        }

        $anyActive = DB::table('delivery_zones')
            ->join('cities', 'cities.id', '=', 'delivery_zones.city_id')
            ->where('delivery_zones.status', 'active')
            ->where('cities.status', 'active')
            ->exists();

        return [
            'serviceable' => false,
            'reason' => $anyActive ? 'OUTSIDE_COVERAGE' : 'NO_ACTIVE_ZONE',
            'city' => null,
            'zone' => null,
        ];
    }
}
