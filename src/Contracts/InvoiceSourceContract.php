<?php

namespace Zatca\Contracts;

use Zatca\DTOs\ZatcaInvoiceInput;

/**
 * The key reusability seam. Every consuming app has a different Order/domain
 * model; the host app implements this to describe its own paid order as a
 * ZatcaInvoiceInput — items, quantities and prices, keyed by egsIdentifier.
 *
 * No VAT arithmetic belongs here: the package calculates net, VAT and totals from
 * the prices (InvoiceCalculator, using config('zatca.vat')).
 */
interface InvoiceSourceContract
{
    /**
     * @param  mixed  $source  host-defined identifier or model (e.g. an Order id or instance)
     */
    public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput;

    /** Idempotency key derived from the host source (e.g. "order:{$id}"). */
    public function sourceReference(mixed $source): string;
}
