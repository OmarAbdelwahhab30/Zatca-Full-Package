<?php

namespace Zatca\Services\CreditNote;

/**
 * One item's share of a price adjustment Credit Note: which item, how much comes
 * off it, and the line text that reaches the PDF and the XML.
 *
 * A note may carry several of these — one per item being corrected. Each is held to
 * its own item's cap and contributes its own line; nothing is pooled between them.
 *
 * `amount` is the difference in the price mode the item was invoiced in (VAT-inclusive
 * by default). The package splits it into net and VAT at the item's own frozen rate and
 * VAT category — the caller does no VAT arithmetic.
 */
class PriceAdjustmentInput
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly int|float|string $amount,
        public readonly string $description,
    ) {}
}
