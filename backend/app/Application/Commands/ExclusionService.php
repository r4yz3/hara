<?php

namespace App\Application\Commands;

use App\Domain\Geo\ZoneGeometry;
use App\Support\Errors\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes coverage exclusions: areas inside a zone that are not
 * served, optionally only during a time window (backlog C-07). An exclusion
 * is never edited; it is removed and a new one added, so the audit log shows
 * each state. Each change and its audit row share one transaction.
 * Authorization is the caller's job.
 */
class ExclusionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string|null  $from  UTC timestamp, or null for "already in force"
     * @param  string|null  $until  UTC timestamp, or null for "until removed"
     */
    public function create(string $actorId, string $zoneId, string $reason, ZoneGeometry $geometry, ?string $from, ?string $until): string
    {
        return DB::transaction(function () use ($actorId, $zoneId, $reason, $geometry, $from, $until): string {
            $zone = DB::table('delivery_zones')->where('id', $zoneId)->lockForUpdate()->first(['city_id', 'status']);
            if ($zone === null) {
                throw ApiError::notFound();
            }
            if ($zone->status === 'archived') {
                throw new ApiError(409, 'CONFLICT', 'An archived zone cannot take exclusions.');
            }

            $geoJson = (string) json_encode($geometry->toGeoJson(), JSON_THROW_ON_ERROR);
            $check = DB::selectOne(
                'SELECT ST_IsValid(g.geom) AS valid, ST_Intersects(g.geom, z.coverage) AS touches
                 FROM delivery_zones z, (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) AS geom) g WHERE z.id = ?',
                [$geoJson, $zoneId],
            );
            if (($check->valid ?? false) !== true) {
                throw ValidationException::withMessages(['geometry' => ['The shape is not valid: it crosses itself or has a misplaced hole.']]);
            }
            if (($check->touches ?? false) !== true) {
                throw ValidationException::withMessages(['geometry' => ['The shape lies entirely outside the zone, so it would exclude nothing.']]);
            }

            $id = strtolower((string) Str::ulid());
            DB::insert(
                'INSERT INTO coverage_exclusions (id, delivery_zone_id, reason, geometry, active_from, active_until)
                 VALUES (?, ?, ?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), ?, ?)',
                [$id, $zoneId, $reason, $geoJson, $from, $until],
            );

            $this->audit->record(
                $actorId, 'exclusion.created', 'coverage_exclusion', $id, (string) $zone->city_id,
                after: ['zone_id' => $zoneId, 'reason' => $reason, 'active_from' => $from, 'active_until' => $until],
            );

            return $id;
        });
    }

    public function delete(string $actorId, string $exclusionId): void
    {
        DB::transaction(function () use ($actorId, $exclusionId): void {
            $row = DB::table('coverage_exclusions as e')
                ->join('delivery_zones as z', 'z.id', '=', 'e.delivery_zone_id')
                ->where('e.id', $exclusionId)
                ->lockForUpdate()
                ->first(['e.delivery_zone_id', 'e.reason', 'e.active_from', 'e.active_until', 'z.city_id']);
            if ($row === null) {
                throw ApiError::notFound();
            }

            DB::table('coverage_exclusions')->where('id', $exclusionId)->delete();

            $this->audit->record(
                $actorId, 'exclusion.deleted', 'coverage_exclusion', $exclusionId, (string) $row->city_id,
                before: ['zone_id' => $row->delivery_zone_id, 'reason' => $row->reason, 'active_from' => $row->active_from, 'active_until' => $row->active_until],
            );
        });
    }
}
