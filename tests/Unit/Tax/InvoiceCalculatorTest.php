<?php

namespace Zatca\Tests\Unit\Tax;

use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceDiscount;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\Support\VatSettings;
use Zatca\Tests\TestCase;

/**
 * VAT calculated by the package from prices and quantities. Every expected figure was
 * worked out independently (decimal arithmetic, half-up) and is asserted exactly; every
 * calculated invoice must also pass InvoiceDataValidator, the gate before an ICV is spent.
 */
class InvoiceCalculatorTest extends TestCase
{
    // ── default: 15%, inclusive ─────────────────────────────────────────────

    public function test_the_default_takes_15_percent_out_of_an_inclusive_price(): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Product A', 1, 115)]);

        $this->assertLine($data, 0, net: 100.00, vat: 15.00, gross: 115.00, unit: 100.0, rate: 15.0);
        $this->assertTotals($data, 100.00, 15.00, 115.00);
        $this->assertSame('115.00', $data->lines[0]->suppliedPrice);
        $this->assertSame(VatPriceMode::Inclusive, $data->lines[0]->priceMode);
    }

    public function test_exclusive_mode_adds_15_percent(): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Product A', 1, 100)], new VatSettings(15, VatPriceMode::Exclusive));

        $this->assertLine($data, 0, net: 100.00, vat: 15.00, gross: 115.00, unit: 100.0, rate: 15.0);
        $this->assertTotals($data, 100.00, 15.00, 115.00);
        $this->assertSame(VatPriceMode::Exclusive, $data->lines[0]->priceMode);
    }

    public function test_the_configured_mode_and_rate_are_used_when_none_is_injected(): void
    {
        Config::set('zatca.vat.percentage', '5');
        Config::set('zatca.vat.mode', 'exclusive');

        $data = (new InvoiceCalculator)->calculate($this->input([new ZatcaInvoiceItem('Product A', 1, 200)]));

        $this->assertLine($data, 0, net: 200.00, vat: 10.00, gross: 210.00, unit: 200.0, rate: 5.0);
    }

    public function test_a_custom_rate_inclusive(): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Product A', 1, 105)], new VatSettings(5, VatPriceMode::Inclusive));

        $this->assertLine($data, 0, net: 100.00, vat: 5.00, gross: 105.00, unit: 100.0, rate: 5.0);
    }

    public function test_a_round_inclusive_price_extracts_vat_to_the_halala(): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Product A', 1, 100)]);

        $this->assertLine($data, 0, net: 86.96, vat: 13.04, gross: 100.00, unit: 86.96, rate: 15.0);
    }

    public function test_an_invoice_may_override_the_configured_mode(): void
    {
        $data = (new InvoiceCalculator(new VatSettings(15, VatPriceMode::Inclusive)))
            ->calculate($this->input([new ZatcaInvoiceItem('Product A', 1, 100)], priceMode: VatPriceMode::Exclusive));

        $this->assertLine($data, 0, net: 100.00, vat: 15.00, gross: 115.00, unit: 100.0, rate: 15.0);
    }

    // ── quantities and the net unit price ───────────────────────────────────

    /**
     * Calculated per LINE: ten items at 100.00 inclusive are 1,000.00, and the net unit
     * price carries the decimals that make quantity × price equal the line net.
     *
     * @dataProvider quantityCases
     */
    #[DataProvider('quantityCases')]
    public function test_quantities_are_calculated_per_line(int $qty, string $price, VatPriceMode $mode, float $net, float $vat, float $gross, float $unit): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Item', $qty, $price)], new VatSettings(15, $mode));

        $this->assertLine($data, 0, $net, $vat, $gross, $unit, 15.0);
        $this->assertSame($qty, $data->lines[0]->quantity);
    }

    public static function quantityCases(): array
    {
        return [
            '10 × 100.00 inclusive' => [10, '100.00', VatPriceMode::Inclusive, 869.57, 130.43, 1000.00, 86.957],
            '3 × 33.33 inclusive' => [3, '33.33', VatPriceMode::Inclusive, 86.95, 13.04, 99.99, 28.983333],
            '3 × 33.33 exclusive' => [3, '33.33', VatPriceMode::Exclusive, 99.99, 15.00, 114.99, 33.33],
            '7 × 14.99 inclusive' => [7, '14.99', VatPriceMode::Inclusive, 91.24, 13.69, 104.93, 13.034286],
            '4 × 99.99 inclusive' => [4, '99.99', VatPriceMode::Inclusive, 347.79, 52.17, 399.96, 86.9475],
        ];
    }

    // ── rounding and precision ──────────────────────────────────────────────

    /**
     * @dataProvider roundingCases
     */
    #[DataProvider('roundingCases')]
    public function test_rounding_is_half_up_to_the_halala(int $qty, string $price, VatPriceMode $mode, float $net, float $vat, float $gross): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Item', $qty, $price)], new VatSettings(15, $mode));

        $this->assertSame([$net, $vat, $gross], [$data->lines[0]->lineTotalExclVat, $data->lines[0]->vatAmount, $data->lines[0]->lineTotalInclVat()]);
    }

    public static function roundingCases(): array
    {
        return [
            'VAT of exactly half a halala rounds up' => [1, '0.10', VatPriceMode::Exclusive, 0.10, 0.02, 0.12],
            'one halala inclusive' => [1, '0.01', VatPriceMode::Inclusive, 0.01, 0.00, 0.01],
            'three-decimal price rounds once, per line' => [1, '9.999', VatPriceMode::Exclusive, 10.00, 1.50, 11.50],
            'six-decimal price × 3' => [3, '0.333333', VatPriceMode::Exclusive, 1.00, 0.15, 1.15],
        ];
    }

    public function test_many_lines_total_without_drift(): void
    {
        $items = array_fill(0, 100, new ZatcaInvoiceItem('Item', 1, '0.10'));
        $data = $this->calculate($items, new VatSettings(15, VatPriceMode::Exclusive));

        // Each line: 0.10 + 0.02 VAT. Totals are sums of the rounded lines (BR-CO-10/14).
        $this->assertTotals($data, 10.00, 2.00, 12.00);
    }

    // ── mixed rates and line overrides ──────────────────────────────────────

    public function test_a_line_may_override_the_default_rate(): void
    {
        $data = $this->calculate([
            new ZatcaInvoiceItem('Standard item', 1, 115),
            new ZatcaInvoiceItem('Reduced-rate item', 2, '52.50', vatRate: 5),
        ]);

        $this->assertLine($data, 0, net: 100.00, vat: 15.00, gross: 115.00, unit: 100.0, rate: 15.0);
        $this->assertLine($data, 1, net: 100.00, vat: 5.00, gross: 105.00, unit: 50.0, rate: 5.0);
        $this->assertTotals($data, 200.00, 20.00, 220.00);
    }

    public function test_a_line_rate_with_two_decimals(): void
    {
        $data = $this->calculate([new ZatcaInvoiceItem('Item', 1, 1000, vatRate: '12.5')], new VatSettings(15, VatPriceMode::Exclusive));

        $this->assertLine($data, 0, net: 1000.00, vat: 125.00, gross: 1125.00, unit: 1000.0, rate: 12.5);
    }

    // ── zero-rated and exempt ───────────────────────────────────────────────

    /**
     * Never 15%: the same price gives the same net in either mode, with no VAT.
     *
     * @dataProvider modes
     */
    #[DataProvider('modes')]
    public function test_zero_rated_and_exempt_items_carry_no_vat(VatPriceMode $mode): void
    {
        $data = $this->calculate([
            ZatcaInvoiceItem::zeroRated('Export shipment', 2, 50, 'VATEX-SA-32', 'Export of goods'),
            ZatcaInvoiceItem::exempt('Life insurance', 1, 500, 'VATEX-SA-29', 'Financial services'),
            new ZatcaInvoiceItem('Headphones', 1, 100),
        ], new VatSettings(15, $mode));

        $this->assertLine($data, 0, net: 100.00, vat: 0.00, gross: 100.00, unit: 50.0, rate: 0.0);
        $this->assertSame(ZatcaVatCategory::ZeroRated, $data->lines[0]->vatCategory);
        $this->assertSame('VATEX-SA-32', $data->lines[0]->vatExemptionReasonCode);
        $this->assertLine($data, 1, net: 500.00, vat: 0.00, gross: 500.00, unit: 500.0, rate: 0.0);
        $this->assertSame(ZatcaVatCategory::Exempt, $data->lines[1]->vatCategory);

        $mode === VatPriceMode::Inclusive
            ? $this->assertTotals($data, 686.96, 13.04, 700.00)
            : $this->assertTotals($data, 700.00, 15.00, 715.00);
    }

    public static function modes(): array
    {
        return ['inclusive' => [VatPriceMode::Inclusive], 'exclusive' => [VatPriceMode::Exclusive]];
    }

    public function test_a_zero_rated_item_cannot_be_given_a_rate(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('always 0%');

        $this->calculate([new ZatcaInvoiceItem('Export', 1, 100, 15, ZatcaVatCategory::ZeroRated, 'VATEX-SA-32', 'Export of goods')]);
    }

    public function test_a_zero_rated_item_without_its_exemption_reason_is_refused_by_the_validator(): void
    {
        $data = $this->calculator()->calculate($this->input([new ZatcaInvoiceItem('Export', 1, 100, null, ZatcaVatCategory::ZeroRated)]));

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('BR-KSA-69');

        (new InvoiceDataValidator)->validate($data);
    }

    public function test_a_standard_item_at_zero_percent_is_refused_by_the_validator(): void
    {
        $data = $this->calculator()->calculate($this->input([new ZatcaInvoiceItem('Free', 1, 100, vatRate: 0)]));

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('ZeroRated');

        (new InvoiceDataValidator)->validate($data);
    }

    // ── discounts ───────────────────────────────────────────────────────────

    public function test_an_inclusive_bundle_discount_comes_off_what_the_customer_pays(): void
    {
        $data = $this->calculate(
            [new ZatcaInvoiceItem('Course A', 1, 120), new ZatcaInvoiceItem('Course B', 1, 200)],
            discounts: [new ZatcaInvoiceDiscount('Bundle discount', 32, poolKey: 'bundle:1', lineIndexes: [0, 1])],
        );

        $allowance = $data->allowanceCharges[0];
        $this->assertSame([27.83, 4.17, 15.0], [$allowance->amountExclVat, $allowance->vatAmount, $allowance->vatRate]);
        $this->assertSame(['bundle:1', [0, 1]], [$allowance->poolKey, $allowance->lineIndexes]);
        // 120 + 200 − 32 = 288.00 exactly.
        $this->assertTotals($data, 250.43, 37.57, 288.00);
    }

    public function test_an_exclusive_discount(): void
    {
        $data = $this->calculate(
            [new ZatcaInvoiceItem('Item', 1, 100)],
            new VatSettings(15, VatPriceMode::Exclusive),
            [new ZatcaInvoiceDiscount('Coupon', 10)],
        );

        $this->assertSame([10.0, 1.5], [$data->allowanceCharges[0]->amountExclVat, $data->allowanceCharges[0]->vatAmount]);
        $this->assertTotals($data, 90.00, 13.50, 103.50);
    }

    public function test_a_discount_on_zero_rated_lines_inherits_their_treatment(): void
    {
        $data = $this->calculate(
            [new ZatcaInvoiceItem('Headphones', 1, 115), ZatcaInvoiceItem::zeroRated('Export', 1, 200, 'VATEX-SA-32', 'Export of goods')],
            discounts: [new ZatcaInvoiceDiscount('Export discount', 20, lineIndexes: [1])],
        );

        $allowance = $data->allowanceCharges[0];
        $this->assertSame(ZatcaVatCategory::ZeroRated, $allowance->vatCategory);
        $this->assertSame([20.0, 0.0, 0.0, 'VATEX-SA-32'], [$allowance->amountExclVat, $allowance->vatAmount, $allowance->vatRate, $allowance->vatExemptionReasonCode]);
        $this->assertTotals($data, 280.00, 15.00, 295.00);
    }

    public function test_a_discount_over_mixed_rates_defaults_to_the_standard_rate(): void
    {
        $data = $this->calculate(
            [new ZatcaInvoiceItem('Standard', 1, 115), new ZatcaInvoiceItem('Reduced', 1, 105, vatRate: 5)],
            discounts: [new ZatcaInvoiceDiscount('Coupon', 23)],
        );

        $this->assertSame([ZatcaVatCategory::Standard, 15.0, 20.0, 3.0], [
            $data->allowanceCharges[0]->vatCategory,
            $data->allowanceCharges[0]->vatRate,
            $data->allowanceCharges[0]->amountExclVat,
            $data->allowanceCharges[0]->vatAmount,
        ]);
    }

    public function test_a_discount_with_an_explicit_rate(): void
    {
        $data = $this->calculate(
            [new ZatcaInvoiceItem('Reduced', 1, 210, vatRate: 5)],
            discounts: [new ZatcaInvoiceDiscount('Coupon', 21, vatRate: 5)],
        );

        $this->assertSame([5.0, 20.0, 1.0], [$data->allowanceCharges[0]->vatRate, $data->allowanceCharges[0]->amountExclVat, $data->allowanceCharges[0]->vatAmount]);
        $this->assertTotals($data, 180.00, 9.00, 189.00);
    }

    public function test_a_discount_covering_a_missing_item_is_refused(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('item index [3]');

        $this->calculate([new ZatcaInvoiceItem('Item', 1, 100)], discounts: [new ZatcaInvoiceDiscount('Coupon', 10, lineIndexes: [3])]);
    }

    // ── invalid input ───────────────────────────────────────────────────────

    /**
     * @dataProvider invalidItems
     */
    #[DataProvider('invalidItems')]
    public function test_invalid_items_are_refused(ZatcaInvoiceItem $item, string $message): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage($message);

        $this->calculator()->calculate($this->input([$item]));
    }

    public static function invalidItems(): array
    {
        return [
            'zero quantity' => [new ZatcaInvoiceItem('Item', 0, 100), 'at least 1'],
            'negative price' => [new ZatcaInvoiceItem('Item', 1, -5), 'negative price'],
            'non-numeric price' => [new ZatcaInvoiceItem('Item', 1, 'ten'), 'not a decimal'],
            'rate above 100' => [new ZatcaInvoiceItem('Item', 1, 100, vatRate: 101), 'between 0 and 100'],
            'rate with three decimals' => [new ZatcaInvoiceItem('Item', 1, 100, vatRate: '15.125'), 'BR-KSA-DEC-02'],
        ];
    }

    public function test_an_invoice_without_items_is_refused(): void
    {
        $this->expectException(ZatcaException::class);

        $this->calculator()->calculate($this->input([]));
    }

    public function test_an_invalid_configured_rate_stops_the_calculation(): void
    {
        Config::set('zatca.vat.percentage', 'fifteen');

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('ZATCA_VAT_PERCENTAGE');

        (new InvoiceCalculator)->calculate($this->input([new ZatcaInvoiceItem('Item', 1, 100)]));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function calculator(?VatSettings $settings = null): InvoiceCalculator
    {
        return new InvoiceCalculator($settings ?? new VatSettings(15, VatPriceMode::Inclusive));
    }

    /**
     * Calculates and runs the result through InvoiceDataValidator, which every
     * calculated invoice must pass before it can become a document.
     */
    private function calculate(array $items, ?VatSettings $settings = null, array $discounts = []): ZatcaInvoiceData
    {
        $data = $this->calculator($settings)->calculate($this->input($items, $discounts));

        (new InvoiceDataValidator)->validate($data);

        return $data;
    }

    private function input(array $items, array $discounts = [], ?VatPriceMode $priceMode = null): ZatcaInvoiceInput
    {
        return new ZatcaInvoiceInput(
            egsIdentifier: 'default',
            sourceReference: 'INV-VAT-1',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-10-14T10:00:00+03:00'),
            currency: 'SAR',
            items: $items,
            discounts: $discounts,
            priceMode: $priceMode,
        );
    }

    private function assertLine(ZatcaInvoiceData $data, int $index, float $net, float $vat, float $gross, float $unit, float $rate): void
    {
        $line = $data->lines[$index];

        $this->assertSame(
            ['net' => $net, 'vat' => $vat, 'gross' => $gross, 'unit' => $unit, 'rate' => $rate],
            ['net' => $line->lineTotalExclVat, 'vat' => $line->vatAmount, 'gross' => $line->lineTotalInclVat(), 'unit' => $line->netUnitPrice, 'rate' => $line->vatRate],
        );
    }

    private function assertTotals(ZatcaInvoiceData $data, float $excl, float $vat, float $incl): void
    {
        $this->assertSame(['excl' => $excl, 'vat' => $vat, 'incl' => $incl], ['excl' => $data->totalExclVat, 'vat' => $data->totalVat, 'incl' => $data->totalInclVat]);
    }
}
