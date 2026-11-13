<?php

namespace App\Domain\Geo;

use DomainException;

/**
 * Carries a stable machine-readable reason so the API can map it to a field
 * message and tests can assert on it without matching prose.
 */
final class InvalidZoneGeometry extends DomainException
{
    public const UNSUPPORTED_TYPE = 'unsupported_type';

    public const MALFORMED = 'malformed';

    public const EMPTY = 'empty';

    public const RING_TOO_SHORT = 'ring_too_short';

    public const RING_NOT_CLOSED = 'ring_not_closed';

    public const RING_DEGENERATE = 'ring_degenerate';

    public const INVALID_POSITION = 'invalid_position';

    public const TOO_MANY_VERTICES = 'too_many_vertices';

    public const EXTENT_TOO_LARGE = 'extent_too_large';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
