<?php

namespace Zatca\Tests\Feature\Characterization;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaInvoiceNeedsAttention;
use Zatca\Exceptions\ZatcaContractChangedException;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Pins what one B2C reporting attempt does today, end to end through the real
 * HttpConnector and ResponseNormalizer, with only the network replaced.
 *
 * B2B adds a clearance path beside this one and a guard in front of it
 * (ReportsZatcaInvoice::report). Every outcome below must stay exactly as it is for
 * Simplified documents.
 *
 * These tests describe behaviour, they do not endorse it. In particular a Guzzle
 * ConnectException (connection refused, DNS failure, connect timeout) escapes the job
 * instead of being recorded — see the test named for it and blueprint Appendix D
 * (HT-xx, L-01). It is characterised here so that a fix is a deliberate change.
 *
 * @group characterization
 */
class ReportingOutcomeCharacterizationTest extends TestCase
{
    use BuildsProductionInvoices;
    use CreatesInvoiceRows;

    /** @var array<int, array{request: Request}> */
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

    /**
     * The package default (ZATCA_REPORTING_RETRY_ATTEMPTS). ReportsZatcaInvoice falls back
     * to 4 only when the key is missing from config entirely.
     */
    public function test_the_default_reporting_attempt_budget_is_twelve(): void
    {
        $this->assertSame(12, Config::get('zatca.retries.reporting.max_attempts'));
    }

