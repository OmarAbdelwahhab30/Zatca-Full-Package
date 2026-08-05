<?php

namespace Asas\Zatca\Services\Security;

/**
 * Single shared redaction point used by every place the package turns raw
 * SDK output, HTTP bodies, command arguments, or file paths into something
 * that might end up in an exception message, a log line, or a stored
 * diagnostic column. Nothing else in the package should hand-roll its own
 * truncation/redaction — route it through here so the guarantee is
 * enforced in one place instead of "each call site happened to be careful."
 */
class ZatcaSanitizer
{
    private const DEFAULT_MAX_LENGTH = 500;

    /**
     * Sanitizes raw SDK console output (stdout+stderr) before it is allowed
     * into an exception message or a log line. Strips anything that looks
     * like key/certificate/XML material and truncates the remainder.
     */
    public function sanitizeSdkOutput(string $output, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        return $this->truncate($this->redact($output), $maxLength);
    }

    /**
     * Sanitizes an HTTP response body (already decoded to an array) before
     * it is stored as a "sanitized" diagnostic column or logged. This is a
     * defensive second layer on top of connectors that already hand-pick
     * which fields to keep — it never trusts "the caller was careful" alone.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function sanitizeResponseBody(array $body): array
    {
        $sensitiveKeys = ['authorization', 'secret', 'binarysecuritytoken', 'token', 'privatekey', 'private_key', 'certificate', 'csr'];

        $clean = [];

        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitizeResponseBody($value) : (is_string($value) ? $this->redact($value) : $value);
        }

        return $clean;
    }

    /**
     * Sanitizes a CLI argument list for safe inclusion in an exception message.
     * Every argument in this package's SDK calls is a filesystem path or a
     * plain flag — never a secret — but absolute paths still reveal server
     * layout unnecessarily, so only the basename is kept.
     *
     * @param  array<string>  $args
     * @return array<string>
     */
    public function sanitizeCommandArgs(array $args): array
    {
        return array_map(function (string $arg) {
            if ($arg === '' || $arg[0] === '-') {
                return $arg; // flags like -sign, -invoice
            }

            return $this->looksLikePath($arg) ? basename($arg) : $arg;
        }, $args);
    }

    /** Redacts PEM-armored blocks, long base64 blobs, and Basic/Bearer auth headers found in free text. */
    private function redact(string $text): string
    {
        // PEM-armored certificate/key blocks (header, body, footer).
        $text = preg_replace('/-----BEGIN[^-]+-----.*?-----END[^-]+-----/s', '[REDACTED-PEM-BLOCK]', $text) ?? $text;

        // Authorization header values.
        $text = preg_replace('/(Authorization:\s*)(Basic|Bearer)\s+\S+/i', '$1$2 [REDACTED]', $text) ?? $text;

        // Long contiguous base64-looking runs (certs/keys/signed XML tend to be 200+ chars with no spaces).
        $text = preg_replace('/\b[A-Za-z0-9+\/]{200,}={0,2}\b/', '[REDACTED-BASE64-BLOB]', $text) ?? $text;

        return $text;
    }

    private function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength).'…[truncated]' : $text;
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '\\');
    }
}
