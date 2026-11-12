<?php

namespace App\Support\Money;

use LogicException;

final class CurrencyMismatch extends LogicException
{
    public static function between(string $left, string $right): self
    {
        return new self("Cannot combine or compare {$left} with {$right}.");
    }
}
