<?php

namespace Zatca\Services\SDK\OutputParsers\V3_4_8;

class CsrOutputParser
{
    public function wasSuccessful(string $output): bool
    {
        return (bool) preg_match('/csr and private key have been generated successfully/i', $output);
    }
}
