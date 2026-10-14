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
        /**
         * Set for a Standard (B2B) compliance document, which ZATCA clears rather than
         * reports: the sandbox answers `"reportingStatus": null, "clearanceStatus": "CLEARED"`.
         */
        public readonly ?string $clearanceStatus = null,
    ) {}

    /** What ZATCA said about the document: CLEARED for a Standard one, REPORTED for a Simplified one. */
    public function outcome(): ?string
    {
        foreach ([$this->clearanceStatus, $this->reportingStatus] as $status) {
            if ($status !== null && $status !== '') {
                return $status;
            }
        }

        return null;
    }
}
