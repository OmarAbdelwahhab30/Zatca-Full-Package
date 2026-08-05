<?php

namespace Zatca\Tests\Unit;

use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Services\Connectors\V2\ResponseNormalizer;
use Zatca\Tests\TestCase;
use GuzzleHttp\Psr7\Response;

/** Fixtures copied verbatim from ZATCA's own Sandbox Swagger docs — not invented shapes. */
class ResponseNormalizerTest extends TestCase
{
    private ResponseNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ResponseNormalizer;
    }

    public function test_it_normalizes_a_successful_compliance_csid_response(): void
    {
        $body = '{"requestID":1234567890123,"dispositionMessage":"ISSUED","binarySecurityToken":"TUlJQ1BUQ0NBZU9n","secret":"Dehvg1fc8GF6Jwt5bOxXwC6enR93VxeNEo2mlUatfgw="}';
        $result = $this->normalizer->normalizeComplianceCsid(new Response(200, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame('1234567890123', $result->payload->requestId);
    }

    public function test_it_normalizes_an_invalid_otp_error(): void
    {
        $body = '{"errors":[{"code":"Invalid-OTP","message":"The provided OTP is invalid"}]}';
        $result = $this->normalizer->normalizeComplianceCsid(new Response(400, [], $body));

        $this->assertFalse($result->successful);
        $this->assertSame(ZatcaResponseStatus::Error, $result->status);
    }

    public function test_it_normalizes_a_passed_reporting_response(): void
    {
        $body = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}';
        $result = $this->normalizer->normalizeReporting(new Response(200, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame(ZatcaResponseStatus::Pass, $result->status);
    }

    public function test_it_treats_409_as_success_only_when_body_confirms_already_reported(): void
    {
        $body = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"message":"Invoice was already Reported successfully earlier."}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}';
        $result = $this->normalizer->normalizeReporting(new Response(409, [], $body));

        $this->assertTrue($result->successful);
        $this->assertTrue($result->sanitizedResponse['alreadyReported']);
    }

    public function test_it_does_not_treat_an_unrelated_409_as_success(): void
    {
        $body = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"message":"A different conflict."}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}';
        $result = $this->normalizer->normalizeReporting(new Response(409, [], $body));

        $this->assertFalse($result->successful);
    }

    public function test_it_handles_401_without_throwing(): void
    {
        $body = '{"timestamp":1654514661409,"status":401,"error":"Unauthorized","message":""}';
        $result = $this->normalizer->normalizeReporting(new Response(401, [], $body));

        $this->assertFalse($result->successful);
        $this->assertSame(401, $result->httpStatus);
    }
}
