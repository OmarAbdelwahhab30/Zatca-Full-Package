<?php

namespace Asas\Zatca\DTOs;

/** Result of atomically reserving the next ICV slot for one EGS/environment/state_type. */
class InvoiceSequenceReservation
{
    public function __construct(
        public readonly int $icv,
        public readonly string $previousInvoiceHash,
    ) {}
}
