<?php

namespace Asas\Zatca\Contracts;

use Asas\Zatca\DTOs\InvoiceSourceReference;

/**
 * The host app implements this to reload its own Order/domain record from
 * a scalar InvoiceSourceReference. The package never knows the host's Order
 * model — GenerateZatcaInvoiceJob only ever carries source_type/source_id
 * and asks this resolver to turn that back into a real record at execution
 * time (never at dispatch time), so the data used to build the invoice is
 * always fresh, and the queued job payload is always safely serializable.
 */
interface InvoiceSourceResolverContract
{
    /** @return mixed the host record, or null if it no longer exists (e.g. deleted) */
    public function resolve(InvoiceSourceReference $reference): mixed;
}
