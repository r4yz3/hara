<?php

namespace App\Domain\Geo;

use InvalidArgumentException;

/**
 * Caps applied to administrator-supplied zone geometry (ADR-002).
 * The values are configuration, not constants; see config/geo.php.
 */
final readonly class GeometryLimits
{
    public function __construct(
        public int $maxVertices,
        public float $maxExtentDegrees,
    ) {
        if ($maxVertices < 4) {
            throw new InvalidArgumentException('maxVertices must allow at least one closed ring (4).');
        }

        if (! is_finite($maxExtentDegrees) || $maxExtentDegrees <= 0.0) {
            throw new InvalidArgumentException('maxExtentDegrees must be a positive number.');
        }
    }
}
