<?php

namespace Zatca\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Zatca\State\EgsRegistry;
use Zatca\Tests\TestCase;

/**
 * Blueprint P14: the optional B2B EGS inherits the seller identity from the primary EGS,
 * and "primary" is explicit rather than "first key". The config file is evaluated with a
 * controlled environment for each case.
 */
class EgsCompositionTest extends TestCase
{
    /** The primary entry's keys, in order — unchanged by P14. */
    private const PRIMARY_KEYS = [
        'environment', 'vat_number', 'organization_name', 'organization_unit_name', 'common_name',
        'serial_number', 'organization_identifier', 'country_code', 'invoice_type', 'location_address',
        'business_category', 'commercial_registration_number', 'company_name_ar', 'vat_percentage',
        'street', 'district', 'building_number', 'additional_number', 'city', 'postal_code',
    ];

    private const SELLER_KEYS = [
        'vat_number', 'organization_name', 'organization_identifier', 'country_code', 'location_address',
        'business_category', 'commercial_registration_number', 'company_name_ar', 'vat_percentage',
        'street', 'district', 'building_number', 'additional_number', 'city', 'postal_code',
    ];

    private const SELLER_ENV = [
        'ZATCA_VAT_NUMBER' => '399999999900003',
        'ZATCA_ORG_NAME' => 'Fake Co',
        'ZATCA_ORG_UNIT_NAME' => 'LMS',
        'ZATCA_CSR_COMMON_NAME' => 'TST-886431145-399999999900003',
        'ZATCA_CSR_SERIAL_NUMBER' => '1-TST|2-TST|3-primary',
        'ZATCA_INVOICE_TYPE' => '0100',
        'ZATCA_CRN' => '7000000007',
    ];

    public function test_without_a_b2b_identifier_the_registry_is_exactly_the_primary_egs(): void
    {
        $config = $this->configWith(self::SELLER_ENV);

        $this->assertSame(['default'], array_keys($config['egs']));
        $this->assertSame(self::PRIMARY_KEYS, array_keys($config['egs']['default']));
        $this->assertSame('default', $config['primary_egs']);
        $this->assertNull($config['b2b']['egs_identifier']);
        $this->assertSame('TST-886431145-399999999900003', $config['egs']['default']['common_name']);
    }

    public function test_a_b2b_identifier_adds_an_entry_that_inherits_the_seller(): void
    {
        $config = $this->configWith(self::SELLER_ENV + [
            'ZATCA_B2B_EGS_IDENTIFIER' => 'b2b',
            'ZATCA_B2B_CSR_COMMON_NAME' => 'TST-886431145-399999999900003-b2b',
            'ZATCA_B2B_CSR_SERIAL_NUMBER' => '1-TST|2-TST|3-b2b',
            'ZATCA_B2B_INVOICE_TYPE' => '1000',
            'ZATCA_B2B_ORG_UNIT_NAME' => 'LMS B2B',
        ]);

        $this->assertSame(['default', 'b2b'], array_keys($config['egs']));
        $this->assertSame('default', $config['primary_egs']);
        $primary = $config['egs']['default'];
        $b2b = $config['egs']['b2b'];
        $this->assertSame(self::PRIMARY_KEYS, array_keys($b2b));
        foreach (self::SELLER_KEYS as $key) {
            $this->assertSame($primary[$key], $b2b[$key], $key);
        }
        $this->assertSame(
            ['TST-886431145-399999999900003-b2b', '1-TST|2-TST|3-b2b', '1000', 'LMS B2B', $primary['environment']],
            [$b2b['common_name'], $b2b['serial_number'], $b2b['invoice_type'], $b2b['organization_unit_name'], $b2b['environment']]
        );
        $this->assertSame('0100', $primary['invoice_type'], 'The primary entry is untouched.');
    }

    /** CSR fields are never inherited: a B2B EGS must not reuse the primary's device identity. */
    public function test_unset_b2b_csr_fields_stay_null(): void
    {
        $b2b = $this->configWith(self::SELLER_ENV + ['ZATCA_B2B_EGS_IDENTIFIER' => 'b2b'])['egs']['b2b'];

        $this->assertSame([null, null, null, 'LMS'], [$b2b['common_name'], $b2b['serial_number'], $b2b['invoice_type'], $b2b['organization_unit_name']]);
    }

