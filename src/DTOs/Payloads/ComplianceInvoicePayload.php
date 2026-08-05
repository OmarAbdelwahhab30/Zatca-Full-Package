<?php

namespace Zatca\DTOs\Payloads;

/** Result of POST /compliance/invoices */
class ComplianceInvoicePayload
{
    public function __construct(
        public readonly string $reportingStatus,
        /** @var array<string> */
        public readonly array $validationWarnings = [],
        /** @var array<string> */
        public readonly array $validationErrors = [],
    ) {}
}
