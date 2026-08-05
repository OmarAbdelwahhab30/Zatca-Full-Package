<?php

namespace Asas\Zatca\Exceptions;

use Asas\Zatca\Enums\ZatcaCredentialType;

class ZatcaCredentialsMissingException extends ZatcaException
{
    public static function forEgs(string $egsIdentifier, ZatcaCredentialType $type): self
    {
        return new self(sprintf(
            'No [%s] ZATCA credentials found for EGS [%s]. Run the onboarding command for this step first.',
            $type->value,
            $egsIdentifier
        ));
    }
}
