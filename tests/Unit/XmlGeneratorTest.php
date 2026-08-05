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

    private function generate(?string $previousInvoiceHash = null): string
    {
        $egsConfig = new EgsConfig(
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
        );

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
