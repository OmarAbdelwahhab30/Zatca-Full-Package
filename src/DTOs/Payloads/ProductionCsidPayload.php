<?php

namespace Zatca\DTOs\Payloads;

/** Result of POST /production/csids */
class ProductionCsidPayload
{
    public function __construct(
        public readonly string $binarySecurityToken,
        public readonly string $secret,
    ) {}
}
