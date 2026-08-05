<?php

namespace Zatca\DTOs;

/**
 * A fully scalar, serialization-safe reference to a host-app record —
 * queued instead of the record itself. GenerateZatcaInvoiceJob carries only
 * this; the actual record is reloaded fresh at execution time through
 * InvoiceSourceResolverContract, so it can never go stale between dispatch
 * and execution, and never risks a queue serialization failure.
 */
class InvoiceSourceReference
{
    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $egsIdentifier = 'default',
    ) {}
}
