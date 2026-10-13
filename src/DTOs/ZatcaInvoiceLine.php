<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaVatCategory;

class ZatcaInvoiceLine
{
    /**
     * @param  ZatcaVatCategory  $vatCategory  S (default), or Z/E for zero-rated/exempt items.
     * @param  string|null  $vatExemptionReasonCode  ZATCA code, e.g. "VATEX-SA-32". Required for Z/E.
     * @param  string|null  $vatExemptionReason  the reason text that goes with the code. Required for Z/E.
     */
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $vatRate,
        public readonly float $lineTotalExclVat,
        public readonly float $vatAmount,
        public readonly ZatcaVatCategory $vatCategory = ZatcaVatCategory::Standard,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
    ) {}

    public function lineTotalInclVat(): float
    {
        return round($this->lineTotalExclVat + $this->vatAmount, 2);
    }

    /**
     * A copy with new amounts and everything else — including the VAT category and
     * exemption reason — kept. Used wherever a note restates part of a line.
     */
    public function withAmounts(int $quantity, float $unitPrice, float $lineTotalExclVat, float $vatAmount, ?string $description = null): self
    {
        return new self(
            description: $description ?? $this->description,
            quantity: $quantity,
            unitPrice: $unitPrice,
            vatRate: $this->vatRate,
            lineTotalExclVat: $lineTotalExclVat,
            vatAmount: $vatAmount,
            vatCategory: $this->vatCategory,
            vatExemptionReasonCode: $this->vatExemptionReasonCode,
            vatExemptionReason: $this->vatExemptionReason,
        );
    }
}
