<?php

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\Coordinates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are synthetic. The asymmetric pair (10, 100) is used because it is
 * valid one way round and invalid the other, which catches axis swaps.
 */
class CoordinateBoundsTest extends TestCase
{
    #[DataProvider('validPairs')]
    public function test_accepts_values_inside_and_on_the_bounds(float $latitude, float $longitude): void
    {
        $point = Coordinates::fromLatitudeLongitude($latitude, $longitude);

        $this->assertSame($latitude, $point->latitude);
        $this->assertSame($longitude, $point->longitude);
    }

    /** @return array<string, array{float, float}> */
    public static function validPairs(): array
    {
        return [
            'origin' => [0.0, 0.0],
            'asymmetric' => [10.0, 100.0],
            'north pole, date line' => [90.0, 180.0],
            'south pole, antimeridian' => [-90.0, -180.0],
            'fractional' => [-12.345678, 98.765432],
        ];
    }

    #[DataProvider('invalidPairs')]
    public function test_rejects_out_of_range_and_non_finite_values(float $latitude, float $longitude): void
    {
        $this->expectException(InvalidArgumentException::class);

        Coordinates::fromLatitudeLongitude($latitude, $longitude);
    }

    /** @return array<string, array{float, float}> */
    public static function invalidPairs(): array
    {
        return [
            'latitude just above' => [90.000001, 0.0],
            'latitude just below' => [-90.000001, 0.0],
            'longitude just above' => [0.0, 180.000001],
            'longitude just below' => [0.0, -180.000001],
            'swapped asymmetric pair' => [100.0, 10.0],
            'latitude NaN' => [NAN, 0.0],
            'longitude NaN' => [0.0, NAN],
            'latitude infinite' => [INF, 0.0],
            'longitude negative infinite' => [0.0, -INF],
        ];
    }

    public function test_geojson_position_is_longitude_then_latitude(): void
    {
        $point = Coordinates::fromLatitudeLongitude(10.0, 100.0);

        $this->assertSame([100.0, 10.0], $point->toGeoJsonPosition());
    }

    public function test_reads_a_geojson_position_as_longitude_then_latitude(): void
    {
        $point = Coordinates::fromGeoJsonPosition([100.0, 10.0]);

        $this->assertSame(10.0, $point->latitude);
        $this->assertSame(100.0, $point->longitude);
    }

    public function test_geojson_position_ignores_altitude(): void
    {
        $point = Coordinates::fromGeoJsonPosition([100.0, 10.0, 250.0]);

        $this->assertSame([100.0, 10.0], $point->toGeoJsonPosition());
    }

    public function test_integer_json_numbers_are_accepted_in_positions(): void
    {
        $point = Coordinates::fromGeoJsonPosition([100, 10]);

        $this->assertSame(10.0, $point->latitude);
        $this->assertSame(100.0, $point->longitude);
    }

    /** @param array<mixed> $position */
    #[DataProvider('malformedPositions')]
    public function test_rejects_malformed_geojson_positions(array $position): void
    {
        $this->expectException(InvalidArgumentException::class);

        Coordinates::fromGeoJsonPosition($position);
    }

    /** @return array<string, array{array<mixed>}> */
    public static function malformedPositions(): array
    {
        return [
            'empty' => [[]],
            'one value' => [[100.0]],
            'four values' => [[100.0, 10.0, 0.0, 0.0]],
            'numeric strings' => [['100.0', '10.0']],
            'null' => [[null, 10.0]],
            'boolean' => [[true, 10.0]],
            'nested' => [[[100.0, 10.0]]],
            'keyed' => [['lng' => 100.0, 'lat' => 10.0]],
            'swapped asymmetric pair' => [[10.0, 100.0]],
        ];
    }
}
