<?php

namespace Asas\Zatca\DTOs\Payloads;

/** Result of POST /compliance */
class ComplianceCsidPayload
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $binarySecurityToken,
        public readonly string $secret,
    ) {}
}
