<?php

namespace Zatca\DTOs\Payloads;

/** Result of POST /invoices/reporting/single */
class ReportingPayload
{
    public function __construct(
        public readonly string $reportingStatus,
        /** @var array<string> */
        public readonly array $validationWarnings = [],
        /** @var array<string> */
        public readonly array $validationErrors = [],
    ) {}
}
