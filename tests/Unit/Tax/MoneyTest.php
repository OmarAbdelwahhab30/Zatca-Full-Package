<?php

namespace Zatca\Tests\Unit\Tax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\Money;

/**
 * The exact integer arithmetic every VAT figure goes through.
 */
class MoneyTest extends TestCase
{
    /**
     * @dataProvider scaledValues
     */
    #[DataProvider('scaledValues')]
    public function test_values_are_read_exactly_and_rounded_half_up(int|float|string $value, int $scale, int $expected): void
    {
        $this->assertSame($expected, Money::toScaled($value, $scale));
    }

    public static function scaledValues(): array
    {
        return [
            'integer' => [115, 2, 11500],
            'float' => [115.0, 2, 11500],
            'string' => ['115.00', 2, 11500],
            'binary float noise' => [0.1 + 0.2, 2, 30],
            'half up at the third decimal' => ['2.345', 2, 235],
            'below half' => ['2.344', 2, 234],
            'negative rounds away from zero' => ['-2.345', 2, -235],
            'six decimals kept' => ['0.333333', 6, 333333],
            'seventh decimal rounds' => ['0.3333335', 6, 333334],
            'leading dot' => ['.5', 2, 50],
            'whitespace trimmed' => [' 10.5 ', 2, 1050],
        ];
    }

    /**
     * @dataProvider divisions
     */
    #[DataProvider('divisions')]
    public function test_division_rounds_half_up_away_from_zero(int $numerator, int $denominator, int $expected): void
    {
        $this->assertSame($expected, Money::divide($numerator, $denominator));
    }

    public static function divisions(): array
    {
        return [
            'exact' => [100, 4, 25],
            'below half' => [10, 4, 3],
            'exactly half' => [5, 2, 3],
            'negative exactly half' => [-5, 2, -3],
            'inclusive 100.00 at 15%' => [10000 * 10000, 11500, 8696],
        ];
    }

    public function test_formatting(): void
    {
        $this->assertSame('115.00', Money::format(11500, 2));
        $this->assertSame('0.05', Money::format(5, 2));
        $this->assertSame('-0.05', Money::format(-5, 2));
        $this->assertSame('86.957', Money::trimmed(86957000, 6));
        $this->assertSame('28.983333', Money::trimmed(28983333, 6));
        $this->assertSame('100.00', Money::trimmed(100000000, 6));
        $this->assertSame('0.10', Money::trimmed(100000, 6));
    }

    public function test_sums_have_no_float_drift(): void
    {
        $this->assertSame(0.3, Money::sum([0.1, 0.2]));
        $this->assertSame(1000.0, Money::sum(array_fill(0, 10_000, 0.1)));
    }

    /**
     * @dataProvider invalidValues
     */
    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused(int|float|string $value): void
    {
        $this->expectException(ZatcaException::class);

        Money::toScaled($value, 2);
    }

    public static function invalidValues(): array
    {
        return [
            'text' => ['abc'],
            'empty' => [''],
            'exponent' => ['1e5'],
            'two dots' => ['1.2.3'],
            'infinite' => [INF],
            'not a number' => [NAN],
            'too large' => ['1000000000000000000'],
        ];
    }

    public function test_overflow_is_refused_rather_than_silently_wrong(): void
    {
        $this->expectException(ZatcaException::class);

        Money::multiply(PHP_INT_MAX, 2);
    }
}
