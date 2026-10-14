<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaVatCategory;

/**
 * A document-level discount, as your app states it. Becomes a ZatcaAllowanceCharge
 * (`cac:AllowanceCharge`, ChargeIndicator=false) once the package has calculated it.
 *
 * `amount` is in the invoice's price mode, like an item price: with inclusive prices a
 * 23.00 discount takes 23.00 off what the customer pays.
 *
 * Tax treatment: leave vatCategory and vatRate null and the discount takes the
 * category and rate of the lines it covers ($lineIndexes, or all lines) when those all
 * share one; otherwise it is standard-rated at the configured rate. Set them explicitly
 * on a mixed-rate invoice.
 */
class ZatcaInvoiceDiscount
{
    /**
     * @param  string|null  $poolKey  your own identity for the group of lines this discount was
     *   calculated over, e.g. "bundle:7". Frozen in the snapshot for credit notes; never in the XML.
     * @param  int[]  $lineIndexes  zero-based indexes of the items this discount covers. Lets a
     *   later credit note give back the right share of it.
     */
    public function __construct(
        public readonly string $reason,
        public readonly int|float|string $amount,
        public readonly int|float|string|null $vatRate = null,
        public readonly ?ZatcaVatCategory $vatCategory = null,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
        public readonly ?string $poolKey = null,
        public readonly array $lineIndexes = [],
    ) {}
}
