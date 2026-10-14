<?php

namespace Zatca\Tests\Unit\Tax;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceDiscount;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\Support\VatSettings;
use Zatca\Tests\TestCase;

/**
 * The calculated amounts reach the UBL XML unchanged, for B2C (Simplified) and B2B
 * (Standard), and the XML satisfies the amount rules of ZATCA's own schematron as
 * shipped in SDK 3.4.8 (re-checked here in assertZatcaArithmetic). This is not a
 * substitute for the SDK's validator or the ZATCA sandbox.
 */
class VatXmlTest extends TestCase
{
    public function test_b2c_inclusive_mixed_invoice_xml(): void
    {
        $data = $this->calculated(ZatcaComplianceDocumentType::Invoice, VatPriceMode::Inclusive);
        $xml = $this->xml($data);

        // 10 × 100.00 inclusive: per-line extraction, six-decimal net unit price.
        $this->assertStringContainsString(
            '<cbc:InvoicedQuantity unitCode="PCE">10</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="SAR">869.57</cbc:LineExtensionAmount>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">130.43</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">1000.00</cbc:RoundingAmount></cac:TaxTotal>',
            $xml
        );
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="SAR">86.957</cbc:PriceAmount>', $xml);
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="SAR">28.983333</cbc:PriceAmount>', $xml);
        // A price that fits two decimals keeps exactly two, as before.
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="SAR">50.00</cbc:PriceAmount>', $xml);

        // One breakdown per rate/category; the 15% one nets the discount out.
        $this->assertStringContainsString($this->subtotal('936.52', '140.47', 'S', '15'), $xml);
        $this->assertStringContainsString($this->subtotal('100.00', '5.00', 'S', '5'), $xml);
        $this->assertStringContainsString($this->subtotal('200.00', '0.00', 'Z', '0'), $xml);

        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="SAR">1236.52</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">1381.99</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:AllowanceTotalAmount currencyID="SAR">20.00</cbc:AllowanceTotalAmount>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">1381.99</cbc:PayableAmount>', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', $xml);

