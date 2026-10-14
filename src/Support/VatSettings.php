<?php

namespace Zatca\Support;

use Zatca\Enums\VatPriceMode;
use Zatca\Exceptions\ZatcaException;

/**
 * The default VAT rate and price mode, read from config('zatca.vat').
 *
 * Read through config() only — never env() — so `php artisan config:cache` works. An
 * empty value means "use the default" (15%, inclusive); anything else that is not valid
 * stops with a message naming the .env key, rather than silently pricing every invoice
 * with a wrong rate.
 *
 * These are DEFAULTS for new documents only. Once a document is built, its rate, VAT
 * category and price mode are frozen in its snapshot, and nothing that rebuilds or
 * adjusts it (regeneration, credit notes) ever reads this again.
 */
final class VatSettings
{
    public const DEFAULT_PERCENTAGE = 15.0;

    public const DEFAULT_MODE = VatPriceMode::Inclusive;

    public function __construct(
        /** Default rate for standard-rated (S) items, e.g. 15.0. */
        public readonly float $percentage = self::DEFAULT_PERCENTAGE,
        public readonly VatPriceMode $mode = self::DEFAULT_MODE,
    ) {
        self::assertStandardRate($percentage, 'ZATCA_VAT_PERCENTAGE');
    }

    /** @throws ZatcaException when the configured rate or mode is invalid. */
    public static function fromConfig(): self
    {
        // Outside a booted Laravel app (plain PHP, a pure unit test) there is no config to
        // read, so the defaults apply.
        if (! function_exists('app') || ! app()->bound('config')) {
            return new self;
        }

        return self::fromValues(config('zatca.vat.percentage'), config('zatca.vat.mode'));
    }

    /** @throws ZatcaException when a value is present but invalid. */
    public static function fromValues(mixed $percentage, mixed $mode): self
    {
        return new self(
            percentage: self::isBlank($percentage) ? self::DEFAULT_PERCENTAGE : self::parsePercentage($percentage),
            mode: self::isBlank($mode) ? self::DEFAULT_MODE : self::parseMode($mode),
        );
    }

    /** The rate in hundredths of a percent: 15% → 1500. */
    public function basisPoints(): int
    {
        return self::rateBasisPoints($this->percentage, 'ZATCA_VAT_PERCENTAGE');
    }

    /**
     * A VAT rate in hundredths of a percent. ZATCA allows 0.00–100.00 with at most two
     * decimals (BR-KSA-DEC-02).
     *
     * @throws ZatcaException
     */
    public static function rateBasisPoints(int|float|string $rate, string $what = 'VAT rate'): int
    {
        $micro = Money::toScaled($rate, Money::PRICE_SCALE, $what);

        if ($micro % 10_000 !== 0) {
            throw new ZatcaException("[ZATCA] The {$what} [{$rate}] has more than two decimals (BR-KSA-DEC-02).");
        }

        $basisPoints = intdiv($micro, 10_000);

        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new ZatcaException("[ZATCA] The {$what} [{$rate}] must be between 0 and 100 (BR-KSA-DEC-02).");
        }

        return $basisPoints;
    }

    private static function assertStandardRate(float $percentage, string $what): void
    {
        if (self::rateBasisPoints($percentage, $what) === 0) {
            throw new ZatcaException(
                "[ZATCA] {$what} must be above 0. Zero-rated and exempt items are marked per item, not with a 0% default."
            );
        }
    }

    private static function parsePercentage(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            throw new ZatcaException('[ZATCA] ZATCA_VAT_PERCENTAGE must be a number such as 15, got ['.self::describe($value).'].');
        }

        return (float) (is_string($value) ? trim($value) : $value);
    }

    private static function parseMode(mixed $value): VatPriceMode
    {
        $mode = is_string($value) ? VatPriceMode::tryFrom(strtolower(trim($value))) : ($value instanceof VatPriceMode ? $value : null);

        return $mode ?? throw new ZatcaException(
            '[ZATCA] ZATCA_VAT_MODE must be "inclusive" or "exclusive", got ['.self::describe($value).'].'
        );
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
