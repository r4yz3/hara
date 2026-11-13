<?php

namespace App\Domain\Identity;

use InvalidArgumentException;

/**
 * Where a role grant applies, or what a request is aimed at (ADR-005).
 */
final readonly class Scope
{
    public const TYPES = ['global', 'city', 'merchant', 'branch'];

    private function __construct(
        public string $type,
        public ?string $id,
    ) {}

    public static function global(): self
    {
        return new self('global', null);
    }

    public static function city(string $id): self
    {
        return self::of('city', $id);
    }

    public static function merchant(string $id): self
    {
        return self::of('merchant', $id);
    }

    public static function branch(string $id): self
    {
        return self::of('branch', $id);
    }

    public static function of(string $type, ?string $id): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown scope type [{$type}].");
        }

        if (($type === 'global') !== ($id === null)) {
            throw new InvalidArgumentException('A global scope has no id; every other scope needs one.');
        }

        if ($id !== null && preg_match('/\A[0-9a-z]{26}\z/', $id) !== 1) {
            throw new InvalidArgumentException('A scope id must be a lower-case ULID.');
        }

        return new self($type, $id);
    }

    public function isGlobal(): bool
    {
        return $this->type === 'global';
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
