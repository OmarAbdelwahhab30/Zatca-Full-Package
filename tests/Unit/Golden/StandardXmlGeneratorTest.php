<?php

namespace Zatca\Tests\Unit\Golden;

use DateTimeImmutable;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardCreditNoteXmlGenerator;
use Zatca\Services\Invoice\XmlGenerators\V2\StandardInvoiceXmlGenerator;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Blueprint P22-P24: the Standard (Clearance, B2B) XML generator. Structural
 * expectations pinned against the SDK's own official samples
 * (Data/Samples/Standard/Invoice/Standard_Invoice.xml,
 * Data/Samples/Standard/Credit/Standard_Credit_Note.xml) — not a byte-for-byte
 * golden fixture, since real sandbox verification (P27+) is still blocked.
 *
 * @group golden
 */
#[Group('golden')]
class StandardXmlGeneratorTest extends TestCase
{
    public function test_a_standard_invoice_carries_0100000_and_the_full_buyer(): void
    {
        $xml = (new StandardInvoiceXmlGenerator)->generate(
            self::invoiceData(),
            self::egs(),
            'std-uuid-1',
            new InvoiceSequenceReservation(5, base64_encode(hash('sha256', 'prev'))),
        );

        $this->assertStringContainsString('<cbc:ProfileID>reporting:1.0</cbc:ProfileID>', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString(
            '<cac:AccountingCustomerParty><cac:Party>'.
            '<cac:PartyIdentification><cbc:ID schemeID="CRN">7000000007</cbc:ID></cac:PartyIdentification>'.
            '<cac:PostalAddress><cbc:StreetName>شارع</cbc:StreetName><cbc:BuildingNumber>9090</cbc:BuildingNumber><cbc:CitySubdivisionName>حي</cbc:CitySubdivisionName><cbc:CityName>مدينة</cbc:CityName><cbc:PostalZone>90909</cbc:PostalZone><cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'.
            '<cac:PartyLegalEntity><cbc:RegistrationName>شركة تجريبية</cbc:RegistrationName></cac:PartyLegalEntity>'.
            '</cac:Party></cac:AccountingCustomerParty>',
            $xml
        );
        $this->assertStringContainsString('<cac:Delivery><cbc:ActualDeliveryDate>2026-09-01</cbc:ActualDeliveryDate></cac:Delivery>', $xml);
    }

    public function test_a_buyer_with_a_vat_number_gets_a_party_tax_scheme_instead_of_an_identification(): void
    {
        $data = self::invoiceData()->withBuyer(new BuyerParty(
            registrationName: 'شركة تجريبية',
            vatNumber: '399999999800003',
            otherId: null,
            address: new BuyerAddress('شارع', '9090', 'حي', 'مدينة', '90909'),
        ));

        $xml = (new StandardInvoiceXmlGenerator)->generate($data, self::egs(), 'std-uuid-2', new InvoiceSequenceReservation(6, base64_encode(hash('sha256', 'prev'))));

        $this->assertStringNotContainsString('cac:PartyIdentification', $this->afterAccountingCustomerParty($xml));
        $this->assertStringContainsString('<cac:PartyTaxScheme><cbc:CompanyID>399999999800003</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>', $xml);
    }

    public function test_a_standard_document_without_a_buyer_is_refused(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('needs a buyer');

        (new StandardInvoiceXmlGenerator)->generate(
            self::invoiceData()->withBuyer(null),
            self::egs(),
            'std-uuid-3',
            new InvoiceSequenceReservation(7, base64_encode(hash('sha256', 'prev'))),
        );
    }

    public function test_a_standard_credit_note_requires_a_billing_reference_and_a_reason(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('billing reference');

        (new StandardCreditNoteXmlGenerator)->generate(
            self::invoiceData(),
            self::egs(),
            'std-uuid-4',
            new InvoiceSequenceReservation(8, base64_encode(hash('sha256', 'prev'))),
        );
    }

    private function afterAccountingCustomerParty(string $xml): string
    {
        return substr($xml, strpos($xml, '<cac:AccountingCustomerParty>'), strlen('<cac:AccountingCustomerParty>') + 400);
    }

    private static function invoiceData(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'b2b',
            sourceReference: 'payment:9',
            buyerName: 'شركة تجريبية',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-02T10:00:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('دورة', 1, 500.0, 15.0, 500.0, 75.0)],
            totalExclVat: 500.0,
            totalVat: 75.0,
            totalInclVat: 575.0,
            buyer: new BuyerParty('شركة تجريبية', null, BuyerOtherId::commercialRegistration('7000000007'), new BuyerAddress('شارع', '9090', 'حي', 'مدينة', '90909')),
            supplyDate: new DateTimeImmutable('2026-09-01T00:00:00+03:00'),
        );
    }

    private static function egs(): EgsConfig
    {
        return new EgsConfig(
            egsIdentifier: 'b2b',
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
}
