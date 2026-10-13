<?php

namespace Zatca\Services\CreditNote;

/**
 * One item's share of a price adjustment Credit Note: which item, how much comes
 * off it, and the line text that reaches the PDF and the XML.
 *
 * A note may carry several of these — one per item being corrected. Each is held to
 * its own item's cap and contributes its own line; nothing is pooled between them.
 *
 * The net/VAT split is the caller's, made under its own price tax policy at the adjusted
 * line's frozen rate, exactly as the single-item form always required.
 */
class PriceAdjustmentInput
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly float $netAmount,
        public readonly float $vatAmount,
        public readonly string $description,
    ) {}

    public function grossInclVat(): float
    {
        return round($this->netAmount + $this->vatAmount, 2);
    }
}
