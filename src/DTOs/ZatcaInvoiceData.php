<?php

namespace Asas\Zatca\DTOs;

use DateTimeImmutable;

/**
 * Host-agnostic invoice input. Produced by the host app's InvoiceSourceContract
 * implementation from its own Order/domain model — the package never reads
 * the host's Order directly.
 */
class ZatcaInvoiceData
{
    public function __construct(
        public readonly string $egsIdentifier,
        /** Host-side identifier (e.g. order/transaction id) used for idempotency. */
        public readonly string $sourceReference,
        public readonly ?string $buyerName,
        public readonly ?string $buyerVatNumber,
        public readonly DateTimeImmutable $issueDateTime,
        public readonly string $currency,
        /** @var array<ZatcaInvoiceLine> */
        public readonly array $lines,
        public readonly float $totalExclVat,
        public readonly float $totalVat,
        public readonly float $totalInclVat,
    ) {}
}
