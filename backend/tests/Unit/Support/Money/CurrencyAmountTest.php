<?php

namespace Tests\Unit\Support\Money;

use App\Support\Money\CurrencyMismatch;
use App\Support\Money\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyAmountTest extends TestCase
{
    public function test_holds_minor_units_and_currency(): void
    {
        $money = Money::of(54000, 'PHP');

        $this->assertSame(54000, $money->amount);
        $this->assertSame('PHP', $money->currency);
    }

    public function test_currency_is_normalized_to_upper_case(): void
    {
        $this->assertSame('PHP', Money::of(1, 'php')->currency);
    }

    #[DataProvider('invalidCurrencies')]
    public function test_rejects_invalid_currency_codes(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1, $currency);
    }

    /** @return array<string, array{string}> */
    public static function invalidCurrencies(): array
    {
        return [
            'empty' => [''],
            'too short' => ['PH'],
            'too long' => ['PHPP'],
            'symbol' => ['₱'],
            'digits' => ['123'],
            'padded' => [' PHP'],
        ];
    }

    public function test_adds_and_subtracts_exactly(): void
    {
        $items = Money::of(50000, 'PHP');
        $delivery = Money::of(6000, 'PHP');
        $service = Money::of(1000, 'PHP');
        $discount = Money::of(3000, 'PHP');

        $total = $items->add($delivery)->add($service)->subtract($discount);

        $this->assertTrue($total->equals(Money::of(54000, 'PHP')));
    }

    public function test_amounts_that_break_floats_stay_exact(): void
    {
        // 0.10 + 0.20 is not 0.30 in binary floating point.
        $sum = Money::of(10, 'PHP')->add(Money::of(20, 'PHP'));

        $this->assertSame(30, $sum->amount);
    }

    public function test_is_immutable(): void
    {
        $original = Money::of(100, 'PHP');
        $original->add(Money::of(50, 'PHP'));

        $this->assertSame(100, $original->amount);
    }

    public function test_signed_amounts_are_allowed(): void
    {
        $refund = Money::of(500, 'PHP')->negate();

        $this->assertSame(-500, $refund->amount);
        $this->assertTrue($refund->isNegative());
        $this->assertFalse($refund->isZero());
        $this->assertTrue(Money::zero('PHP')->isZero());
        $this->assertTrue(Money::of(1, 'PHP')->isPositive());
    }

    public function test_multiplies_by_whole_quantity(): void
    {
        $this->assertSame(37500, Money::of(12500, 'PHP')->multiply(3)->amount);
        $this->assertSame(0, Money::of(12500, 'PHP')->multiply(0)->amount);
    }

    public function test_refuses_to_combine_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'PHP')->add(Money::of(100, 'USD'));
    }

    public function test_refuses_to_compare_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'PHP')->compare(Money::of(100, 'USD'));
    }

    public function test_equals_is_false_across_currencies(): void
    {
        $this->assertFalse(Money::of(100, 'PHP')->equals(Money::of(100, 'USD')));
    }

    public function test_compares_within_a_currency(): void
    {
        $small = Money::of(100, 'PHP');
        $large = Money::of(200, 'PHP');

        $this->assertSame(-1, $small->compare($large));
        $this->assertSame(1, $large->compare($small));
        $this->assertSame(0, $small->compare(Money::of(100, 'PHP')));
    }

    public function test_addition_overflow_throws_instead_of_becoming_a_float(): void
    {
        $this->expectException(OverflowException::class);

        Money::of(PHP_INT_MAX, 'PHP')->add(Money::of(1, 'PHP'));
    }

    public function test_subtraction_overflow_throws(): void
    {
        $this->expectException(OverflowException::class);

        Money::of(PHP_INT_MIN, 'PHP')->subtract(Money::of(1, 'PHP'));
    }

    public function test_multiplication_overflow_throws(): void
    {
        $this->expectException(OverflowException::class);

        Money::of(PHP_INT_MAX, 'PHP')->multiply(2);
    }

    public function test_negating_the_minimum_integer_throws(): void
    {
        $this->expectException(OverflowException::class);

        Money::of(PHP_INT_MIN, 'PHP')->negate();
    }

    #[DataProvider('decimalStrings')]
    public function test_parses_decimal_strings_without_floats(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::fromDecimalString($input, 'PHP')->amount);
    }

    /** @return array<string, array{string, int}> */
    public static function decimalStrings(): array
    {
        return [
            'whole' => ['500', 50000],
            'two decimals' => ['500.00', 50000],
            'one decimal' => ['0.1', 10],
            'float-hostile' => ['0.29', 29],
            'another float-hostile' => ['1.15', 115],
            'negative' => ['-12.34', -1234],
            'zero' => ['0', 0],
            'large' => ['90000000000000.07', 9000000000000007],
        ];
    }

    #[DataProvider('invalidDecimalStrings')]
    public function test_rejects_malformed_decimal_strings(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString($input, 'PHP');
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimalStrings(): array
    {
        return [
            'empty' => [''],
            'three decimals' => ['1.234'],
            'thousands separator' => ['1,000.00'],
            'currency symbol' => ['₱500'],
            'exponent' => ['1e3'],
            'trailing dot' => ['5.'],
            'leading dot' => ['.5'],
            'spaces' => [' 5 '],
            'plus sign' => ['+5'],
            'words' => ['five'],
            'out of range' => ['99999999999999999999'],
        ];
    }

    public function test_serializes_to_amount_and_currency_only(): void
    {
        $this->assertSame(
            '{"amount":54000,"currency":"PHP"}',
            json_encode(Money::of(54000, 'PHP')),
        );
    }
}
