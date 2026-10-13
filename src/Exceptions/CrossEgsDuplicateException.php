<?php

namespace Zatca\Exceptions;

/**
 * One sale, one tax document: a source reference already has a document on another EGS
 * in the same environment (for example a sale invoiced as B2C, then offered to the B2B
 * EGS). Retrying cannot help and the other document is never returned in its place —
 * a person has to decide which document is the right one.
 */
class CrossEgsDuplicateException extends ZatcaException
{
    public static function for(string $sourceReference, string $environment, string $requestedEgs, string $existingEgs): self
    {
        return new self(sprintf(
            '[%s] in [%s] already has a ZATCA document on EGS [%s]; refusing to create a second one on EGS [%s].',
            $sourceReference,
            $environment,
            $existingEgs,
            $requestedEgs,
        ));
    }
}
