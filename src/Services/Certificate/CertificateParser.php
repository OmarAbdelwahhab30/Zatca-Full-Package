<?php

namespace Zatca\Services\Certificate;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Reads the validity window out of a ZATCA certificate.
 *
 * ZATCA returns the certificate as `binarySecurityToken`, which is base64 OF
 * the base64 certificate body — decoding once yields the body that PEM armor
 * wraps. Armoring the token directly (without that first decode) produces a
 * PEM file openssl refuses with "asn1 encoding routines::wrong tag", and the
 * expiry silently becomes NULL.
 *
 * That silence is the dangerous part: a credential with no
 * certificate_expires_at is skipped by CertificateLifecycleManager::check(),
 * so it never becomes ExpiringSoon or Expired, never fires
 * ZatcaCertificateExpiring, and never alerts anyone — until reporting starts
 * failing against an expired certificate.
 *
 * Several encodings are therefore tried in order, and a failure to read the
 * dates is logged loudly rather than returned as a quiet null.
 */
class CertificateParser
{
    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} issued-at, expires-at
     */
    public function validity(string $certificate): array
    {
        $parsed = $this->parse($certificate);

        if (! $parsed) {
            Log::warning(
                '[ZATCA] Could not read validity dates from the certificate. '
                .'certificate_expires_at will be null, which means expiry monitoring '
                .'(zatca:certificate:check, zatca:status) cannot warn before it expires.'
            );

            return [null, null];
        }

        return [
            isset($parsed['validFrom_time_t']) ? (new DateTimeImmutable)->setTimestamp($parsed['validFrom_time_t']) : null,
            isset($parsed['validTo_time_t']) ? (new DateTimeImmutable)->setTimestamp($parsed['validTo_time_t']) : null,
        ];
    }

    /** @return array<string, mixed>|false */
    private function parse(string $certificate)
    {
        foreach ($this->candidates($certificate) as $candidate) {
            $parsed = @openssl_x509_parse($candidate);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        // Don't leave openssl's error queue populated for the next caller.
        while (openssl_error_string() !== false) {
            continue;
        }

        return false;
    }

    /**
     * Every shape the certificate has been observed in, cheapest first.
     *
     * @return array<int, string>
     */
    private function candidates(string $certificate): array
    {
        $certificate = trim($certificate);
        $candidates = [];

        // Already a PEM file.
        if (str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $candidates[] = $certificate;
        }

        // The ZATCA case: base64 wrapping the real base64 body.
        $decoded = base64_decode($certificate, true);

        if (is_string($decoded) && $decoded !== '') {
            $candidates[] = str_contains($decoded, 'BEGIN CERTIFICATE')
                ? $decoded
                : $this->armor($decoded);
        }

        // Raw base64 body with no armor and no outer encoding.
        $candidates[] = $this->armor($certificate);

        return $candidates;
    }

    private function armor(string $body): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(trim($body), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
