<?php

namespace Zatca\Tests\Feature\Characterization;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaInvoiceNeedsAttention;
use Zatca\Jobs\ClearZatcaInvoiceJob;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * The B2B counterpart of ReportingOutcomeCharacterizationTest: what one clearance attempt
 * does, end to end through the real HttpConnector and ResponseNormalizer, with only the
 * network replaced.
 *
 * The status a response maps to is what decides whether a credit/debit note may be
 * cancelled (only ClearanceRejected proves ZATCA did not take it) and whether a retry is
 * scheduled, so a 401 or a 5xx drifting into "rejected" would let a note ZATCA may still
 * hold be cancelled and re-issued.
 *
 * @group characterization
 */
#[Group('characterization')]
class ClearanceOutcomeCharacterizationTest extends TestCase
{
    use BuildsProductionInvoices;
    use CreatesInvoiceRows;

    /** @var array<int, array{request: \GuzzleHttp\Psr7\Request}> */
    private array $history = [];

    private MockHandler $zatca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();

        $this->zatca = new MockHandler;
        $stack = HandlerStack::create($this->zatca);
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));

        Event::fake([ZatcaInvoiceNeedsAttention::class]);
    }

    /** @dataProvider outcomes */
    #[DataProvider('outcomes')]
    public function test_clearance_outcome(int $httpStatus, string $body, int $priorAttempts, ZatcaInvoiceStatus $expected, bool $needsAttention): void
    {
        $invoice = $this->pendingClearance(['attempt_count' => $priorAttempts]);
        $this->zatca->append(new Response($httpStatus, ['Content-Type' => 'application/json'], $body));

        $this->clear($invoice);

        $invoice->refresh();
        $this->assertSame($expected, $invoice->status);
        $this->assertSame($priorAttempts + 1, $invoice->attempt_count);
        $this->assertSame($httpStatus, (int) ZatcaSubmissionAttempt::query()->sole()->http_status);

        $needsAttention
            ? Event::assertDispatched(ZatcaInvoiceNeedsAttention::class)
            : Event::assertNotDispatched(ZatcaInvoiceNeedsAttention::class);
    }

    public static function outcomes(): array
    {
        $validation = fn (string $status, string $warnings = '', string $errors = '') => '"validationResults":{"infoMessages":[],"warningMessages":['.$warnings.'],"errorMessages":['.$errors.'],"status":"'.$status.'"}';
        $cleared = '{"clearanceStatus":"CLEARED","clearedInvoice":"Q0xFQVJFRA==",'.$validation('PASS').'}';
        $clearedWithWarning = '{"clearanceStatus":"CLEARED","clearedInvoice":"Q0xFQVJFRA==",'.$validation('WARNING', '{"code":"BR-KSA-08","message":"a warning"}').'}';
        $notCleared = '{"clearanceStatus":"NOT_CLEARED",'.$validation('ERROR', '', '{"code":"BR-KSA-37","message":"a rule failed"}').'}';
        $serverError = '{"category":"HTTP-Errors","code":"500","message":"Something went wrong"}';
        $unauthorized = '{"timestamp":1654514661409,"status":401,"error":"Unauthorized","message":""}';

        return [
            '200 CLEARED → cleared' => [200, $cleared, 0, ZatcaInvoiceStatus::Cleared, false],
            '202 CLEARED with warning → cleared with warnings' => [202, $clearedWithWarning, 0, ZatcaInvoiceStatus::ClearedWithWarnings, false],
            '400 NOT_CLEARED → rejected' => [400, $notCleared, 0, ZatcaInvoiceStatus::ClearanceRejected, true],
            '401 → manual review, never rejected' => [401, $unauthorized, 0, ZatcaInvoiceStatus::ClearanceManualReview, true],
            '500 on attempt 1 → retrying' => [500, $serverError, 0, ZatcaInvoiceStatus::ClearanceRetrying, false],
            '500 on attempt 12 → manual review' => [500, $serverError, 11, ZatcaInvoiceStatus::ClearanceManualReview, true],
        ];
    }

    /** What goes over the wire for a Standard document, and what is kept from the answer. */
    public function test_a_cleared_document_goes_to_the_clearance_endpoint_and_keeps_zatcas_copy(): void
    {
        $invoice = $this->pendingClearance();
        $this->zatca->append(new Response(200, [], '{"clearanceStatus":"CLEARED","clearedInvoice":"Q0xFQVJFRA==","validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"}}'));

        $this->clear($invoice);

        $request = $this->history[0]['request'];
        $this->assertSame('https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/invoices/clearance/single', (string) $request->getUri());
        $this->assertSame('1', $request->getHeaderLine('Clearance-Status'));
        $this->assertSame('V2', $request->getHeaderLine('Accept-Version'));
        $this->assertStringStartsWith('Basic ', $request->getHeaderLine('Authorization'));

        $invoice->refresh();
        $this->assertSame('Q0xFQVJFRA==', $invoice->cleared_xml_base64);
        $this->assertSame('CLEARED', $invoice->clearance_status);
        $this->assertNotNull($invoice->cleared_at);
        $this->assertSame($invoice->invoice_hash, $invoice->cleared_invoice_hash);
    }

    /** Same guard as reporting: nothing is sent with an expired certificate. */
    public function test_an_expired_certificate_stops_clearance_before_any_request(): void
    {
        ZatcaEgsCredential::query()
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->update(['certificate_state' => ZatcaCertificateState::Expired->value]);
        $invoice = $this->pendingClearance();

        $this->clear($invoice);

        $this->assertCount(0, $this->history);
        $this->assertSame(ZatcaInvoiceStatus::PendingClearance, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->attempt_count);
        $this->assertSame(0, ZatcaSubmissionAttempt::query()->count());
    }

    private function pendingClearance(array $overrides = []): ZatcaInvoice
    {
        return $this->invoiceRow(ZatcaInvoiceStatus::PendingClearance, array_merge([
            'submission_model' => 'clearance',
            'document_type' => 'standard_invoice',
        ], $overrides));
    }

    private function clear(ZatcaInvoice $invoice): void
    {
        (new ClearZatcaInvoiceJob($invoice->id))->handle(app(ConnectorFactory::class));
    }
}
