<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedDebitNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Zatca\Tests\TestCase;
use DateTimeImmutable;
use DOMDocument;

/**
 * Credit and debit notes share the entire template with the plain invoice
 * (AbstractSimplifiedUblDocumentXmlGenerator, exercised by XmlGeneratorTest)
 * except for the InvoiceTypeCode, the added BillingReference, and the KSA-10
 * reason inside PaymentMeans — this file only proves those differences.
 *
 * The reason specifically belongs at cac:PaymentMeans/cbc:InstructionNote per
 * BR-KSA-17, not a top-level cbc:Note — a first implementation used cbc:Note
 * and ZATCA's sandbox rejected it for landing at the wrong path, so every
 * test here also asserts cbc:Note is absent.
 */
class SimplifiedNoteXmlGeneratorTest extends TestCase
{
    public function test_credit_note_declares_type_381_bills_against_the_invoice_and_states_its_reason(): void
    {
        $xml = $this->generate(new SimplifiedCreditNoteXmlGenerator, 'compliance-test-20260101000000');

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Generated XML must be well-formed.');

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>compliance-test-20260101000000</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            $xml
        );
        // ZATCA rule KSA-10 (BR-KSA-17): the reason must be
        // cac:PaymentMeans/cbc:InstructionNote specifically.
        $this->assertStringContainsString(
            '<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode><cbc:InstructionNote>Compliance test credit note</cbc:InstructionNote></cac:PaymentMeans>',
            $xml
        );
        $this->assertStringNotContainsString('<cbc:Note>', $xml);
    }

    public function test_debit_note_declares_type_383_bills_against_the_invoice_and_states_its_reason(): void
    {
        $xml = $this->generate(new SimplifiedDebitNoteXmlGenerator, 'compliance-test-20260101000000');

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">383</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>compliance-test-20260101000000</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            $xml
        );
        $this->assertStringContainsString(
            '<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode><cbc:InstructionNote>Compliance test debit note</cbc:InstructionNote></cac:PaymentMeans>',
            $xml
        );
        $this->assertStringNotContainsString('<cbc:Note>', $xml);
    }

    public function test_plain_invoice_payment_means_is_unchanged_and_has_no_instruction_note(): void
    {
        $xml = (new SimplifiedInvoiceXmlGenerator)->generate(
            $this->invoiceData(),
            $this->egsConfig(),
            'test-uuid-1234',
            new InvoiceSequenceReservation(5, base64_encode(hash('sha256', '0')))
        );

        $this->assertStringContainsString('<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode></cac:PaymentMeans>', $xml);
        $this->assertStringNotContainsString('<cbc:InstructionNote>', $xml);
        $this->assertStringNotContainsString('<cbc:Note>', $xml);
    }

    public function test_credit_note_without_a_billing_reference_is_refused(): void
    {
        $this->expectException(ZatcaException::class);

        $this->generate(new SimplifiedCreditNoteXmlGenerator, null);
    }

    public function test_debit_note_without_a_billing_reference_is_refused(): void
    {
        $this->expectException(ZatcaException::class);

        $this->generate(new SimplifiedDebitNoteXmlGenerator, null);
    }

    private function generate(object $generator, ?string $billingReferenceId): string
    {
        $reservation = new InvoiceSequenceReservation(5, base64_encode(hash('sha256', '0')));

        return $generator->generate($this->invoiceData(), $this->egsConfig(), 'test-uuid-1234', $reservation, $billingReferenceId);
    }

    private function egsConfig(): EgsConfig
    {
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
        );
    }

    private function invoiceData(): ZatcaInvoiceData
    {
        $line = new ZatcaInvoiceLine('Item Enrollment', 1, 200.00, 15.0, 200.00, 30.00);

        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'note:42',
            buyerName: 'Test Student',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-01-01 10:00:00'),
            currency: 'SAR',
            lines: [$line],
            totalExclVat: 200.00,
            totalVat: 30.00,
            totalInclVat: 230.00,
        );
    }
}
