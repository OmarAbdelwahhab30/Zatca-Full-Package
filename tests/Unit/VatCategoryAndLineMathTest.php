<?php

namespace Zatca\Tests\Unit;

use DateTimeImmutable;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\TestCase;

/**
 * Zero-rated and exempt lines, the per-line arithmetic checks, and the SAR-only rule.
 */
class VatCategoryAndLineMathTest extends TestCase
{
    public function test_a_mixed_standard_and_zero_rated_invoice_validates_and_renders_both_categories(): void
    {
        $data = $this->invoice([
            new ZatcaInvoiceLine('Headphones', 2, 150.00, 15.0, 300.00, 45.00),
            $this->zeroRated('Export shipment', 1, 200.00),
        ]);

        (new InvoiceDataValidator)->validate($data);
        $xml = $this->xml($data);

        $this->assertStringContainsString('<cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>15</cbc:Percent>', $xml);
        $this->assertStringContainsString('<cac:ClassifiedTaxCategory><cbc:ID>Z</cbc:ID><cbc:Percent>0</cbc:Percent>', $xml);
        $this->assertStringContainsString(
            '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="SAR">200.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">0.00</cbc:TaxAmount>'
            .'<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">Z</cbc:ID><cbc:Percent>0</cbc:Percent>'
            .'<cbc:TaxExemptionReasonCode>VATEX-SA-32</cbc:TaxExemptionReasonCode><cbc:TaxExemptionReason>Export of goods</cbc:TaxExemptionReason>',
            $xml
        );
        $this->assertStringContainsString('<cbc:TaxableAmount currencyID="SAR">300.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="SAR">45.00</cbc:TaxAmount>', $xml);
    }

    public function test_an_exempt_allowance_is_rendered_with_its_category(): void
    {
        $data = $this->invoice(
            [$this->exempt('Financial service fee', 1, 100.00)],
            [new ZatcaAllowanceCharge('Loyalty discount', 10.00, 0.0, 0.00, vatCategory: ZatcaVatCategory::Exempt, vatExemptionReasonCode: 'VATEX-SA-29', vatExemptionReason: 'Financial services')],
        );

        (new InvoiceDataValidator)->validate($data);

        $this->assertStringContainsString('<cbc:Amount currencyID="SAR">10.00</cbc:Amount><cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">E</cbc:ID><cbc:Percent>0</cbc:Percent><cac:TaxScheme>', $this->xml($data));
    }

