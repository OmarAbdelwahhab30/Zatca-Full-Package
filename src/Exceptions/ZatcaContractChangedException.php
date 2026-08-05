<?php

namespace Asas\Zatca\Exceptions;

/**
 * Thrown when a ZATCA API response no longer matches the shape the pinned
 * Response Normalizer expects (new/renamed/missing keys). This must fail
 * loudly rather than silently coerce unknown data, since it usually means
 * ZATCA changed the contract for the configured api_version.
 */
class ZatcaContractChangedException extends ZatcaException
{
    public static function unexpectedShape(string $endpoint, array $payload): self
    {
        return new self(sprintf(
            'ZATCA response shape for [%s] no longer matches the pinned normalizer. Keys received: %s',
            $endpoint,
            implode(', ', array_keys($payload))
        ));
    }
}
