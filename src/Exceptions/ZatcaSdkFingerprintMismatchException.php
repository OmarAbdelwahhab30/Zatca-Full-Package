<?php

namespace Zatca\Exceptions;

class ZatcaSdkFingerprintMismatchException extends ZatcaException
{
    public static function mismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'ZATCA SDK binary fingerprint mismatch. Expected [%s], found [%s]. The SDK executable was replaced/updated without going through onboarding re-verification.',
            $expected,
            $actual
        ));
    }
}
