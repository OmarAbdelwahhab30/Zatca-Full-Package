<?php

namespace Zatca\Tests\Unit;

use Zatca\Services\Certificate\CertificateParser;
use Zatca\Tests\TestCase;

/**
 * ZATCA hands the certificate back as a binarySecurityToken: base64 wrapping
 * the base64 certificate body. Armoring that token directly produces a PEM
 * openssl rejects with "asn1 encoding routines::wrong tag", and the expiry
 * silently becomes null — which disables every expiry warning in the package.
 *
 * The fixture is ZATCA's own sample certificate, shipped publicly in the SDK
 * at Data/Certificates/cert.pem, stored in exactly the double-encoded form
 * ZATCA returns.
 */
class CertificateParserTest extends TestCase
{
    private CertificateParser $parser;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('openssl_x509_parse')) {
            $this->markTestSkipped('ext-openssl is not available.');
        }

        $this->parser = new CertificateParser;
        $this->token = trim(file_get_contents(__DIR__.'/../Fixtures/Certificates/zatca_sample_bst.txt'));
    }

    /** The shape that actually comes back from ZATCA — the one the old code got wrong. */
    public function test_it_reads_a_zatca_binary_security_token(): void
    {
        [$issuedAt, $expiresAt] = $this->parser->validity($this->token);

        $this->assertNotNull($expiresAt, 'A ZATCA binarySecurityToken must yield an expiry date.');
        $this->assertNotNull($issuedAt);
        $this->assertSame('2029-01-09', $expiresAt->format('Y-m-d'));
        $this->assertSame('2024-01-11', $issuedAt->format('Y-m-d'));
    }

    public function test_it_reads_a_plain_pem_certificate(): void
    {
        [, $expiresAt] = $this->parser->validity($this->pem());

        $this->assertSame('2029-01-09', $expiresAt?->format('Y-m-d'));
    }

    public function test_it_reads_an_unarmored_base64_body(): void
    {
        [, $expiresAt] = $this->parser->validity(base64_decode($this->token, true));

        $this->assertSame('2029-01-09', $expiresAt?->format('Y-m-d'));
    }

    public function test_it_reads_a_base64_wrapped_pem(): void
    {
        [, $expiresAt] = $this->parser->validity(base64_encode($this->pem()));

        $this->assertSame('2029-01-09', $expiresAt?->format('Y-m-d'));
    }

    public function test_it_normalizes_a_zatca_binary_security_token_for_sdk_signing(): void
    {
        $this->assertSame($this->pem(), $this->parser->normalizeForSdk($this->token));
    }

    public function test_it_normalizes_an_unarmored_base64_body_for_sdk_signing(): void
    {
        $body = base64_decode($this->token, true);

        $this->assertSame($this->pem(), $this->parser->normalizeForSdk($body));
    }

    public function test_it_leaves_a_pem_certificate_ready_for_sdk_signing(): void
    {
        $this->assertSame(trim($this->pem()), trim($this->parser->normalizeForSdk($this->pem())));
    }

    public function test_garbage_returns_nulls_rather_than_throwing(): void
    {
        [$issuedAt, $expiresAt] = $this->parser->validity('not a certificate at all');

        $this->assertNull($issuedAt);
        $this->assertNull($expiresAt);
    }

    public function test_an_empty_value_returns_nulls(): void
    {
        [, $expiresAt] = $this->parser->validity('');

        $this->assertNull($expiresAt);
    }

    /** A failed parse must not leave errors queued for whatever calls openssl next. */
    public function test_it_drains_the_openssl_error_queue_after_a_failure(): void
    {
        $this->parser->validity('not a certificate at all');

        $this->assertFalse(openssl_error_string());
    }

    private function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_decode($this->token, true), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