        $this->assertZatcaArithmetic($xml);
    }

    public function test_b2b_exclusive_mixed_invoice_xml(): void
    {
        $data = $this->calculated(ZatcaComplianceDocumentType::StandardInvoice, VatPriceMode::Exclusive);
        $xml = $this->xml($data);

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString(
            '<cbc:InvoicedQuantity unitCode="PCE">10</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="SAR">1000.00</cbc:LineExtensionAmount>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">150.00</cbc:TaxAmount><cbc:RoundingAmount currencyID="SAR">1150.00</cbc:RoundingAmount></cac:TaxTotal>',
            $xml
        );
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="SAR">100.00</cbc:PriceAmount>', $xml);
        $this->assertStringContainsString($this->subtotal('1076.99', '161.55', 'S', '15'), $xml);
        $this->assertStringContainsString($this->subtotal('105.00', '5.25', 'S', '5'), $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="SAR">1381.99</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">1548.79</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="SAR">1548.79</cbc:PayableAmount>', $xml);

        $this->assertZatcaArithmetic($xml);
    }

    /**
     * The old inclusive problem: a unit net rounded to two decimals, times a large
     * quantity, drifts from the line net. Per-line calculation keeps every case inside
     * BR-KSA-EN16931-11.
     */
    public function test_large_inclusive_quantities_stay_within_the_line_net_rule(): void
    {
        foreach ([[1000, '0.99'], [999, '1.15'], [250, '7.77'], [12, '33.33'], [10_000, '100.00']] as [$qty, $price]) {
            $data = (new InvoiceCalculator(new VatSettings(15, VatPriceMode::Inclusive)))->calculate($this->input(
                ZatcaComplianceDocumentType::Invoice,
                [new ZatcaInvoiceItem('Bulk item', $qty, $price)],
                [],
            ));

            (new InvoiceDataValidator)->validate($data);
            $this->assertZatcaArithmetic($this->xml($data));
        }
    }

    private function calculated(ZatcaComplianceDocumentType $type, VatPriceMode $mode): ZatcaInvoiceData
    {
        $data = (new InvoiceCalculator(new VatSettings(15, $mode)))->calculate($this->input($type, [
            new ZatcaInvoiceItem('Bulk item', 10, '100.00'),
            new ZatcaInvoiceItem('Small item', 3, '33.33'),
            new ZatcaInvoiceItem('Reduced-rate item', 2, '52.50', vatRate: 5),
            ZatcaInvoiceItem::zeroRated('Export shipment', 1, 200, 'VATEX-SA-32', 'Export of goods'),
        ], [new ZatcaInvoiceDiscount('Bulk discount', 23, lineIndexes: [0])]));

        (new InvoiceDataValidator)->validate($data);

        return $data;
    }

    private function input(ZatcaComplianceDocumentType $type, array $items, array $discounts): ZatcaInvoiceInput
    {
        $standard = $type->isStandard();

        return new ZatcaInvoiceInput(
            egsIdentifier: 'default',
            sourceReference: 'INV-VAT-XML',
            buyerName: $standard ? 'Riyadh Office Supplies Co.' : null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-10-14T10:00:00+03:00'),
            currency: 'SAR',
            items: $items,
            discounts: $discounts,
            documentType: $type,
            buyer: $standard ? new BuyerParty('Riyadh Office Supplies Co.', null, BuyerOtherId::commercialRegistration('7000000007'), new BuyerAddress('Prince Sultan St', '4321', 'Al Rawdah', 'Jeddah', '23434')) : null,
            supplyDate: $standard ? new DateTimeImmutable('2026-10-14') : null,
        );
    }

    private function xml(ZatcaInvoiceData $data): string
    {
        $egs = new EgsConfig(
            egsIdentifier: 'default',
            environment: ZatcaEnvironment::Sandbox,
            vatNumber: '399999999900003',
            organizationName: 'Test Org',
            organizationUnitName: 'Test Unit',
            commonName: 'TST-EGS-01',
            serialNumber: '1-TST|2-TST|3-uuid',
            organizationIdentifier: '399999999900003',
            countryCode: 'SA',
            invoiceType: '1100',
            locationAddress: 'Test Address',
            businessCategory: 'Retail',
            commercialRegistrationNumber: '1010010000',
        );

        return app(XmlGeneratorFactory::class)->forData($data)
            ->generate($data, $egs, 'uuid-vat-xml', new InvoiceSequenceReservation(1, base64_encode(hash('sha256', '0'))));
    }

    private function subtotal(string $taxable, string $vat, string $category, string $percent): string
    {
        return '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="SAR">'.$taxable.'</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">'.$vat.'</cbc:TaxAmount>'
            .'<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">'.$category.'</cbc:ID><cbc:Percent>'.$percent.'</cbc:Percent>';
    }

    /**
     * The amount rules from the SDK 3.4.8 schematrons (Data/Rules/Schematrons), evaluated
     * on the XML itself, in integer halalas.
     */
    private function assertZatcaArithmetic(string $xml): void
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $x = new DOMXPath($doc);
        $x->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $h = static fn (string $amount): int => (int) round((float) $amount * 100);

        $lineSum = 0;
        $lineCount = 0;

        foreach ($x->query('//cac:InvoiceLine') as $line) {
            $lineCount++;
            $qty = (string) $x->evaluate('string(cbc:InvoicedQuantity)', $line);
            $price = (string) $x->evaluate('string(cac:Price/cbc:PriceAmount)', $line);
            $net = $h((string) $x->evaluate('string(cbc:LineExtensionAmount)', $line));
            $vat = $h((string) $x->evaluate('string(cac:TaxTotal/cbc:TaxAmount)', $line));
            $rounding = $h((string) $x->evaluate('string(cac:TaxTotal/cbc:RoundingAmount)', $line));

            // BR-KSA-EN16931-11: net = round(floor(qty × price × 1000) / 1000, 2) ± 0.01.
            [$intPart, $fraction] = array_pad(explode('.', $price), 2, '');
            $priceMicro = (int) $intPart * 1_000_000 + (int) str_pad($fraction, 6, '0');
            $milli = intdiv((int) $qty * $priceMicro, 1000);
            $expected = intdiv($milli + 5, 10);
            $this->assertLessThanOrEqual(1, abs($net - $expected), "BR-KSA-EN16931-11 line {$lineCount}: {$qty} × {$price}");

            // BR-KSA-51: line amount with VAT = net + VAT.
            $this->assertSame($net + $vat, $rounding, "BR-KSA-51 line {$lineCount}");
            // BR-DEC-23 / BR-KSA-DEC-03 / -04: two decimals on the line amounts.
            foreach (['cbc:LineExtensionAmount', 'cac:TaxTotal/cbc:TaxAmount', 'cac:TaxTotal/cbc:RoundingAmount'] as $path) {
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $x->evaluate("string({$path})", $line));
            }

            $lineSum += $net;
        }

        $total = fn (string $name): int => $h((string) $x->evaluate("string(//cac:LegalMonetaryTotal/cbc:{$name})"));

        // BR-CO-10: document LineExtensionAmount = sum of the line nets.
        $this->assertSame($lineSum, $total('LineExtensionAmount'), 'BR-CO-10');
        // BR-CO-13: TaxExclusiveAmount = line extension − allowances.
        $this->assertSame($total('LineExtensionAmount') - $total('AllowanceTotalAmount'), $total('TaxExclusiveAmount'), 'BR-CO-13');

        $subtotalVat = 0;

        foreach ($x->query('//cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
            $taxable = $h((string) $x->evaluate('string(cbc:TaxableAmount)', $subtotal));
            $tax = $h((string) $x->evaluate('string(cbc:TaxAmount)', $subtotal));
            $rate = (float) $x->evaluate('string(cac:TaxCategory/cbc:Percent)', $subtotal);

            // BR-S-09 / BR-CO-17: VAT = taxable × rate, within one halala per invoice line.
            $this->assertLessThanOrEqual($lineCount, abs($tax - (int) round($taxable * $rate / 100)), 'BR-S-09');
            $subtotalVat += $tax;
        }

        // BR-CO-14 and BR-CO-15.
        $documentVat = $h((string) $x->evaluate('string((//cac:TaxTotal[cac:TaxSubtotal])[1]/cbc:TaxAmount)'));
        $this->assertSame($subtotalVat, $documentVat, 'BR-CO-14');
        $this->assertSame($total('TaxExclusiveAmount') + $subtotalVat, $total('TaxInclusiveAmount'), 'BR-CO-15');
        $this->assertSame($total('TaxInclusiveAmount'), $total('PayableAmount'), 'BR-CO-16');
    }
}
