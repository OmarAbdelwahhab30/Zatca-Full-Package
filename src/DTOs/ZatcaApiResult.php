<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaResponseStatus;

/**
 * Shared shape for every ZATCA API response. Concrete payload DTOs
 * (Payloads/ComplianceCsidPayload, Payloads\ReportingPayload, ...) carry the
 * per-endpoint typed fields; this base carries what's common across all of
 * them so response handling code doesn't duplicate status/http/raw plumbing.
 */
class ZatcaApiResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ZatcaResponseStatus $status,
        public readonly int $httpStatus,
        public readonly ?string $requestId,
        /** Sanitized response body — secrets/tokens already stripped before storage. */
        public readonly array $sanitizedResponse,
        /** @var array<string> */
        public readonly array $warnings = [],
        /** @var array<string> */
        public readonly array $errors = [],
        public readonly mixed $payload = null,
    ) {}

    public function withPayload(mixed $payload): self
    {
        return new self(
            $this->successful,
            $this->status,
            $this->httpStatus,
            $this->requestId,
            $this->sanitizedResponse,
            $this->warnings,
            $this->errors,
            $payload
        );
    }
}
