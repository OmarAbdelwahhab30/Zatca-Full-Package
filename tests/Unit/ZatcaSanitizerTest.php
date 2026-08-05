<?php

namespace Zatca\Tests\Unit;

use Zatca\Exceptions\ZatcaSdkOutputChangedException;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Services\Security\ZatcaSanitizer;
use Zatca\Tests\TestCase;

class ZatcaSanitizerTest extends TestCase
{
    private const FAKE_PRIVATE_KEY_PEM = "-----BEGIN EC PRIVATE KEY-----\nFAKESECRETKEYMATERIALTHATMUSTNEVERAPPEARINLOGSFAKESECRETKEYMATERIAL\n-----END EC PRIVATE KEY-----";

    private const FAKE_CERT_PEM = "-----BEGIN CERTIFICATE-----\nFAKECERTIFICATEBODYTHATMUSTNEVERAPPEARINLOGSFAKECERTIFICATEBODY\n-----END CERTIFICATE-----";

    private const FAKE_TOKEN = 'FAKE-SECRET-TOKEN-abcdef0123456789';

    public function test_it_redacts_pem_armored_blocks(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $sanitized = $sanitizer->sanitizeSdkOutput('some output '.self::FAKE_PRIVATE_KEY_PEM.' more output');

        $this->assertStringNotContainsString('FAKESECRETKEYMATERIAL', $sanitized);
        $this->assertStringContainsString('[REDACTED-PEM-BLOCK]', $sanitized);
    }

    public function test_it_redacts_long_base64_blobs(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $blob = str_repeat('A', 250);
        $sanitized = $sanitizer->sanitizeSdkOutput("prefix {$blob} suffix");

        $this->assertStringNotContainsString($blob, $sanitized);
        $this->assertStringContainsString('[REDACTED-BASE64-BLOB]', $sanitized);
    }

    public function test_it_redacts_authorization_headers(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $sanitized = $sanitizer->sanitizeSdkOutput('Authorization: Basic '.base64_encode('user:'.self::FAKE_TOKEN));

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $sanitized);
    }

    public function test_it_truncates_long_output(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $sanitized = $sanitizer->sanitizeSdkOutput(str_repeat('word ', 500), 100);

        $this->assertLessThanOrEqual(120, mb_strlen($sanitized));
        $this->assertStringContainsString('[truncated]', $sanitized);
    }

    public function test_it_redacts_sensitive_response_body_keys(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $clean = $sanitizer->sanitizeResponseBody([
            'status' => 'PASS',
            'secret' => self::FAKE_TOKEN,
            'nested' => ['privateKey' => self::FAKE_PRIVATE_KEY_PEM],
        ]);

        $this->assertSame('[REDACTED]', $clean['secret']);
        $this->assertSame('[REDACTED]', $clean['nested']['privateKey']);
        $this->assertSame('PASS', $clean['status']);
    }

    public function test_it_reduces_command_arguments_to_basenames(): void
    {
        $sanitizer = new ZatcaSanitizer;
        $sanitized = $sanitizer->sanitizeCommandArgs(['-sign', '/private/storage/app/private/zatca/runtime/abc/unsigned.xml']);

        $this->assertSame(['-sign', 'unsigned.xml'], $sanitized);
    }

    public function test_sdk_process_exception_never_leaks_key_material_or_full_paths(): void
    {
        $exception = ZatcaSdkProcessException::nonZeroExit(['-sign', '/tmp/private/key.xml'], 1, 'crash '.self::FAKE_PRIVATE_KEY_PEM);

        $this->assertStringNotContainsString('FAKESECRETKEYMATERIAL', $exception->getMessage());
        $this->assertStringNotContainsString('/tmp/private/key.xml', $exception->getMessage());
    }

    public function test_sdk_output_changed_exception_never_leaks_certificate_material(): void
    {
        $exception = ZatcaSdkOutputChangedException::unparsable('3.4.8', 'unexpected '.self::FAKE_CERT_PEM);

        $this->assertStringNotContainsString('FAKECERTIFICATEBODY', $exception->getMessage());
    }
}
