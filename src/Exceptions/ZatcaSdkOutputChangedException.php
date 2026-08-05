<?php

namespace Asas\Zatca\Exceptions;

use Asas\Zatca\Services\Security\ZatcaSanitizer;

/**
 * Thrown when the SDK console/file output no longer matches the pinned
 * Output Parser for the configured SDK version. Prevents a silent SDK
 * upgrade from being misread as a valid signing/validation result.
 */
class ZatcaSdkOutputChangedException extends ZatcaException
{
    public static function unparsable(string $sdkVersion, string $rawOutput): self
    {
        $sanitizer = new ZatcaSanitizer;

        return new self(sprintf(
            'SDK output for pinned version [%s] could not be parsed by OutputParsers/V%s. Sanitized output head: %s',
            $sdkVersion,
            str_replace('.', '_', $sdkVersion),
            $sanitizer->sanitizeSdkOutput($rawOutput, 500)
        ));
    }
}
