<?php

namespace App\Http\Resources;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff view of a delivery zone. Lists leave the geometry out; the single
 * record returns it as a GeoJSON MultiPolygon in [longitude, latitude] order.
 */
final class ZoneResource
{
    public static function query(bool $withGeometry): Builder
    {
        $columns = ['id', 'city_id', 'name', 'slug', 'status', 'priority', 'version', 'created_at', 'updated_at'];
        $query = DB::table('delivery_zones')->select($columns);

        if ($withGeometry) {
            $query->selectRaw('ST_AsGeoJSON(coverage, 12) AS geometry');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public static function admin(object $row): array
    {
        $out = [
            'id' => $row->id,
            'city_id' => $row->city_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'status' => $row->status,
            'priority' => (int) $row->priority,
            'version' => (int) $row->version,
        ];

        if (property_exists($row, 'geometry')) {
            $out['geometry'] = json_decode((string) $row->geometry, true, 512, JSON_THROW_ON_ERROR);
        }

        return $out + [
            'created_at' => Carbon::parse((string) $row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => Carbon::parse((string) $row->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
