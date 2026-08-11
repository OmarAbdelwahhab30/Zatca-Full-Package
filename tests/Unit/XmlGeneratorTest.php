<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Zatca\Tests\TestCase;
use DateTimeImmutable;
use DOMDocument;

class XmlGeneratorTest extends TestCase
{
    public function test_it_generates_well_formed_ubl_xml_with_expected_totals(): void
    {
        $xml = $this->generate();

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Generated XML must be well-formed.');

        $this->assertStringContainsString('<cbc:ID>order:42</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:UUID>test-uuid-1234</cbc:UUID>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">230.00</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:UUID>5</cbc:UUID>', $xml); // ICV
        $this->assertStringContainsString('Test Student', $xml);
        $this->assertStringContainsString('Course Enrollment', $xml);
    }

    /**
     * The reporting profile and the simplified-invoice type code are what tell
     * ZATCA which document this is. A silent change here would be rejected at
     * validation time, long after signing.
     */
    public function test_it_declares_the_reporting_profile_and_simplified_invoice_type(): void
    {
        $xml = $this->generate();

        $this->assertStringContainsString('<cbc:ProfileID>reporting:1.0</cbc:ProfileID>', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', $xml);
    }

    /** ICV and PIH must both reach the XML — they are the invoice chain. */
    public function test_it_carries_the_counter_and_previous_invoice_hash(): void
    {
        $previousHash = base64_encode(hash('sha256', '0'));

        $xml = $this->generate($previousHash);

        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:ID>PIH</cbc:ID>', $xml);
        $this->assertStringContainsString($previousHash, $xml);
    }

    /**
     * The structured national address must reach five *distinct* XML elements.
     * Before it existed, one ZATCA_LOCATION_ADDRESS string was substituted into
     * StreetName, CitySubdivisionName and CityName alike, and the building
     * number and postal zone were hardcoded placeholders.
     */
    public function test_it_maps_the_structured_national_address_onto_distinct_elements(): void
    {
        $xml = $this->generate(egsConfig: $this->egsConfig(
            street: 'King Fahd Road',
            district: 'Al Olaya',
            buildingNumber: '1234',
            city: 'Riyadh',
            postalCode: '12345',
        ));

        $this->assertStringContainsString('<cbc:StreetName>King Fahd Road</cbc:StreetName>', $xml);
        $this->assertStringContainsString('<cbc:BuildingNumber>1234</cbc:BuildingNumber>', $xml);
        $this->assertStringContainsString('<cbc:CitySubdivisionName>Al Olaya</cbc:CitySubdivisionName>', $xml);
        $this->assertStringContainsString('<cbc:CityName>Riyadh</cbc:CityName>', $xml);
        $this->assertStringContainsString('<cbc:PostalZone>12345</cbc:PostalZone>', $xml);
    }

    /**
     * A deployment that has not set the structured address keys yet must keep
     * producing exactly what it produced before they existed — otherwise this
     * change would silently alter live invoices on deploy.
     */
    public function test_it_falls_back_to_the_legacy_single_line_address(): void
    {
        $xml = $this->generate();

        $this->assertStringContainsString('<cbc:StreetName>Test Address</cbc:StreetName>', $xml);
        $this->assertStringContainsString('<cbc:BuildingNumber>0000</cbc:BuildingNumber>', $xml);
        $this->assertStringContainsString('<cbc:CitySubdivisionName>Test Address</cbc:CitySubdivisionName>', $xml);
        $this->assertStringContainsString('<cbc:CityName>Test Address</cbc:CityName>', $xml);
        $this->assertStringContainsString('<cbc:PostalZone>00000</cbc:PostalZone>', $xml);
    }

    /** KSA tax invoices are Arabic-first, so the Arabic legal name wins. */
    public function test_it_prefers_the_arabic_legal_name_for_the_seller(): void
    {
        $xml = $this->generate(egsConfig: $this->egsConfig(companyNameAr: 'شركة الاختبار'));

        $this->assertStringContainsString('<cbc:RegistrationName>شركة الاختبار</cbc:RegistrationName>', $xml);
    }

    /** Without an Arabic name configured, the English one is still emitted. */
    public function test_it_falls_back_to_the_english_legal_name(): void
    {
        $xml = $this->generate();

        $this->assertStringContainsString('<cbc:RegistrationName>Test Org</cbc:RegistrationName>', $xml);
    }

    private function egsConfig(
        ?string $companyNameAr = null,
        ?string $street = null,
        ?string $district = null,
        ?string $buildingNumber = null,
        ?string $city = null,
        ?string $postalCode = null,
    ): EgsConfig {
        return new EgsConfig(
            egsIdentifier: 'default',
            environment: ZatcaEnvironment::Sandbox,
            vatNumber: '399999999900003',
            organizationName: 'Test Org',
            organizationUnitName: 'Test Unit',
            commonName: 'TST-EGS-01',
            serialNumber: '1-TST|2-ASAS|3-uuid',
            organizationIdentifier: '399999999900003',
            countryCode: 'SA',
            invoiceType: '0100',
            locationAddress: 'Test Address',
            businessCategory: 'Education',
            commercialRegistrationNumber: '1010010000',
            companyNameAr: $companyNameAr,
            street: $street,
            district: $district,
            buildingNumber: $buildingNumber,
            city: $city,
            postalCode: $postalCode,
        );
    }

    private function generate(?string $previousInvoiceHash = null, ?EgsConfig $egsConfig = null): string
    {
        $egsConfig ??= $this->egsConfig();

        $line = new ZatcaInvoiceLine('Course Enrollment', 1, 200.00, 15.0, 200.00, 30.00);

        $invoiceData = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'order:42',
            buyerName: 'Test Student',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-01-01 10:00:00'),
            currency: 'SAR',
            lines: [$line],
            totalExclVat: 200.00,
            totalVat: 30.00,
            totalInclVat: 230.00,
        );

        $reservation = new InvoiceSequenceReservation(5, $previousInvoiceHash ?? base64_encode(hash('sha256', '0')));

        return (new SimplifiedInvoiceXmlGenerator)->generate($invoiceData, $egsConfig, 'test-uuid-1234', $reservation);
    }
}
