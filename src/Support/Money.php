<?php

namespace Zatca\Support;

use Zatca\Exceptions\ZatcaException;

/**
 * Exact decimal arithmetic on integers, for every VAT figure the package calculates.
 *
 * Amounts are held as integers at a fixed scale: halalas (scale 2) for money, and
 * millionths (scale 6) for supplied unit prices. Nothing is ever computed in float, so
 * 0.1 + 0.2 is 0.3 and a total is always exactly the sum of its lines. Floats only
 * appear at the edges: when a value is read in, and when a result is handed to the
 * float-typed DTOs (always from an exact decimal string, so the float is the closest
 * one to a value that has at most six decimals).
 *
 * Rounding is half-up, away from zero (2.345 → 2.35, -2.345 → -2.35) — the "commercial"
 * rounding ZATCA's own examples use.
 */
final class Money
{
    /** Scale of money amounts: halalas. */
    public const AMOUNT_SCALE = 2;

    /** Scale of supplied prices and of the net unit price written to the XML. */
    public const PRICE_SCALE = 6;

    /** Keeps every intermediate product below PHP_INT_MAX (9.2e18). */
    private const MAX_DIGITS = 17;

    /**
     * A decimal value as an integer at $scale, rounded half-up past that scale.
     *
     * @throws ZatcaException when the value is not a plain finite decimal, or is too large.
     */
    public static function toScaled(int|float|string $value, int $scale, string $what = 'amount'): int
    {
        $string = self::decimalString($value, $what);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $string, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new ZatcaException("[ZATCA] The {$what} [{$string}] is not a decimal number.");
        }

        $negative = $m[1] === '-';
        $integer = ltrim($m[2], '0');
        $fraction = $m[3] ?? '';

        if (strlen($integer) + $scale > self::MAX_DIGITS) {
            throw new ZatcaException("[ZATCA] The {$what} [{$string}] is too large.");
        }

        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $result = (int) $integer * (10 ** $scale) + (int) $kept;

        if (isset($fraction[$scale]) && (int) $fraction[$scale] >= 5) {
            $result++;
        }

        return $negative ? -$result : $result;
    }

    /** Amount in halalas. */
    public static function halalas(int|float|string $amount, string $what = 'amount'): int
    {
        return self::toScaled($amount, self::AMOUNT_SCALE, $what);
    }

    /** Integer division rounded half-up, away from zero. */
    public static function divide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new ZatcaException('[ZATCA] Internal error: division by a non-positive number.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator - $quotient * $denominator;

        if (2 * abs($remainder) >= $denominator) {
            $quotient += $numerator >= 0 ? 1 : -1;
        }

        return $quotient;
    }

    /** @throws ZatcaException when the product would overflow a 64-bit integer. */
    public static function multiply(int $a, int $b): int
    {
        $product = $a * $b;

        if (! is_int($product)) {
            throw new ZatcaException('[ZATCA] An amount is too large to calculate exactly.');
        }

        return $product;
    }

    /** Fixed-point text, e.g. format(11500, 2) = "115.00". */
    public static function format(int $scaled, int $scale): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $digits = str_pad((string) abs($scaled), $scale + 1, '0', STR_PAD_LEFT);

        if ($scale === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /** Like format(), with trailing zeros removed down to two decimals: "86.957000" → "86.957". */
    public static function trimmed(int $scaled, int $scale): string
    {
        $text = self::format($scaled, $scale);

        if ($scale <= self::AMOUNT_SCALE) {
            return $text;
        }

        [$integer, $fraction] = explode('.', $text);

        return $integer.'.'.str_pad(rtrim($fraction, '0'), self::AMOUNT_SCALE, '0');
    }

    public static function toFloat(int $scaled, int $scale): float
    {
        return (float) self::format($scaled, $scale);
    }

    /** Halalas as the float the DTOs carry, e.g. 11500 → 115.0. */
    public static function amount(int $halalas): float
    {
        return self::toFloat($halalas, self::AMOUNT_SCALE);
    }

    /**
     * Sum of float amounts, each already a whole number of halalas, without float drift.
     *
     * @param  iterable<float|int|string>  $amounts
     */
    public static function sum(iterable $amounts): float
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += self::halalas($amount);
        }

        return self::amount($total);
    }

    private static function decimalString(int|float|string $value, string $what): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new ZatcaException("[ZATCA] The {$what} is not a finite number.");
            }

            // Twelve decimals is enough to recover any value with up to six, which is
            // the most this class ever keeps; the rest is binary representation noise.
            return sprintf('%.12F', $value);
        }

        return trim($value);
    }
}
