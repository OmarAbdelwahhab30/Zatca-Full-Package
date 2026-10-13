<?php

namespace Zatca\DTOs\Payloads;

/**
 * Result of POST /invoices/clearance/single (Facts Register F-CL-01/F-CL-09).
 * $clearedInvoiceBase64 is ZATCA's own re-signed copy of the document (with its
 * cryptographic stamp/QR embedded) — null on a rejection, where no cleared
 * document exists.
 */
class ClearancePayload
{
    public function __construct(
        public readonly ?string $clearanceStatus,
        public readonly ?string $clearedInvoiceBase64,
        /** @var array<string> */
        public readonly array $validationWarnings = [],
        /** @var array<string> */
        public readonly array $validationErrors = [],
    ) {}
}
