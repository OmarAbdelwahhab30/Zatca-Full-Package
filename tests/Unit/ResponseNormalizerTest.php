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

    /**
     * ZATCA accepts the invoice but attaches warnings (HTTP 202). This must stay a
     * SUCCESS — treating it as a failure would send a perfectly accepted invoice to
     * manual review, and retrying it risks a duplicate.
     */
    public function test_it_normalizes_a_reported_with_warnings_response(): void
    {
        $body = '{"validationResults":{"infoMessages":[],"warningMessages":[{"type":"WARNING","code":"BR-KSA-98","category":"KSA","message":"The simplified invoice should be submitted within 24 hours of issuing the invoice.","status":"WARNING"}],"errorMessages":[],"status":"WARNING"},"reportingStatus":"REPORTED"}';

        $result = $this->normalizer->normalizeReporting(new Response(202, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame(ZatcaResponseStatus::Warning, $result->status);
        $this->assertCount(1, $result->warnings);
        $this->assertSame([
            [
                'code' => 'BR-KSA-98',
                'message' => 'The simplified invoice should be submitted within 24 hours of issuing the invoice.',
            ],
        ], $result->sanitizedResponse['validationWarnings']);
    }

    public function test_it_preserves_every_validation_code_with_its_own_message(): void
    {
        $body = json_encode([
            'validationResults' => [
                'infoMessages' => [],
                'warningMessages' => [],
                'errorMessages' => [
                    ['code' => 'BR-CO-15', 'message' => 'Invoice total amount with VAT (BT-112) is invalid.'],
                    ['code' => 'BR-CO-14', 'message' => 'Invoice total VAT amount (BT-110) is invalid.'],
                ],
                'status' => 'ERROR',
            ],
            'reportingStatus' => 'NOT_REPORTED',
        ], JSON_THROW_ON_ERROR);

        $result = $this->normalizer->normalizeReporting(new Response(400, [], $body));

        $this->assertSame([
            ['code' => 'BR-CO-15', 'message' => 'Invoice total amount with VAT (BT-112) is invalid.'],
            ['code' => 'BR-CO-14', 'message' => 'Invoice total VAT amount (BT-110) is invalid.'],
        ], $result->sanitizedResponse['validationErrors']);
    }

    /**
     * A 4xx whose body does not match the documented shape must come back as a plain
     * error. ZatcaContractChangedException is reserved for unexpected 2xx shapes —
     * throwing it here would turn an ordinary conflict into a hard crash.
     */
    public function test_it_handles_a_409_with_a_changed_response_structure_as_a_plain_error(): void
    {
        $result = $this->normalizer->normalizeReporting(new Response(409, [], '{"message":"conflict"}'));

        $this->assertFalse($result->successful);
        $this->assertSame(409, $result->httpStatus);
    }

    /** Shape captured from the developer-portal sandbox on 2026-10-15 for a Standard compliance invoice. */
    public function test_a_standard_compliance_document_reports_its_clearance_outcome(): void
    {
        $body = '{"validationResults":{"infoMessages":[{"type":"INFO","code":"XSD_ZATCA_VALID","category":"XSD validation","message":"Complied with UBL 2.1 standards in line with ZATCA specifications","status":"PASS"}],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":null,"clearanceStatus":"CLEARED","qrSellertStatus":null,"qrBuyertStatus":null}';
        $result = $this->normalizer->normalizeComplianceInvoice(new Response(200, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame('CLEARED', $result->payload->clearanceStatus);
        $this->assertSame('CLEARED', $result->payload->outcome());
    }

    public function test_a_simplified_compliance_document_reports_its_reporting_outcome(): void
    {
        $body = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED","clearanceStatus":null,"qrSellertStatus":null,"qrBuyertStatus":null}';
        $result = $this->normalizer->normalizeComplianceInvoice(new Response(200, [], $body));

        $this->assertNull($result->payload->clearanceStatus);
        $this->assertSame('REPORTED', $result->payload->outcome());
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

    /** F-CL-01/F-CL-06/F-CL-09: 200 CLEARED, PASS. */
    public function test_it_normalizes_a_cleared_response(): void
    {
        $body = '{"clearanceStatus":"CLEARED","clearedInvoice":"QmFzZTY0","validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}';

        $result = $this->normalizer->normalizeClearance(new Response(200, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame(ZatcaResponseStatus::Pass, $result->status);
        $this->assertSame('CLEARED', $result->payload->clearanceStatus);
        $this->assertSame('QmFzZTY0', $result->payload->clearedInvoiceBase64);
    }

    /** F-CL-06: 202 CLEARED with a warning. */
    public function test_it_normalizes_a_cleared_with_warnings_response(): void
    {
        $body = '{"clearanceStatus":"CLEARED","clearedInvoice":"QmFzZTY0","validationResults":{"infoMessages":[],"warningMessages":[{"type":"WARNING","code":"BR-KSA-51","category":"KSA","message":"warning text","status":"WARNING"}],"errorMessages":[],"status":"WARNING"}}';

        $result = $this->normalizer->normalizeClearance(new Response(202, [], $body));

        $this->assertTrue($result->successful);
        $this->assertSame(ZatcaResponseStatus::Warning, $result->status);
        $this->assertSame(['warning text'], $result->payload->validationWarnings);
    }

    /**
     * F-CL-09: the clearance schema spells the field `erroMessages`. Until a live
     * sandbox response confirms which spelling is actually sent (G-04), both must
     * be readable — a document must never look error-free just because we read
     * the wrong key.
     */
    public function test_it_tolerates_the_documented_erromessages_typo(): void
    {
        $body = '{"clearanceStatus":"NOT_CLEARED","clearedInvoice":null,"validationResults":{"infoMessages":[],"warningMessages":[],"erroMessages":[{"type":"ERROR","code":"BR-KSA-06","category":"KSA","message":"rejected","status":"ERROR"}],"status":"ERROR"}}';

        $result = $this->normalizer->normalizeClearance(new Response(400, [], $body));

        $this->assertFalse($result->successful);
        $this->assertSame(['rejected'], $result->errors);
        $this->assertSame(['rejected'], $result->payload->validationErrors);
        $this->assertNull($result->payload->clearedInvoiceBase64);
    }

    public function test_it_handles_401_without_throwing(): void
    {
        $body = '{"timestamp":1654514661409,"status":401,"error":"Unauthorized","message":""}';
        $result = $this->normalizer->normalizeReporting(new Response(401, [], $body));

        $this->assertFalse($result->successful);
        $this->assertSame(401, $result->httpStatus);
    }
}
