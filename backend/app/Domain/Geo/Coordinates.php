<?php

namespace App\Domain\Geo;

use InvalidArgumentException;

/**
 * A validated WGS 84 position (SRID 4326), per ADR-002.
 *
 * Construction is always by named axis or by an explicit GeoJSON position, so
 * the order can never be ambiguous: GeoJSON and PostGIS take longitude first,
 * everything named takes latitude and longitude by name.
 */
final readonly class Coordinates
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public static function fromLatitudeLongitude(float $latitude, float $longitude): self
    {
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }

        return new self($latitude, $longitude);
    }

    /**
     * @param  array<mixed>  $position  [longitude, latitude] or [longitude, latitude, altitude]; altitude is dropped.
     */
    public static function fromGeoJsonPosition(array $position): self
    {
        if (! array_is_list($position) || count($position) < 2 || count($position) > 3) {
            throw new InvalidArgumentException('A position must be [longitude, latitude] with an optional altitude.');
        }

        foreach ($position as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Position values must be numbers.');
            }
        }

        /** @var array{int|float, int|float} $position */
        return self::fromLatitudeLongitude((float) $position[1], (float) $position[0]);
    }

    /** @return array{float, float} [longitude, latitude] */
    public function toGeoJsonPosition(): array
    {
        return [$this->longitude, $this->latitude];
    }
}
