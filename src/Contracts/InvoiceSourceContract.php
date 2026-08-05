<?php

namespace Zatca\Contracts;

use Zatca\DTOs\ZatcaInvoiceData;

/**
 * The key reusability seam. Every consuming app has a different Order/domain
 * model; the host app implements this to map its own paid-order object into
 * the package's neutral ZatcaInvoiceData, keyed by egsIdentifier.
 */
interface InvoiceSourceContract
{
    /**
     * @param  mixed  $source  host-defined identifier or model (e.g. an Order id or instance)
     */
    public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData;

    /** Idempotency key derived from the host source (e.g. "order:{$id}"). */
    public function sourceReference(mixed $source): string;
}
