<?php

namespace Asas\Zatca\DTOs;

class SdkValidationResult
{
    public function __construct(
        public readonly bool $valid,
        /** @var array<string,bool> keyed by check name: XSD, EN, KSA, QR, SIGNATURE, PIH */
        public readonly array $checks,
        /** @var array<string> "CODE: MESSAGE" */
        public readonly array $errors,
        /** @var array<string> "CODE: MESSAGE" */
        public readonly array $warnings,
    ) {}
}