    public function test_a_b2b_identifier_equal_to_the_primary_is_ignored(): void
    {
        $config = $this->configWith(self::SELLER_ENV + ['ZATCA_B2B_EGS_IDENTIFIER' => 'default']);

        $this->assertSame(['default'], array_keys($config['egs']));
        $this->assertSame('default', $config['b2b']['egs_identifier']);
    }

    public function test_primary_is_explicit_whatever_the_order(): void
    {
        Config::set('zatca.egs', ['b2b' => Config::get('zatca.egs.default')] + Config::get('zatca.egs'));
        Config::set('zatca.primary_egs', 'default');
        $registry = new EgsRegistry;

        $this->assertSame('b2b', $registry->default()->egsIdentifier, 'default() is still "first key".');
        $this->assertSame('default', $registry->primary()->egsIdentifier);
    }

    /** @dataProvider missingPrimary */
    public function test_primary_falls_back_to_the_first_egs(?string $primary): void
    {
        Config::set('zatca.primary_egs', $primary);

        $this->assertSame('default', (new EgsRegistry)->primary()->egsIdentifier);
    }

    public static function missingPrimary(): array
    {
        return ['key absent (old cached config)' => [null], 'unknown identifier' => ['nope']];
    }

    public function test_health_check_passes_the_identity_check_for_a_composed_b2b_egs(): void
    {
        $this->useComposedB2bEgs();

        $this->artisan('zatca:health-check')
            ->doesntExpectOutputToContain('seller data differs')
            ->expectsOutputToContain('[PENDING] [b2b] B2B EGS not ready for onboarding — not set: ZATCA_B2B_CSR_COMMON_NAME, ZATCA_B2B_CSR_SERIAL_NUMBER, ZATCA_B2B_INVOICE_TYPE.');
    }

    public function test_health_check_fails_on_seller_drift(): void
    {
        $this->useComposedB2bEgs();
        Config::set('zatca.egs.b2b.vat_number', '300000000000003');
        Config::set('zatca.egs.b2b.city', 'Elsewhere');

        $this->artisan('zatca:health-check')
            ->expectsOutputToContain('[FAIL] [b2b] seller data differs from the primary EGS [default]: VAT number, city.')
            ->assertFailed();
    }

    public function test_health_check_fails_when_the_b2b_identifier_equals_the_primary(): void
    {
        Config::set('zatca.b2b.egs_identifier', 'default');

        $this->artisan('zatca:health-check')
            ->expectsOutputToContain('[FAIL] ZATCA_B2B_EGS_IDENTIFIER [default] equals the primary EGS identifier')
            ->assertFailed();
    }

    private function useComposedB2bEgs(): void
    {
        $b2b = array_merge(Config::get('zatca.egs.default'), ['common_name' => null, 'serial_number' => null, 'invoice_type' => null]);
        Config::set('zatca.egs.b2b', $b2b);
        Config::set('zatca.b2b.egs_identifier', 'b2b');
    }

    /** Evaluates the package config file with exactly these ZATCA_* variables set. */
    private function configWith(array $env): array
    {
        $names = array_unique(array_merge(array_keys($env), [
            'ZATCA_EGS_IDENTIFIER', 'ZATCA_B2B_EGS_IDENTIFIER', 'ZATCA_B2B_CSR_COMMON_NAME', 'ZATCA_B2B_CSR_SERIAL_NUMBER',
            'ZATCA_B2B_INVOICE_TYPE', 'ZATCA_B2B_ORG_UNIT_NAME', 'ZATCA_B2B_ENVIRONMENT',
        ]));
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
            if (array_key_exists($name, $env)) {
                $_SERVER[$name] = $_ENV[$name] = $env[$name];
                putenv("{$name}={$env[$name]}");
            }
        }

        try {
            return require __DIR__.'/../../config/zatca.php';
        } finally {
            foreach ($saved as $name => [$server, $envValue, $put]) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($envValue !== null) {
                    $_ENV[$name] = $envValue;
                }
                if ($put !== false) {
                    putenv("{$name}={$put}");
                }
            }
        }
    }
}
