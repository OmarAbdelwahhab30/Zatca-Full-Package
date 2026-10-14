<?php

namespace Zatca\Services\Tax;

use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceDiscount;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\Money;
use Zatca\Support\VatSettings;

/**
 * Turns what your app supplies (items, quantities, prices) into the calculated
 * ZatcaInvoiceData the rest of the package works on. The only place VAT is calculated.
 *
 * Rounding policy (all in exact integer arithmetic, half-up):
 *
 *  1. Supplied prices keep up to six decimals.
 *  2. Line base = quantity × price, rounded to halalas. Calculated per LINE, never per
 *     unit, so ten items at 100.00 inclusive are 1000.00, not ten rounded unit nets.
 *  3. Exclusive: net = base; VAT = net × rate, rounded.
 *     Inclusive: gross = base; net = gross ÷ (1 + rate), rounded; VAT = gross − net. VAT
 *     is the remainder, so net + VAT is exactly what the customer paid. It can differ by
 *     at most one halala from net × rate, which ZATCA's line rules allow.
 *  4. Zero-rated and exempt: rate 0, VAT 0, net = base, whatever the mode.
 *  5. Net unit price (XML PriceAmount) = net ÷ quantity, to six decimals, so quantity ×
 *     price reproduces the line net (BR-KSA-EN16931-11).
 *  6. A discount is calculated like a one-unit line, in the same mode.
 *  7. Document totals are the sums of the rounded line and discount figures (BR-CO-10,
 *     BR-CO-14); total incl. VAT = total excl. VAT + total VAT (BR-CO-15).
 *
 * The result is then checked by InvoiceDataValidator before any ICV is spent.
 */
class InvoiceCalculator
{
    /** @param  VatSettings|null  $settings  fixed settings (tests); null reads config('zatca.vat') on each call. */
    public function __construct(private readonly ?VatSettings $settings = null) {}

    public function settings(): VatSettings
    {
        return $this->settings ?? VatSettings::fromConfig();
    }

    /** @throws ZatcaException when an item, discount or the VAT configuration is invalid. */
    public function calculate(ZatcaInvoiceInput $input): ZatcaInvoiceData
    {
        $settings = $this->settings();
        $mode = $input->priceMode ?? $settings->mode;

        if ($input->items === []) {
            throw new ZatcaException("[ZATCA] Invoice [{$input->sourceReference}] has no items, so there is nothing to invoice.");
        }

        $lines = [];

        foreach (array_values($input->items) as $position => $item) {
            if (! $item instanceof ZatcaInvoiceItem) {
                throw new ZatcaException("[ZATCA] Invoice [{$input->sourceReference}] item ".($position + 1).' is not a ZatcaInvoiceItem.');
            }

            $lines[] = $this->line($item, $mode, $settings, "Invoice [{$input->sourceReference}] item ".($position + 1));
        }

        $allowances = [];

        foreach (array_values($input->discounts) as $position => $discount) {
            if (! $discount instanceof ZatcaInvoiceDiscount) {
                throw new ZatcaException("[ZATCA] Invoice [{$input->sourceReference}] discount ".($position + 1).' is not a ZatcaInvoiceDiscount.');
            }

            $allowances[] = $this->discount($discount, $lines, $mode, $settings, "Invoice [{$input->sourceReference}] discount ".($position + 1));
        }

        [$totalExclVat, $totalVat] = self::totals($lines, $allowances);

        return new ZatcaInvoiceData(
            egsIdentifier: $input->egsIdentifier,
            sourceReference: $input->sourceReference,
            buyerName: $input->buyerName,
            buyerVatNumber: $input->buyerVatNumber,
            issueDateTime: $input->issueDateTime,
            currency: $input->currency,
            lines: $lines,
            totalExclVat: Money::amount($totalExclVat),
            totalVat: Money::amount($totalVat),
            totalInclVat: Money::amount($totalExclVat + $totalVat),
            documentType: $input->documentType,
            billingReferenceId: $input->billingReferenceId,
            adjustmentReason: $input->adjustmentReason,
            allowanceCharges: $allowances,
            buyer: $input->buyer,
            supplyDate: $input->supplyDate,
        );
    }

    /** One calculated line from one supplied item. */
    public function line(ZatcaInvoiceItem $item, ?VatPriceMode $mode = null, ?VatSettings $settings = null, string $where = 'Item'): ZatcaInvoiceLine
    {
        $settings ??= $this->settings();
        $mode ??= $settings->mode;

        if ($item->quantity < 1) {
            throw new ZatcaException("[ZATCA] {$where} has quantity {$item->quantity}; it must be at least 1.");
        }

        $priceMicro = Money::toScaled($item->price, Money::PRICE_SCALE, "{$where} price");

        if ($priceMicro < 0) {
            throw new ZatcaException("[ZATCA] {$where} has a negative price ({$item->price}).");
        }

        $basisPoints = $this->rateFor($item->vatCategory, $item->vatRate, $settings, $where);

        // quantity × price at six decimals, rounded once to halalas for the whole line.
        $base = Money::divide(Money::multiply($item->quantity, $priceMicro), 10_000);
        [$net, $vat] = self::split($base, $basisPoints, $mode);

        return new ZatcaInvoiceLine(
            description: $item->description,
            quantity: $item->quantity,
            netUnitPrice: self::netUnitPrice($net, $item->quantity),
            vatRate: Money::toFloat($basisPoints, 2),
            lineTotalExclVat: Money::amount($net),
            vatAmount: Money::amount($vat),
            vatCategory: $item->vatCategory,
            vatExemptionReasonCode: $item->vatExemptionReasonCode,
            vatExemptionReason: $item->vatExemptionReason,
            suppliedPrice: Money::trimmed($priceMicro, Money::PRICE_SCALE),
            priceMode: $mode,
        );
    }

