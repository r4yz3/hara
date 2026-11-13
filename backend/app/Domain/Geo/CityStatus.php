<?php

namespace App\Domain\Geo;

/**
 * City lifecycle. Values are the proposal in ADR-000 item 8, still awaiting
 * formal approval. A status changes only along these edges, never freely.
 */
enum CityStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Paused],
            self::Paused => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canBecome(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
