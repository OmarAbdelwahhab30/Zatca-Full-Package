<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\EgsConfig;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Tests\TestCase;

/**
 * Covers the two derivations EgsConfig performs on the company data, both of
 * which feed artifacts that ZATCA rejects on mismatch: the CSR's free-text
 * location line and the invoice's seller legal name.
 */
class EgsConfigTest extends TestCase
{
    public function test_one_line_address_joins_the_structured_fields(): void
    {
        $config = $this->config([
            'building_number' => '1234',
            'street' => 'King Fahd Road',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
        ]);

        $this->assertSame('1234, King Fahd Road, Al Olaya, Riyadh', $config->oneLineAddress());
    }

    /** Partial configuration must not emit empty segments like "1234, , , Riyadh". */
    public function test_one_line_address_skips_missing_fields(): void
    {
        $config = $this->config([
            'street' => 'King Fahd Road',
            'city' => 'Riyadh',
        ]);

        $this->assertSame('King Fahd Road, Riyadh', $config->oneLineAddress());
    }

    /** An installation predating the structured keys must build the same CSR. */
    public function test_one_line_address_falls_back_to_the_legacy_value(): void
    {
        $config = $this->config(['location_address' => 'RRRD2929']);

        $this->assertSame('RRRD2929', $config->oneLineAddress());
    }

    public function test_invoice_legal_name_prefers_arabic(): void
    {
        $config = $this->config([
            'company_name_ar' => 'شركة الاختبار',
            'organization_name' => 'Test Org',
        ]);

        $this->assertSame('شركة الاختبار', $config->invoiceLegalName());
    }

    public function test_invoice_legal_name_falls_back_to_english(): void
    {
        $config = $this->config(['organization_name' => 'Test Org']);

        $this->assertSame('Test Org', $config->invoiceLegalName());
    }

    /**
     * Regression: ZATCA_COMPANY_NAME_AR=" أساس" in .env kept its leading space,
     * which reached cbc:RegistrationName. The SDK trims the seller name when
     * building the QR TLV but the XML did not, so ZATCA rejected the invoice
     * with "seller name does not match with qr code seller name".
     */
    public function test_it_trims_whitespace_from_config_values(): void
    {
        $config = $this->config([
            'company_name_ar' => ' أساس',
            'organization_name' => "  Test Org\t",
            'vat_number' => ' 399999999900003 ',
            'building_number' => " 2929\n",
            'city' => ' الرياض ',
        ]);

        $this->assertSame('أساس', $config->companyNameAr);
        $this->assertSame('أساس', $config->invoiceLegalName());
        $this->assertSame('Test Org', $config->organizationName);
        $this->assertSame('399999999900003', $config->vatNumber);
        $this->assertSame('2929', $config->buildingNumber);
        $this->assertSame('الرياض', $config->city);
    }

    /** Invisible characters pasted from a document are just as fatal as spaces. */
    public function test_it_strips_invisible_characters(): void
    {
        $config = $this->config([
            'company_name_ar' => "\u{FEFF}أساس\u{200B}",
            'organization_name' => "\u{00A0}Test Org\u{00A0}",
        ]);

        $this->assertSame('أساس', $config->companyNameAr);
        $this->assertSame('Test Org', $config->organizationName);
    }

    /** A whitespace-only value must fall back, not emit an empty element. */
    public function test_whitespace_only_values_become_null(): void
    {
        $config = $this->config([
            'company_name_ar' => '   ',
            'organization_name' => 'Test Org',
        ]);

        $this->assertNull($config->companyNameAr);
        $this->assertSame('Test Org', $config->invoiceLegalName());
    }

    public function test_it_defaults_the_new_fields_when_absent_from_config(): void
    {
        $config = EgsConfig::fromArray('default', ['environment' => ZatcaEnvironment::Sandbox->value]);

        $this->assertNull($config->companyNameAr);
        $this->assertNull($config->street);
        $this->assertNull($config->buildingNumber);
        $this->assertNull($config->postalCode);
        $this->assertNull($config->oneLineAddress());
    }

    private function config(array $overrides = []): EgsConfig
    {
        return EgsConfig::fromArray('default', array_merge([
            'environment' => ZatcaEnvironment::Sandbox->value,
        ], $overrides));
    }
}