    public function test_a_zero_percent_line_must_not_be_standard_rated(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('standard-rated (S) with a 0% rate');

        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('Free sample', 1, 10.00, 0.0, 10.00, 0.00)]));
    }

    public function test_a_zero_rated_line_needs_an_exemption_code(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('VATEX-SA-32');

        (new InvoiceDataValidator)->validate($this->invoice([
            new ZatcaInvoiceLine('Export', 1, 10.00, 0.0, 10.00, 0.00, ZatcaVatCategory::ZeroRated, null, 'Export of goods'),
        ]));
    }

    public function test_a_zero_rated_line_needs_the_reason_text(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('exemption reason text');

        (new InvoiceDataValidator)->validate($this->invoice([
            new ZatcaInvoiceLine('Export', 1, 10.00, 0.0, 10.00, 0.00, ZatcaVatCategory::ZeroRated, 'VATEX-SA-32', ' '),
        ]));
    }

    public function test_a_zero_rated_line_cannot_carry_vat(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('must have a 0% rate and 0.00 VAT');

        (new InvoiceDataValidator)->validate($this->invoice([
            new ZatcaInvoiceLine('Export', 1, 10.00, 15.0, 10.00, 1.50, ZatcaVatCategory::ZeroRated, 'VATEX-SA-32', 'Export of goods'),
        ]));
    }

    public function test_line_net_must_equal_quantity_times_unit_price(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('quantity × unit price does not equal the line net amount');

        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('Chair', 2, 400.00, 15.0, 700.00, 105.00)]));
    }

    public function test_line_vat_must_equal_net_times_rate(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('VAT amount does not equal the line net × 15%');

        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('Chair', 1, 400.00, 15.0, 400.00, 40.00)]));
    }

    public function test_one_fil_of_rounding_difference_is_accepted(): void
    {
        // 33.33 × 15% = 4.9995 → 5.00; a host that rounded per unit may have 4.99.
        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('Cable', 1, 33.33, 15.0, 33.33, 4.99)]));

        $this->assertTrue(true);
    }

    public function test_a_vat_only_line_is_allowed(): void
    {
        // A Debit Note VAT Adjustment charges only under-collected VAT on a zero net.
        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('VAT correction', 1, 0.00, 15.0, 0.00, 30.00)]));

        $this->assertTrue(true);
    }

    public function test_only_sar_is_accepted(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('only SAR is supported');

        (new InvoiceDataValidator)->validate($this->invoice([new ZatcaInvoiceLine('Chair', 1, 100.00, 15.0, 100.00, 15.00)], currency: 'USD'));
    }

    public function test_the_category_survives_a_snapshot_round_trip_and_standard_snapshots_are_unchanged(): void
    {
        $data = $this->invoice([new ZatcaInvoiceLine('Chair', 1, 100.00, 15.0, 100.00, 15.00), $this->zeroRated('Export', 1, 50.00)]);

        $snapshot = InvoiceDataSnapshot::toArray($data);
        $this->assertArrayNotHasKey('vat_category', $snapshot['lines'][0]);
        $this->assertSame('Z', $snapshot['lines'][1]['vat_category']);

        $restored = InvoiceDataSnapshot::fromArray($snapshot);
        $this->assertSame(ZatcaVatCategory::Standard, $restored->lines[0]->vatCategory);
        $this->assertSame(ZatcaVatCategory::ZeroRated, $restored->lines[1]->vatCategory);
        $this->assertSame('VATEX-SA-32', $restored->lines[1]->vatExemptionReasonCode);
        $this->assertSame('Export of goods', $restored->lines[1]->vatExemptionReason);
    }

    public function test_with_amounts_keeps_the_category(): void
    {
        $copy = $this->zeroRated('Export', 2, 50.00)->withAmounts(1, 50.00, 50.00, 0.00);

        $this->assertSame(ZatcaVatCategory::ZeroRated, $copy->vatCategory);
        $this->assertSame('VATEX-SA-32', $copy->vatExemptionReasonCode);
        $this->assertSame(1, $copy->quantity);
    }

    private function zeroRated(string $name, int $qty, float $unit): ZatcaInvoiceLine
    {
        return new ZatcaInvoiceLine($name, $qty, $unit, 0.0, round($qty * $unit, 2), 0.00, ZatcaVatCategory::ZeroRated, 'VATEX-SA-32', 'Export of goods');
    }

    private function exempt(string $name, int $qty, float $unit): ZatcaInvoiceLine
    {
        return new ZatcaInvoiceLine($name, $qty, $unit, 0.0, round($qty * $unit, 2), 0.00, ZatcaVatCategory::Exempt, 'VATEX-SA-29', 'Financial services');
    }

    /**
     * @param  ZatcaInvoiceLine[]  $lines
     * @param  ZatcaAllowanceCharge[]  $allowances
     */
    private function invoice(array $lines, array $allowances = [], string $currency = 'SAR'): ZatcaInvoiceData
    {
        $net = round(array_sum(array_map(fn ($l) => $l->lineTotalExclVat, $lines)) - array_sum(array_map(fn ($a) => $a->amountExclVat, $allowances)), 2);
        $vat = round(array_sum(array_map(fn ($l) => $l->vatAmount, $lines)) - array_sum(array_map(fn ($a) => $a->vatAmount, $allowances)), 2);

        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'INV-VAT-1',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-10-13T10:00:00+03:00'),
            currency: $currency,
            lines: $lines,
            totalExclVat: $net,
            totalVat: $vat,
            totalInclVat: round($net + $vat, 2),
            allowanceCharges: $allowances,
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
            serialNumber: '1-TST|2-ASAS|3-uuid',
            organizationIdentifier: '399999999900003',
            countryCode: 'SA',
            invoiceType: '0100',
            locationAddress: 'Test Address',
            businessCategory: 'Retail',
            commercialRegistrationNumber: '1010010000',
        );

        return (new SimplifiedInvoiceXmlGenerator)->generate($data, $egs, 'uuid-vat', new InvoiceSequenceReservation(1, base64_encode(hash('sha256', '0'))));
    }
}
