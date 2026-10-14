<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaVatCategory;

/**
 * One sold item, as your app prices it. The package calculates its net amount, VAT
 * and totals (InvoiceCalculator) — you do no VAT arithmetic.
 *
 * `price` is the unit price in the invoice's price mode (ZATCA_VAT_MODE, or the
 * invoice's own priceMode): VAT-inclusive by default. Up to six decimals are kept.
 *
 * Standard-rated items use ZATCA_VAT_PERCENTAGE unless `vatRate` is given. Zero-rated
 * and exempt items never get a rate; build them with zeroRated() / exempt(), which
 * require the ZATCA exemption reason (BR-KSA-69).
 */
class ZatcaInvoiceItem
{
    /**
     * @param  int|float|string|null  $vatRate  percentage for THIS item, e.g. 5; null = the configured default.
     *   Only for standard-rated items.
     */
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly int|float|string $price,
        public readonly int|float|string|null $vatRate = null,
        public readonly ZatcaVatCategory $vatCategory = ZatcaVatCategory::Standard,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
    ) {}

    /** Taxable at 0% (exports, qualifying medicines...), e.g. code "VATEX-SA-32". */
    public static function zeroRated(string $description, int $quantity, int|float|string $price, string $exemptionReasonCode, string $exemptionReason): self
    {
        return new self($description, $quantity, $price, null, ZatcaVatCategory::ZeroRated, $exemptionReasonCode, $exemptionReason);
    }

    /** Exempt from VAT (some financial services, residential rent...), e.g. code "VATEX-SA-29". */
    public static function exempt(string $description, int $quantity, int|float|string $price, string $exemptionReasonCode, string $exemptionReason): self
    {
        return new self($description, $quantity, $price, null, ZatcaVatCategory::Exempt, $exemptionReasonCode, $exemptionReason);
    }
}
