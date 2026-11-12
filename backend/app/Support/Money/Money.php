<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/**
 * An amount in a currency's minor unit (centavos for PHP), held as a signed
 * integer. There is no float anywhere in this class, and arithmetic that
 * would exceed the integer range throws instead of silently becoming a float.
 *
 * Percentages, tax and rounding rules are deliberately absent: they are
 * business decisions that arrive with pricing in a later phase.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function of(int $amount, string $currency): self
    {
        $normalized = strtoupper($currency);

        if (preg_match('/\A[A-Z]{3}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO 4217 code.');
        }

        return new self($amount, $normalized);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parses "500", "500.5" or "-12.34" for a two-decimal currency.
     * Anything else (separators, symbols, exponents, a third decimal) is rejected.
     */
    public static function fromDecimalString(string $value, string $currency): self
    {
        if (preg_match('/\A(-?)(\d{1,16})(?:\.(\d{1,2}))?\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Amount must be a plain decimal with at most two decimal places.');
        }

        $minor = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return self::of($parts[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if (($other->amount > 0 && $this->amount > PHP_INT_MAX - $other->amount)
            || ($other->amount < 0 && $this->amount < PHP_INT_MIN - $other->amount)) {
            throw new OverflowException('Money addition exceeds the integer range.');
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if (($other->amount < 0 && $this->amount > PHP_INT_MAX + $other->amount)
            || ($other->amount > 0 && $this->amount < PHP_INT_MIN + $other->amount)) {
            throw new OverflowException('Money subtraction exceeds the integer range.');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        // Checked before multiplying: PHP would promote an overflowing
        // integer product to a float instead of failing.
        if ($this->amount !== 0 && $quantity !== 0) {
            $overflows = ($this->amount === PHP_INT_MIN || $quantity === PHP_INT_MIN)
                ? ($this->amount !== 1 && $quantity !== 1)
                : abs($this->amount) > intdiv(PHP_INT_MAX, abs($quantity));

            if ($overflows) {
                throw new OverflowException('Money multiplication exceeds the integer range.');
            }
        }

        return new self($this->amount * $quantity, $this->currency);
    }

    public function negate(): self
    {
        if ($this->amount === PHP_INT_MIN) {
            throw new OverflowException('Money negation exceeds the integer range.');
        }

        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /** @return -1|0|1 */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    /** @return array{amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