    /** @dataProvider outcomes */
    public function test_reporting_outcome(int $httpStatus, string $body, int $priorAttempts, ZatcaInvoiceStatus $expected, string $attemptOutcome, bool $needsAttention): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['attempt_count' => $priorAttempts]);
        $this->zatca->append(new Response($httpStatus, ['Content-Type' => 'application/json'], $body));

        $this->report($invoice);

        $invoice->refresh();
        $this->assertSame($expected, $invoice->status);
        $this->assertSame($priorAttempts + 1, $invoice->attempt_count);
        $this->assertSame($expected->isTerminalSuccess(), $invoice->reported_at !== null);

        $attempt = ZatcaSubmissionAttempt::query()->where('zatca_invoice_id', $invoice->id)->sole();
        $this->assertSame($attemptOutcome, $attempt->outcome);
        $this->assertSame($httpStatus, (int) $attempt->http_status);
        $this->assertSame($priorAttempts + 1, (int) $attempt->attempt_number);
        $this->assertNull($attempt->triggered_by_type);

        $needsAttention
            ? Event::assertDispatched(ZatcaInvoiceNeedsAttention::class)
            : Event::assertNotDispatched(ZatcaInvoiceNeedsAttention::class);
    }

    public static function outcomes(): array
    {
        $pass = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}';
        $warning = '{"validationResults":{"infoMessages":[],"warningMessages":[{"code":"BR-KSA-08","message":"a warning"}],"errorMessages":[],"status":"WARNING"},"reportingStatus":"REPORTED"}';
        $error = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"code":"BR-KSA-37","message":"a rule failed"}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}';
        $alreadyReported = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"message":"Invoice was already Reported successfully earlier."}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}';
        $otherConflict = '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"message":"A different conflict."}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}';
        $serverError = '{"category":"HTTP-Errors","code":"500","message":"Something went wrong"}';
        $unauthorized = '{"timestamp":1654514661409,"status":401,"error":"Unauthorized","message":""}';

        return [
            '200 PASS → reported' => [200, $pass, 0, ZatcaInvoiceStatus::Reported, 'reported', false],
            '202 WARNING → reported with warnings' => [202, $warning, 0, ZatcaInvoiceStatus::ReportedWithWarnings, 'reported_with_warnings', false],
            '400 ERROR → manual review' => [400, $error, 0, ZatcaInvoiceStatus::ManualReview, 'failed', true],
            '409 confirmed already reported → reported' => [409, $alreadyReported, 0, ZatcaInvoiceStatus::Reported, 'reported', false],
            '409 other conflict → manual review' => [409, $otherConflict, 0, ZatcaInvoiceStatus::ManualReview, 'failed', true],
            '401 → manual review (not retried)' => [401, $unauthorized, 0, ZatcaInvoiceStatus::ManualReview, 'failed', true],
            '500 on attempt 1 → retrying' => [500, $serverError, 0, ZatcaInvoiceStatus::Retrying, 'failed', false],
            '500 on attempt 11 → retrying' => [500, $serverError, 10, ZatcaInvoiceStatus::Retrying, 'failed', false],
            '500 on attempt 12 → manual review' => [500, $serverError, 11, ZatcaInvoiceStatus::ManualReview, 'failed', true],
        ];
    }

    /** What goes over the wire for a Simplified document: reporting path, clearance off, V2. */
    public function test_the_request_is_a_reporting_call_with_clearance_off(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);
        $this->zatca->append(new Response(200, [], '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}'));

        $this->report($invoice);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/invoices/reporting/single', (string) $request->getUri());
        $this->assertSame('0', $request->getHeaderLine('Clearance-Status'));
        $this->assertSame('V2', $request->getHeaderLine('Accept-Version'));
        $this->assertStringStartsWith('Basic ', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['invoiceHash' => $invoice->invoice_hash, 'uuid' => $invoice->uuid, 'invoice' => $invoice->signed_xml_base64],
            json_decode((string) $request->getBody(), true)
        );
    }

    /**
     * Current behaviour (L-01): a connect-level failure is not a RequestException, so
     * HttpConnector does not catch it. The job throws, nothing is recorded and the
     * invoice keeps its status and counter; the next scheduled run tries again.
     */
    public function test_a_connect_exception_escapes_and_records_nothing(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['attempt_count' => 1]);
        $this->zatca->append(new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'x')));

        try {
            $this->report($invoice);
            $this->fail('A ConnectException used to escape the reporting job.');
        } catch (ConnectException) {
            // The documented current behaviour.
        }

        $invoice->refresh();
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $invoice->status);
        $this->assertSame(1, $invoice->attempt_count);
        $this->assertSame(0, ZatcaSubmissionAttempt::query()->count());
    }

    /** A request-level failure without a response is recorded as HTTP 0 and retried. */
    public function test_a_request_exception_without_a_response_is_retried_as_http_zero(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);
        $this->zatca->append(new RequestException('cURL error 28: timed out', new Request('POST', 'x')));

        $this->report($invoice);

        $invoice->refresh();
        $this->assertSame(ZatcaInvoiceStatus::Retrying, $invoice->status);
        $this->assertSame(0, (int) ZatcaSubmissionAttempt::query()->sole()->http_status);
    }

    /** A 2xx without the validation shape is treated as a contract change and thrown. */
    public function test_a_success_status_with_an_unknown_body_throws_and_records_nothing(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);
        $this->zatca->append(new Response(200, [], '{"something":"else"}'));

        try {
            $this->report($invoice);
            $this->fail('An unexpected 2xx body used to throw.');
        } catch (ZatcaContractChangedException) {
        }

        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $invoice->refresh()->status);
        $this->assertSame(0, ZatcaSubmissionAttempt::query()->count());
    }

    /** @dataProvider terminalStatuses */
    public function test_an_accepted_invoice_is_never_sent_again(ZatcaInvoiceStatus $status): void
    {
        $invoice = $this->invoiceRow($status);

        $this->report($invoice);

        $this->assertCount(0, $this->history);
        $this->assertSame(0, ZatcaSubmissionAttempt::query()->count());
    }

    public static function terminalStatuses(): array
    {
        return [
            'reported' => [ZatcaInvoiceStatus::Reported],
            'reported with warnings' => [ZatcaInvoiceStatus::ReportedWithWarnings],
        ];
    }

    /**
     * Only terminal success short-circuits. A document in manual review, or even a draft
     * row, is still sent when a job is dispatched for it — selection is the caller's job.
     *
     * Reporting statuses only. What the job does with a clearance status is not pinned:
     * that is exactly what the clearance guard (blueprint P36) changes.
     *
     * @dataProvider nonTerminalStatuses
     */
    public function test_any_non_terminal_status_is_sent_when_a_job_runs(ZatcaInvoiceStatus $status): void
    {
        $invoice = $this->invoiceRow($status);
        $this->zatca->append(new Response(200, [], '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}'));

        $this->report($invoice);

        $this->assertCount(1, $this->history);
        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->refresh()->status);
    }

    public static function nonTerminalStatuses(): array
    {
        return array_map(fn (ZatcaInvoiceStatus $s) => [$s], array_filter(
            ZatcaInvoiceStatus::cases(),
            fn (ZatcaInvoiceStatus $s) => ! $s->isTerminalSuccess() && ! $s->isClearanceState(),
        ));
    }

    private function report(ZatcaInvoice $invoice): void
    {
        (new ReportZatcaInvoiceJob($invoice->id))->handle(app(ConnectorFactory::class));
    }
}
