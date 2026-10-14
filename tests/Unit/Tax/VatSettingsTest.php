<?php

namespace Zatca\Tests\Unit\Tax;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Zatca\Enums\VatPriceMode;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\VatSettings;
use Zatca\Tests\TestCase;

/**
 * ZATCA_VAT_PERCENTAGE / ZATCA_VAT_MODE, read through config('zatca.vat').
 */
class VatSettingsTest extends TestCase
{
    public function test_the_defaults_are_15_percent_inclusive(): void
    {
        $settings = VatSettings::fromConfig();

        $this->assertSame(15.0, $settings->percentage);
        $this->assertSame(VatPriceMode::Inclusive, $settings->mode);
        $this->assertSame(1500, $settings->basisPoints());
    }

    public function test_the_package_config_file_carries_the_defaults(): void
    {
        $this->assertEquals(15, Config::get('zatca.vat.percentage'));
        $this->assertSame('inclusive', Config::get('zatca.vat.mode'));
    }

    /**
     * @dataProvider validValues
     */
    #[DataProvider('validValues')]
    public function test_configured_values_are_read(mixed $percentage, mixed $mode, float $expectedPercentage, VatPriceMode $expectedMode): void
    {
        Config::set('zatca.vat.percentage', $percentage);
        Config::set('zatca.vat.mode', $mode);

        $settings = VatSettings::fromConfig();

        $this->assertSame($expectedPercentage, $settings->percentage);
        $this->assertSame($expectedMode, $settings->mode);
    }

    public static function validValues(): array
    {
        return [
            'custom rate, exclusive' => ['5', 'exclusive', 5.0, VatPriceMode::Exclusive],
            'two decimals' => ['12.75', 'inclusive', 12.75, VatPriceMode::Inclusive],
            'number not string' => [15, 'inclusive', 15.0, VatPriceMode::Inclusive],
            'mode case and spaces' => ['15', ' Exclusive ', 15.0, VatPriceMode::Exclusive],
            'missing values' => [null, null, 15.0, VatPriceMode::Inclusive],
            'empty values (ZATCA_VAT_MODE= in .env)' => ['', '', 15.0, VatPriceMode::Inclusive],
            'hundred percent' => ['100', 'exclusive', 100.0, VatPriceMode::Exclusive],
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    #[DataProvider('invalidValues')]
    public function test_invalid_values_stop_with_a_message_naming_the_env_key(mixed $percentage, mixed $mode, string $key): void
    {
        Config::set('zatca.vat.percentage', $percentage);
        Config::set('zatca.vat.mode', $mode);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage($key);

        VatSettings::fromConfig();
    }

    public static function invalidValues(): array
    {
        return [
            'text rate' => ['abc', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'zero rate' => ['0', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'negative rate' => ['-5', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'above 100' => ['101', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'three decimals (BR-KSA-DEC-02)' => ['15.555', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'percent sign' => ['15%', 'inclusive', 'ZATCA_VAT_PERCENTAGE'],
            'unknown mode' => ['15', 'gross', 'ZATCA_VAT_MODE'],
            'mode not a string' => ['15', ['inclusive'], 'ZATCA_VAT_MODE'],
        ];
    }

    /**
     * config:cache freezes config() and makes env() return null outside the config files.
     * The settings must therefore come from config() and nothing else.
     */
    public function test_settings_come_from_config_not_from_the_environment(): void
    {
        putenv('ZATCA_VAT_PERCENTAGE=5');
        putenv('ZATCA_VAT_MODE=exclusive');

        try {
            Config::set('zatca.vat.percentage', 15);
            Config::set('zatca.vat.mode', 'inclusive');

            $settings = VatSettings::fromConfig();

            $this->assertSame(15.0, $settings->percentage);
            $this->assertSame(VatPriceMode::Inclusive, $settings->mode);
        } finally {
            putenv('ZATCA_VAT_PERCENTAGE');
            putenv('ZATCA_VAT_MODE');
        }
    }

    /** No package class may call env() — it returns null once the config is cached. */
    public function test_no_package_class_reads_the_environment_directly(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath(__DIR__.'/../../../src'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Code tokens only: comments that explain the rule may say "env()".
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));

            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'env') {
                    $previous = $tokens[$i - 1] ?? null;
                    $isMethodOrDeclaration = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

                    if (! $isMethodOrDeclaration) {
                        $offenders[] = $file->getFilename().':'.$token[2];
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_health_check_shows_the_effective_vat_settings(): void
    {
        $this->artisan('zatca:health-check')
            ->expectsOutputToContain('[OK] VAT: 15% for standard-rated items, prices are VAT-inclusive (ZATCA_VAT_PERCENTAGE, ZATCA_VAT_MODE).');

        Config::set('zatca.vat.percentage', '12.5');
        Config::set('zatca.vat.mode', 'exclusive');

        $this->artisan('zatca:health-check')
            ->expectsOutputToContain('[OK] VAT: 12.5% for standard-rated items, prices are VAT-exclusive');
    }

    public function test_health_check_fails_on_an_invalid_vat_setting(): void
    {
        Config::set('zatca.vat.mode', 'gross');

        $this->artisan('zatca:health-check')
            ->expectsOutputToContain('[FAIL] [ZATCA] ZATCA_VAT_MODE must be "inclusive" or "exclusive", got [gross].')
            ->assertFailed();
    }

    public function test_a_cached_config_is_what_the_calculation_uses(): void
    {
        // What `php artisan config:cache` produces: the merged array, loaded as-is.
        $cached = require __DIR__.'/../../../config/zatca.php';
        $cached['vat'] = ['percentage' => '5', 'mode' => 'exclusive'];
        Config::set('zatca', $cached);

        $settings = VatSettings::fromConfig();

        $this->assertSame(5.0, $settings->percentage);
        $this->assertSame(VatPriceMode::Exclusive, $settings->mode);
    }
}
