<?php

namespace Asas\Zatca\Exceptions;

use Asas\Zatca\Services\Security\ZatcaSanitizer;

class ZatcaSdkProcessException extends ZatcaException
{
    /**
     * @param  array<string>  $commandArgs  raw CLI args — sanitized before ever reaching the message
     */
    public static function nonZeroExit(array $commandArgs, int $exitCode, string $rawOutput): self
    {
        $sanitizer = new ZatcaSanitizer;

        return new self(sprintf(
            'ZATCA SDK process [%s] exited with code %d: %s',
            implode(' ', $sanitizer->sanitizeCommandArgs($commandArgs)),
            $exitCode,
            $sanitizer->sanitizeSdkOutput($rawOutput, 1000)
        ));
    }

    public static function timedOut(string $command, float $timeoutSeconds): self
    {
        return new self(sprintf('ZATCA SDK process [%s] timed out after %.1fs', $command, $timeoutSeconds));
    }

    public static function missingOutputFile(string $expectedPath): self
    {
        return new self(sprintf('ZATCA SDK process did not produce the expected output file [%s]', basename($expectedPath)));
    }
}