    /**
     * Net and VAT, in halalas, for an amount in halalas at a rate in hundredths of a percent.
     *
     * @return array{0: int, 1: int} [net, vat]
     */
    public static function split(int $amount, int $basisPoints, VatPriceMode $mode): array
    {
        if ($basisPoints === 0) {
            return [$amount, 0];
        }

        if ($mode === VatPriceMode::Exclusive) {
            return [$amount, Money::divide(Money::multiply($amount, $basisPoints), 10_000)];
        }

        $net = Money::divide(Money::multiply($amount, 10_000), 10_000 + $basisPoints);

        return [$net, $amount - $net];
    }

    /** Net unit price for the XML: line net (halalas) ÷ quantity, to six decimals. */
    public static function netUnitPrice(int $netHalalas, int $quantity): float
    {
        return Money::toFloat(Money::divide(Money::multiply($netHalalas, 10_000), max(1, $quantity)), Money::PRICE_SCALE);
    }

    /**
     * @param  ZatcaInvoiceLine[]  $lines
     * @param  ZatcaAllowanceCharge[]  $allowances
     * @return array{0: int, 1: int} [total net, total VAT] in halalas
     */
    public static function totals(array $lines, array $allowances = []): array
    {
        $net = 0;
        $vat = 0;

        foreach ($lines as $line) {
            $net += Money::halalas($line->lineTotalExclVat);
            $vat += Money::halalas($line->vatAmount);
        }

        foreach ($allowances as $allowance) {
            $net -= Money::halalas($allowance->amountExclVat);
            $vat -= Money::halalas($allowance->vatAmount);
        }

        return [$net, $vat];
    }

    /**
     * Rate in hundredths of a percent for an item or discount of $category.
     *
     * @throws ZatcaException when a zero-rated/exempt entry is given a rate, or a rate is invalid.
     */
    public function rateFor(ZatcaVatCategory $category, int|float|string|null $rate, VatSettings $settings, string $where): int
    {
        if ($category !== ZatcaVatCategory::Standard) {
            if ($rate !== null && VatSettings::rateBasisPoints($rate, "{$where} VAT rate") !== 0) {
                throw new ZatcaException(
                    "[ZATCA] {$where} is VAT category {$category->value} and cannot have a {$rate}% rate. Zero-rated and exempt items are always 0%."
                );
            }

            return 0;
        }

        // A standard-rated rate of 0 is passed through on purpose: InvoiceDataValidator
        // refuses it with the message that points at zeroRated()/exempt().
        return $rate === null ? $settings->basisPoints() : VatSettings::rateBasisPoints($rate, "{$where} VAT rate");
    }

    /** @param  ZatcaInvoiceLine[]  $lines */
    private function discount(ZatcaInvoiceDiscount $discount, array $lines, VatPriceMode $mode, VatSettings $settings, string $where): ZatcaAllowanceCharge
    {
        foreach ($discount->lineIndexes as $index) {
            if (! is_int($index) || ! array_key_exists($index, $lines)) {
                throw new ZatcaException("[ZATCA] {$where} covers item index [{$index}], which does not exist.");
            }
        }

        $amount = Money::halalas($discount->amount, "{$where} amount");

        if ($amount < 0) {
            throw new ZatcaException("[ZATCA] {$where} is negative ({$discount->amount}).");
        }

        [$category, $rate, $code, $reason] = $this->discountTaxTreatment($discount, $lines);
        $basisPoints = $this->rateFor($category, $rate, $settings, $where);
        [$net, $vat] = self::split($amount, $basisPoints, $mode);

        return new ZatcaAllowanceCharge(
            reason: $discount->reason,
            amountExclVat: Money::amount($net),
            vatRate: Money::toFloat($basisPoints, 2),
            vatAmount: Money::amount($vat),
            poolKey: $discount->poolKey,
            lineIndexes: array_values($discount->lineIndexes),
            vatCategory: $category,
            vatExemptionReasonCode: $code,
            vatExemptionReason: $reason,
        );
    }

    /**
     * Explicit treatment when given; otherwise the one shared by every line the discount
     * covers; otherwise standard-rated at the default rate.
     *
     * @param  ZatcaInvoiceLine[]  $lines
     * @return array{0: ZatcaVatCategory, 1: int|float|string|null, 2: ?string, 3: ?string}
     */
    private function discountTaxTreatment(ZatcaInvoiceDiscount $discount, array $lines): array
    {
        if ($discount->vatCategory !== null) {
            return [$discount->vatCategory, $discount->vatRate, $discount->vatExemptionReasonCode, $discount->vatExemptionReason];
        }

        if ($discount->vatRate !== null) {
            return [ZatcaVatCategory::Standard, $discount->vatRate, null, null];
        }

        $covered = $discount->lineIndexes === []
            ? $lines
            : array_map(static fn (int $index) => $lines[$index], $discount->lineIndexes);

        $treatments = array_unique(array_map(
            static fn (ZatcaInvoiceLine $line) => $line->vatCategory->value.'|'.$line->vatRate.'|'.$line->vatExemptionReasonCode,
            $covered
        ));

        if (count($treatments) === 1) {
            $line = reset($covered);

            return [$line->vatCategory, $line->vatRate, $line->vatExemptionReasonCode, $line->vatExemptionReason];
        }

        return [ZatcaVatCategory::Standard, null, null, null];
    }
}
