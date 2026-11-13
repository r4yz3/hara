<?php

namespace App\Domain\Geo;

use InvalidArgumentException;

/**
 * A structurally valid delivery-zone shape, normalized to a GeoJSON
 * MultiPolygon with [longitude, latitude] positions (ADR-002).
 *
 * This checks what can be decided without a spatial engine: type, nesting,
 * ring length and closure, coordinate bounds, vertex count and extent.
 * It does NOT prove the shape is valid: self-intersection, hole placement and
 * overlapping polygons are decided by PostGIS (ST_IsValid) when the geometry
 * is stored. Nothing here repairs input; invalid input is rejected.
 */
final readonly class ZoneGeometry
{
    /**
     * @param  list<list<list<array{float, float}>>>  $polygons
     */
    private function __construct(
        private array $polygons,
        public int $vertexCount,
    ) {}

    /**
     * @param  array<mixed>  $geoJson  A GeoJSON Polygon or MultiPolygon geometry object.
     *
     * @throws InvalidZoneGeometry
     */
    public static function fromGeoJson(array $geoJson, GeometryLimits $limits): self
    {
        $type = $geoJson['type'] ?? null;

        if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::UNSUPPORTED_TYPE, 'Geometry type must be Polygon or MultiPolygon.');
        }

        $coordinates = self::requireList($geoJson['coordinates'] ?? null, 'coordinates');
        $rawPolygons = $type === 'Polygon' ? [$coordinates] : $coordinates;

        if ($rawPolygons === []) {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::EMPTY, 'Geometry has no polygons.');
        }

        $polygons = [];
        $vertexCount = 0;
        $west = $south = INF;
        $east = $north = -INF;

        foreach ($rawPolygons as $rawPolygon) {
            $rawRings = self::requireList($rawPolygon, 'polygon');

            if ($rawRings === []) {
                throw InvalidZoneGeometry::because(InvalidZoneGeometry::EMPTY, 'A polygon has no rings.');
            }

            $rings = [];

            foreach ($rawRings as $rawRing) {
                $rawPositions = self::requireList($rawRing, 'ring');
                $vertexCount += count($rawPositions);

                // Checked before parsing positions so an oversized payload is refused cheaply.
                if ($vertexCount > $limits->maxVertices) {
                    throw InvalidZoneGeometry::because(
                        InvalidZoneGeometry::TOO_MANY_VERTICES,
                        "Geometry has more than {$limits->maxVertices} vertices.",
                    );
                }

                $ring = self::parseRing($rawPositions);

                foreach ($ring as [$longitude, $latitude]) {
                    $west = min($west, $longitude);
                    $east = max($east, $longitude);
                    $south = min($south, $latitude);
                    $north = max($north, $latitude);
                }

                $rings[] = $ring;
            }

            $polygons[] = $rings;
        }

        if ($east - $west > $limits->maxExtentDegrees || $north - $south > $limits->maxExtentDegrees) {
            throw InvalidZoneGeometry::because(
                InvalidZoneGeometry::EXTENT_TOO_LARGE,
                "Geometry spans more than {$limits->maxExtentDegrees} degrees.",
            );
        }

        return new self($polygons, $vertexCount);
    }

    /**
     * @return array{type: 'MultiPolygon', coordinates: list<list<list<array{float, float}>>>}
     */
    public function toGeoJson(): array
    {
        return ['type' => 'MultiPolygon', 'coordinates' => $this->polygons];
    }

    /**
     * @param  list<mixed>  $rawPositions
     * @return list<array{float, float}>
     */
    private static function parseRing(array $rawPositions): array
    {
        if (count($rawPositions) < 4) {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::RING_TOO_SHORT, 'A ring needs at least four positions.');
        }

        $ring = [];

        foreach ($rawPositions as $rawPosition) {
            if (! is_array($rawPosition)) {
                throw InvalidZoneGeometry::because(InvalidZoneGeometry::INVALID_POSITION, 'A position must be [longitude, latitude].');
            }

            try {
                $ring[] = Coordinates::fromGeoJsonPosition($rawPosition)->toGeoJsonPosition();
            } catch (InvalidArgumentException $e) {
                throw InvalidZoneGeometry::because(InvalidZoneGeometry::INVALID_POSITION, $e->getMessage());
            }
        }

        if ($ring[0] !== $ring[count($ring) - 1]) {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::RING_NOT_CLOSED, 'A ring must end at its first position.');
        }

        $distinct = [];
        foreach ($ring as [$longitude, $latitude]) {
            $distinct[$longitude.','.$latitude] = true;
        }

        if (count($distinct) < 3) {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::RING_DEGENERATE, 'A ring needs at least three distinct positions.');
        }

        return $ring;
    }

    /**
     * @return list<mixed>
     */
    private static function requireList(mixed $value, string $what): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidZoneGeometry::because(InvalidZoneGeometry::MALFORMED, "The {$what} must be an array.");
        }

        return $value;
    }
}
