<?php

namespace Zatca\Services\SDK\OutputParsers\V3_4_8;

use Zatca\DTOs\SdkValidationResult;
use Zatca\Exceptions\ZatcaSdkOutputChangedException;

/**
 * Parses `-validate` console output. Pinned to SDK 3.4.8's exact log format,
 * verified against real jar output:
 *
 *   [INFO] ValidationProcessorImpl - [XSD] validation result : PASSED
 *   [ERROR] ValidationProcessorImpl - CODE : sellerName, MESSAGE : seller name does not match...
 *   [WARN] ValidationProcessorImpl - CODE : BR-KSA-98, MESSAGE : [BR-KSA-98] - ...
 *   [INFO] InvoiceValidationService -  *** GLOBAL VALIDATION RESULT = FAILED
 */
class ValidationOutputParser
{
    public function parse(string $output): SdkValidationResult
    {
        if (! preg_match('/GLOBAL VALIDATION RESULT\s*=\s*(PASSED|FAILED)/i', $output, $globalMatch)) {
            throw ZatcaSdkOutputChangedException::unparsable('3.4.8', $output);
        }

        $checks = [];
        foreach (['XSD', 'EN', 'KSA', 'QR', 'SIGNATURE', 'PIH'] as $check) {
            if (preg_match('/\['.$check.'\]\s*validation result\s*:\s*(PASSED|FAILED)/i', $output, $m)) {
                $checks[$check] = strtoupper($m[1]) === 'PASSED';
            }
        }

        $errors = [];
        $warnings = [];

        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/\[(ERROR|WARN)\].*?CODE\s*:\s*(.+?),\s*MESSAGE\s*:\s*(.+)$/i', trim($line), $m)) {
                continue;
            }

            $entry = trim($m[2]).': '.trim($m[3]);

            strtoupper($m[1]) === 'ERROR' ? $errors[] = $entry : $warnings[] = $entry;
        }

        return new SdkValidationResult(
            valid: strtoupper($globalMatch[1]) === 'PASSED',
            checks: $checks,
            errors: $errors,
            warnings: $warnings,
        );
    }
}
