<?php

namespace App\Application\Commands;

use App\Domain\Geo\CityStatus;
use App\Domain\Geo\ZoneGeometry;
use App\Support\Errors\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits delivery zones (backlog C-04). Every write carries the
 * zone's version: an edit made against an older version is refused with 409
 * instead of silently overwriting someone else's change. Each change and its
 * audit row share one transaction.
 * Authorization is the caller's job.
 */
class ZoneService
{
    /** Audited fields. Geometry is left out so the audit log holds no shapes. */
    private const AUDITED = ['name', 'slug', 'status', 'priority', 'version'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, slug: string, priority: int}  $fields
     */
    public function create(string $actorId, string $cityId, array $fields, ZoneGeometry $geometry): string
    {
        return DB::transaction(function () use ($actorId, $cityId, $fields, $geometry): string {
            $cityStatus = DB::table('cities')->where('id', $cityId)->lockForUpdate()->value('status');
            if ($cityStatus === CityStatus::Archived->value) {
                throw new ApiError(409, 'CONFLICT', 'An archived city cannot take new zones.');
            }

            $geoJson = $this->assertValidInPostgis($geometry);
            $id = strtolower((string) Str::ulid());

            DB::insert(
                'INSERT INTO delivery_zones (id, city_id, name, slug, priority, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))',
                [$id, $cityId, $fields['name'], $fields['slug'], $fields['priority'], $geoJson],
            );

            $this->audit->record($actorId, 'zone.created', 'delivery_zone', $id, $cityId, after: $this->audited($id));

            return $id;
        });
    }

    /**
     * @param  array{name?: string, priority?: int}  $fields
     */
    public function update(string $actorId, string $zoneId, int $expectedVersion, array $fields, ?ZoneGeometry $geometry): void
    {
        DB::transaction(function () use ($actorId, $zoneId, $expectedVersion, $fields, $geometry): void {
            $row = DB::table('delivery_zones')->where('id', $zoneId)->lockForUpdate()->first(['city_id', 'status', 'version']);
            if ($row === null) {
                throw ApiError::notFound();
            }
            if ($row->status === 'archived') {
                throw new ApiError(409, 'CONFLICT', 'An archived zone cannot be changed.');
            }
            if ((int) $row->version !== $expectedVersion) {
                throw new ApiError(409, 'CONFLICT', 'The zone was changed by someone else. Reload it and try again.');
            }

            $before = $this->audited($zoneId);
            $sets = [];
            $bindings = [];

            foreach ($fields as $column => $value) {
                $sets[] = "{$column} = ?";
                $bindings[] = $value;
            }
            if ($geometry !== null) {
                $sets[] = 'coverage = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))';
                $bindings[] = $this->assertValidInPostgis($geometry);
            }
            if ($sets === []) {
                return;
            }

            $sets[] = 'version = version + 1';
            $sets[] = 'updated_at = NOW()';
            // The version condition repeats the check above at the database,
            // so the rule holds even if the row lock were ever removed.
            $updated = DB::update(
                'UPDATE delivery_zones SET '.implode(', ', $sets).' WHERE id = ? AND version = ?',
                [...$bindings, $zoneId, $expectedVersion],
            );
            if ($updated !== 1) {
                throw new ApiError(409, 'CONFLICT', 'The zone was changed by someone else. Reload it and try again.');
            }

            $this->audit->record($actorId, 'zone.updated', 'delivery_zone', $zoneId, (string) $row->city_id, before: $before, after: $this->audited($zoneId));
        });
    }

    /**
     * Moves a zone along the same edges as a city (ADR-000 items 8 and 9).
     * Activation also needs the city to be active and the stored shape to be
     * valid. The reason, when given, is kept in the audit row.
     */
    public function changeStatus(string $actorId, string $zoneId, int $expectedVersion, CityStatus $target, ?string $reason): void
    {
        DB::transaction(function () use ($actorId, $zoneId, $expectedVersion, $target, $reason): void {
            $row = DB::table('delivery_zones')->where('id', $zoneId)->lockForUpdate()->first(['city_id', 'status', 'version']);
            if ($row === null) {
                throw ApiError::notFound();
            }
            if ((int) $row->version !== $expectedVersion) {
                throw new ApiError(409, 'CONFLICT', 'The zone was changed by someone else. Reload it and try again.');
            }

            $current = CityStatus::from((string) $row->status);
            if (! $current->canBecome($target)) {
                throw new ApiError(409, 'CONFLICT', "A {$current->value} zone cannot become {$target->value}.");
            }

            if ($target === CityStatus::Active) {
                // Shared lock: the city cannot be paused or archived mid-activation.
                $cityStatus = DB::table('cities')->where('id', $row->city_id)->sharedLock()->value('status');
                if ($cityStatus !== CityStatus::Active->value) {
                    throw new ApiError(409, 'CONFLICT', 'A zone can be activated only in an active city.');
                }

                $valid = DB::selectOne('SELECT ST_IsValid(coverage) AND NOT ST_IsEmpty(coverage) AS valid FROM delivery_zones WHERE id = ?', [$zoneId])->valid ?? false;
                if ($valid !== true) {
                    Log::notice('zone.validation_failed', ['zone_id' => $zoneId, 'reason' => 'invalid_on_activation']);
                    throw new ApiError(409, 'CONFLICT', 'The zone shape is not valid and cannot be activated.');
                }
            }

            $updated = DB::update(
                'UPDATE delivery_zones SET status = ?, version = version + 1, updated_at = NOW() WHERE id = ? AND version = ?',
                [$target->value, $zoneId, $expectedVersion],
            );
            if ($updated !== 1) {
                throw new ApiError(409, 'CONFLICT', 'The zone was changed by someone else. Reload it and try again.');
            }

            $after = ['status' => $target->value] + ($reason === null ? [] : ['reason' => $reason]);
            $this->audit->record($actorId, 'zone.status_changed', 'delivery_zone', $zoneId, (string) $row->city_id, before: ['status' => $current->value], after: $after);

            if ($target === CityStatus::Active) {
                $cityId = (string) $row->city_id;
                DB::afterCommit(fn () => Log::info('zone.activated', ['zone_id' => $zoneId, 'city_id' => $cityId, 'actor_user_id' => $actorId]));
            }
        });
    }

    /**
     * The structural validator cannot see self-intersections or misplaced
     * holes; PostGIS decides those. The reason text is not returned because
     * it echoes coordinates.
     */
    private function assertValidInPostgis(ZoneGeometry $geometry): string
    {
        $geoJson = (string) json_encode($geometry->toGeoJson(), JSON_THROW_ON_ERROR);
        $valid = DB::selectOne('SELECT ST_IsValid(ST_GeomFromGeoJSON(?)) AS valid', [$geoJson])->valid ?? false;

        if ($valid !== true) {
            Log::notice('zone.validation_failed', ['reason' => 'invalid_in_postgis']);
            throw ValidationException::withMessages(['geometry' => ['The shape is not valid: it crosses itself or has a misplaced hole.']]);
        }

        return $geoJson;
    }

    /** @return array<string, mixed> */
    private function audited(string $zoneId): array
    {
        return (array) DB::table('delivery_zones')->where('id', $zoneId)->first(self::AUDITED);
    }
}
