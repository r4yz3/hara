<?php

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\GeometryLimits;
use App\Domain\Geo\InvalidZoneGeometry;
use App\Domain\Geo\ZoneGeometry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structural validation only. Self-intersection and ring nesting are decided
 * by PostGIS (ST_IsValid) and are tested against a real database in Sprint 5.
 *
 * All shapes are synthetic squares near longitude 100, latitude 10.
 */
class ZoneGeometryValidatorTest extends TestCase
{
    /** @return list<list<int|float>> */
    private static function square(float $west = 100.0, float $south = 10.0, float $size = 0.02): array
    {
        return [
            [$west, $south],
            [$west + $size, $south],
            [$west + $size, $south + $size],
            [$west, $south + $size],
            [$west, $south],
        ];
    }

    private function limits(int $maxVertices = 5000, float $maxExtentDegrees = 2.0): GeometryLimits
    {
        return new GeometryLimits($maxVertices, $maxExtentDegrees);
    }

    public function test_accepts_a_multipolygon_and_returns_it_unchanged(): void
    {
        $input = ['type' => 'MultiPolygon', 'coordinates' => [[self::square()]]];

        $geometry = ZoneGeometry::fromGeoJson($input, $this->limits());

        $this->assertSame($input, $geometry->toGeoJson());
        $this->assertSame(5, $geometry->vertexCount);
    }

    public function test_wraps_a_polygon_into_a_multipolygon(): void
    {
        $geometry = ZoneGeometry::fromGeoJson(
            ['type' => 'Polygon', 'coordinates' => [self::square()]],
            $this->limits(),
        );

        $this->assertSame(
            ['type' => 'MultiPolygon', 'coordinates' => [[self::square()]]],
            $geometry->toGeoJson(),
        );
    }

    public function test_drops_altitude_and_casts_integers(): void
    {
        $ring = [[100, 10, 5], [101, 10, 5], [101, 11, 5], [100, 11, 5], [100, 10, 5]];

        $geometry = ZoneGeometry::fromGeoJson(['type' => 'Polygon', 'coordinates' => [$ring]], $this->limits());

        $this->assertSame(
            [[[[100.0, 10.0], [101.0, 10.0], [101.0, 11.0], [100.0, 11.0], [100.0, 10.0]]]],
            $geometry->toGeoJson()['coordinates'],
        );
    }

    public function test_keeps_holes_and_multiple_polygons(): void
    {
        $shell = self::square(100.0, 10.0, 0.1);
        $hole = self::square(100.04, 10.04, 0.02);
        $second = self::square(100.5, 10.5, 0.1);

        $geometry = ZoneGeometry::fromGeoJson(
            ['type' => 'MultiPolygon', 'coordinates' => [[$shell, $hole], [$second]]],
            $this->limits(),
        );

        $this->assertCount(2, $geometry->toGeoJson()['coordinates']);
        $this->assertCount(2, $geometry->toGeoJson()['coordinates'][0]);
        $this->assertSame(15, $geometry->vertexCount);
    }

