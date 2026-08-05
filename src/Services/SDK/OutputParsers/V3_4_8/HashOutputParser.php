<?php

namespace Zatca\Services\SDK\OutputParsers\V3_4_8;

use Zatca\Exceptions\ZatcaSdkOutputChangedException;

/**
 * Parses console output of `-generateHash` / `-sign` (both services print
 * the same "*** INVOICE HASH = <base64>" line). Pinned to SDK 3.4.8's exact
 * log format — verified against real jar output, not guessed. A format
 * change on SDK upgrade must fail loudly via ZatcaSdkOutputChangedException,
 * not silently return an empty hash.
 */
class HashOutputParser
{
    public function parse(string $output): string
    {
        if (preg_match('/\*\*\*\s*INVOICE HASH\s*=\s*(\S+)/', $output, $matches) === 1) {
            return trim($matches[1]);
        }

        throw ZatcaSdkOutputChangedException::unparsable('3.4.8', $output);
    }

    public function wasSuccessful(string $output): bool
    {
        return (bool) preg_match('/has been (generated|signed) successfully/i', $output);
    }
}
