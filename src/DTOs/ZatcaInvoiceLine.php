<?php

namespace Zatca\DTOs;

use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaVatCategory;

/**
 * One CALCULATED invoice line: every amount the XML needs, already worked out.
 *
 * Your app does not build these. It supplies ZatcaInvoiceItem (price and quantity) and
 * InvoiceCalculator produces this. The package's validator, XML generators, snapshots,
 * regeneration and credit/debit notes all work on this calculated form.
 *
 * Two prices, deliberately kept apart:
 *  - $suppliedPrice: the unit price as your app gave it, in $priceMode. Audit only;
 *    never in the XML.
 *  - $netUnitPrice: the net (VAT-exclusive) unit price written to cac:Price/PriceAmount
 *    (BT-146). Kept to six decimals so that quantity × netUnitPrice equals the line
 *    net (BR-KSA-EN16931-11) even when VAT was taken out of an inclusive price.
 */
class ZatcaInvoiceLine
{
    /**
     * @param  ZatcaVatCategory  $vatCategory  S (default), or Z/E for zero-rated/exempt items.
     * @param  string|null  $vatExemptionReasonCode  ZATCA code, e.g. "VATEX-SA-32". Required for Z/E.
     * @param  string|null  $vatExemptionReason  the reason text that goes with the code. Required for Z/E.
     * @param  string|null  $suppliedPrice  the unit price as supplied, e.g. "115.00". Null on lines not
     *   built from a supplied price (snapshots written before it existed, restated note lines).
     * @param  VatPriceMode|null  $priceMode  what $suppliedPrice meant. Null when not recorded.
     */
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly float $netUnitPrice,
        public readonly float $vatRate,
        public readonly float $lineTotalExclVat,
        public readonly float $vatAmount,
        public readonly ZatcaVatCategory $vatCategory = ZatcaVatCategory::Standard,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
        public readonly ?string $suppliedPrice = null,
        public readonly ?VatPriceMode $priceMode = null,
    ) {}

    public function lineTotalInclVat(): float
    {
        return round($this->lineTotalExclVat + $this->vatAmount, 2);
    }

    /**
     * A copy with new amounts and everything else — including the VAT category, the
     * exemption reason and the price mode — kept. Used wherever a note restates part of
     * a line. The supplied price is dropped: the restated amounts no longer come from it.
     */
    public function withAmounts(int $quantity, float $netUnitPrice, float $lineTotalExclVat, float $vatAmount, ?string $description = null): self
    {
        return new self(
            description: $description ?? $this->description,
            quantity: $quantity,
            netUnitPrice: $netUnitPrice,
            vatRate: $this->vatRate,
            lineTotalExclVat: $lineTotalExclVat,
            vatAmount: $vatAmount,
            vatCategory: $this->vatCategory,
            vatExemptionReasonCode: $this->vatExemptionReasonCode,
            vatExemptionReason: $this->vatExemptionReason,
            suppliedPrice: null,
            priceMode: $this->priceMode,
        );
    }
}
