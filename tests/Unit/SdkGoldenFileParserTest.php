<?php

namespace Zatca\Tests\Unit;

use Zatca\Exceptions\ZatcaSdkOutputChangedException;
use Zatca\Services\SDK\OutputParsers\V3_4_8\CsrOutputParser;
use Zatca\Services\SDK\OutputParsers\V3_4_8\HashOutputParser;
use Zatca\Services\SDK\OutputParsers\V3_4_8\ValidationOutputParser;
use Zatca\Services\SDK\SignedInvoiceXmlReader;
use Zatca\Tests\TestCase;

/**
 * Golden-file tests for every OutputParsers/V3_4_8 parser. Fixtures under
 * tests/Fixtures/SdkOutput/V3_4_8/ are sanitized copies of real SDK 3.4.8
 * console output captured during development (no real certificates,
 * private keys, VAT numbers, or invoice content — hash/QR values are
 * fake placeholders). If SDK 3.4.8's console format ever changes, these
 * fixtures stay pinned and the parser tests fail loudly instead of the
 * drift silently reaching production signing/validation.
 */
class SdkGoldenFileParserTest extends TestCase
{
    public function test_csr_output_parser_recognizes_success(): void
    {
        $parser = new CsrOutputParser;

        $this->assertTrue($parser->wasSuccessful($this->fixture('csr_success.txt')));
    }

    public function test_hash_output_parser_extracts_the_hash(): void
    {
        $parser = new HashOutputParser;

        $hash = $parser->parse($this->fixture('hash_success.txt'));

        $this->assertSame('SGVsbG9UaGlzSXNBRmFrZUhhc2hWYWx1ZUZvclRlc3Rpbmc=', $hash);
        $this->assertTrue($parser->wasSuccessful($this->fixture('hash_success.txt')));
    }

    public function test_hash_output_parser_extracts_the_hash_from_a_sign_operation_too(): void
    {
        $parser = new HashOutputParser;

        $hash = $parser->parse($this->fixture('sign_success.txt'));

        $this->assertSame('SGVsbG9UaGlzSXNBRmFrZUhhc2hWYWx1ZUZvclRlc3Rpbmc=', $hash);
    }

    public function test_hash_output_parser_throws_on_changed_format(): void
    {
        $this->expectException(ZatcaSdkOutputChangedException::class);

        (new HashOutputParser)->parse($this->fixture('hash_changed_format.txt'));
    }

    public function test_validation_output_parser_reads_a_full_pass(): void
    {
        $result = (new ValidationOutputParser)->parse($this->fixture('validate_pass.txt'));

        $this->assertTrue($result->valid);
        $this->assertSame([
            'XSD' => true,
            'EN' => true,
            'KSA' => true,
            'QR' => true,
            'SIGNATURE' => true,
            'PIH' => true,
        ], $result->checks);
        $this->assertEmpty($result->errors);
        $this->assertEmpty($result->warnings);
    }

    public function test_validation_output_parser_reads_warnings_and_errors_from_a_failure(): void
    {
        $result = (new ValidationOutputParser)->parse($this->fixture('validate_fail_with_warning.txt'));

        $this->assertFalse($result->valid);
        $this->assertFalse($result->checks['QR']);
        $this->assertFalse($result->checks['SIGNATURE']);
        $this->assertTrue($result->checks['PIH']);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('BR-KSA-98', $result->warnings[0]);
        $this->assertCount(4, $result->errors);
    }

    public function test_validation_output_parser_throws_on_changed_format(): void
    {
        $this->expectException(ZatcaSdkOutputChangedException::class);

        (new ValidationOutputParser)->parse($this->fixture('validate_changed_format.txt'));
    }

    public function test_qr_and_pih_extraction_from_a_signed_invoice(): void
    {
        $reader = new SignedInvoiceXmlReader;
        $xml = $this->fixture('signed_invoice_sample.xml');

        $this->assertSame('RkFLRVFSQ09ERVZBTFVFRk9SVEVTVElOR09OTFk=', $reader->extractQrCode($xml));
        $this->assertSame('RkFLRVBJSFZBTFVFRk9SVEVTVElOR09OTFk=', $reader->extractPih($xml));
    }

    public function test_pih_extraction_falls_back_to_genesis_when_missing(): void
    {
        $reader = new SignedInvoiceXmlReader;

        $this->assertSame(base64_encode('0'), $reader->extractPih('<Invoice xmlns:cac="x" xmlns:cbc="y"/>'));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/SdkOutput/V3_4_8/'.$name);
    }
}
