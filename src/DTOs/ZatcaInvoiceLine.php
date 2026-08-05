<?php

namespace Asas\Zatca\DTOs;

class ZatcaInvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $vatRate,
        public readonly float $lineTotalExclVat,
        public readonly float $vatAmount,
    ) {}

    public function lineTotalInclVat(): float
    {
        return round($this->lineTotalExclVat + $this->vatAmount, 2);
    }
}
