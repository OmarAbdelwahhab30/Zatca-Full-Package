<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaVatCategory;

/**
 * A document-level `cac:AllowanceCharge` (ChargeIndicator=false — this package
 * only ever emits allowances, never charges). Reduces the document totals
 * without altering any individual line, which is why it lives on
 * ZatcaInvoiceData rather than on a ZatcaInvoiceLine: the lines keep
 * describing what was sold, this describes a reduction applied to the
 * document as a whole (e.g. a bundle discount spread across several lines).
 *
 * poolKey/lineIndexes carry WHICH lines the allowance belongs to. They never
 * reach the XML — ZATCA has no field for them — but they are frozen into the
 * snapshot, because a later Credit Note has to know that this 32.00 belongs to
 * lines 0 and 1 and not to the unrelated item on line 2. Recovering that from
 * the host's Transaction rows afterwards works for a single allowance and
 * becomes guesswork with two, so it is recorded at signing time instead.
 */
class ZatcaAllowanceCharge
{
    /**
     * @param  string|null  $poolKey  opaque host-assigned identity for the group of lines
     *   this allowance was calculated over, e.g. "bundle:7". Null on allowances written
     *   before this field existed.
     * @param  int[]  $lineIndexes  zero-based indexes into ZatcaInvoiceData::$lines that
     *   this allowance was calculated over. Empty when not recorded.
     */
    public function __construct(
        public readonly string $reason,
        public readonly float $amountExclVat,
        public readonly float $vatRate,
        public readonly float $vatAmount,
        public readonly ?string $poolKey = null,
        public readonly array $lineIndexes = [],
        public readonly ZatcaVatCategory $vatCategory = ZatcaVatCategory::Standard,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
    ) {}

    public function amountInclVat(): float
    {
        return round($this->amountExclVat + $this->vatAmount, 2);
    }
}