    public function test_ignores_unknown_members_such_as_bbox(): void
    {
        $geometry = ZoneGeometry::fromGeoJson(
            ['type' => 'Polygon', 'coordinates' => [self::square()], 'bbox' => [0, 0, 1, 1], 'crs' => 'anything'],
            $this->limits(),
        );

        $this->assertSame(['type', 'coordinates'], array_keys($geometry->toGeoJson()));
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidShapes')]
    public function test_rejects_invalid_input_with_a_reason(array $input, string $reason): void
    {
        try {
            ZoneGeometry::fromGeoJson($input, $this->limits());
            $this->fail("Expected rejection with reason {$reason}.");
        } catch (InvalidZoneGeometry $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function invalidShapes(): array
    {
        $square = self::square();
        $open = array_slice($square, 0, 4);
        $triangleUnclosed = [[100.0, 10.0], [100.02, 10.0], [100.02, 10.02]];
        $collapsed = [[100.0, 10.0], [100.02, 10.0], [100.0, 10.0], [100.0, 10.0]];
        $swapped = array_map(fn (array $p): array => [$p[1], $p[0]], $square);

        return [
            'no type' => [['coordinates' => [[$square]]], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'point' => [['type' => 'Point', 'coordinates' => [100.0, 10.0]], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'feature wrapper' => [['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$square]]], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'geometry collection' => [['type' => 'GeometryCollection', 'geometries' => []], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'type is not a string' => [['type' => ['Polygon'], 'coordinates' => [$square]], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'lower-case type' => [['type' => 'polygon', 'coordinates' => [$square]], InvalidZoneGeometry::UNSUPPORTED_TYPE],
            'missing coordinates' => [['type' => 'Polygon'], InvalidZoneGeometry::MALFORMED],
            'coordinates not a list' => [['type' => 'Polygon', 'coordinates' => 'x'], InvalidZoneGeometry::MALFORMED],
            'keyed coordinates' => [['type' => 'Polygon', 'coordinates' => ['a' => $square]], InvalidZoneGeometry::MALFORMED],
            'empty multipolygon' => [['type' => 'MultiPolygon', 'coordinates' => []], InvalidZoneGeometry::EMPTY],
            'polygon without rings' => [['type' => 'Polygon', 'coordinates' => []], InvalidZoneGeometry::EMPTY],
            'multipolygon with empty polygon' => [['type' => 'MultiPolygon', 'coordinates' => [[]]], InvalidZoneGeometry::EMPTY],
            'polygon depth given as multipolygon' => [['type' => 'MultiPolygon', 'coordinates' => [$square]], InvalidZoneGeometry::RING_TOO_SHORT],
            'multipolygon depth given as polygon' => [['type' => 'Polygon', 'coordinates' => [[$square]]], InvalidZoneGeometry::RING_TOO_SHORT],
            'ring is not a list' => [['type' => 'Polygon', 'coordinates' => ['ring']], InvalidZoneGeometry::MALFORMED],
            'ring too short' => [['type' => 'Polygon', 'coordinates' => [$triangleUnclosed]], InvalidZoneGeometry::RING_TOO_SHORT],
            'ring not closed' => [['type' => 'Polygon', 'coordinates' => [[...$open, [100.01, 10.01]]]], InvalidZoneGeometry::RING_NOT_CLOSED],
            'ring collapsed to a line' => [['type' => 'Polygon', 'coordinates' => [$collapsed]], InvalidZoneGeometry::RING_DEGENERATE],
            'string coordinates' => [['type' => 'Polygon', 'coordinates' => [[['100', '10'], ['101', '10'], ['101', '11'], ['100', '10']]]], InvalidZoneGeometry::INVALID_POSITION],
            'latitude out of range' => [['type' => 'Polygon', 'coordinates' => [[[100.0, 91.0], [100.02, 91.0], [100.02, 91.02], [100.0, 91.0]]]], InvalidZoneGeometry::INVALID_POSITION],
            'axes swapped' => [['type' => 'Polygon', 'coordinates' => [$swapped]], InvalidZoneGeometry::INVALID_POSITION],
        ];
    }

    public function test_rejects_more_vertices_than_the_cap(): void
    {
        $this->expectRejection(
            ['type' => 'MultiPolygon', 'coordinates' => [[self::square()], [self::square(100.5)]]],
            $this->limits(maxVertices: 9),
            InvalidZoneGeometry::TOO_MANY_VERTICES,
        );
    }

    public function test_accepts_exactly_the_vertex_cap(): void
    {
        $geometry = ZoneGeometry::fromGeoJson(
            ['type' => 'MultiPolygon', 'coordinates' => [[self::square()], [self::square(100.5)]]],
            $this->limits(maxVertices: 10),
        );

        $this->assertSame(10, $geometry->vertexCount);
    }

    public function test_rejects_an_extent_wider_than_the_cap(): void
    {
        $this->expectRejection(
            ['type' => 'Polygon', 'coordinates' => [self::square(100.0, 10.0, 2.5)]],
            $this->limits(maxExtentDegrees: 2.0),
            InvalidZoneGeometry::EXTENT_TOO_LARGE,
        );
    }

    public function test_extent_is_measured_across_all_polygons(): void
    {
        $this->expectRejection(
            ['type' => 'MultiPolygon', 'coordinates' => [[self::square(100.0)], [self::square(103.0)]]],
            $this->limits(maxExtentDegrees: 2.0),
            InvalidZoneGeometry::EXTENT_TOO_LARGE,
        );
    }

    public function test_accepts_an_extent_within_the_cap(): void
    {
        $geometry = ZoneGeometry::fromGeoJson(
            ['type' => 'Polygon', 'coordinates' => [self::square(100.0, 10.0, 1.5)]],
            $this->limits(maxExtentDegrees: 2.0),
        );

        $this->assertSame(5, $geometry->vertexCount);
    }

    public function test_limits_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GeometryLimits(0, 2.0);
    }

    /** @param array<mixed> $input */
    private function expectRejection(array $input, GeometryLimits $limits, string $reason): void
    {
        try {
            ZoneGeometry::fromGeoJson($input, $limits);
            $this->fail("Expected rejection with reason {$reason}.");
        } catch (InvalidZoneGeometry $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
