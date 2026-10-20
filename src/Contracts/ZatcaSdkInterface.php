<?php

namespace Zatca\Contracts;

use Zatca\Enums\ZatcaEnvironment;

/**
 * Wraps the ZATCA SDK CLI (Java jar / fatoora executable) so the rest of the
 * package never shells out directly. Enables test doubles for adapter tests.
 */
interface ZatcaSdkInterface
{
    /** @return array{csr: string, privateKey: string} absolute file paths */
    public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array;

    /** @return array{signedXmlBase64: string, invoiceHash: string, qrCode: string} the signed document is returned in memory; nothing is left on disk */
    public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array;

    public function generateHash(string $xmlPath): string;

    /** @return array{valid: bool, errors: array<string>, warnings: array<string>} */
    public function validateInvoice(string $signedXmlPath, string $certificatePath): array;

    public function version(): string;

    public function executablePath(): string;
}
