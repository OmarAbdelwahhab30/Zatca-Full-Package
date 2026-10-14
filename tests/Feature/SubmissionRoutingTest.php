<?php

namespace Zatca\Tests\Feature;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\ClearZatcaInvoiceJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Jobs\RetryZatcaClearanceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Invoice\ComplianceTestInvoiceDataFactory;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\Feature\Characterization\CreatesInvoiceRows;
use Zatca\Tests\TestCase;

/**
 * A Standard (B2B) document is only ever cleared and a Simplified (B2C) one only ever
 * reported, whichever path sends it.
 */
class SubmissionRoutingTest extends TestCase
{
    use BuildsProductionInvoices;
    use CreatesInvoiceRows;

    public function test_a_signed_standard_document_waits_in_pending_clearance(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $standard = app(ProductionInvoiceOrchestrator::class)->generate($this->source(standard: true), (object) ['id' => 1], 'default');
        $simplified = app(ProductionInvoiceOrchestrator::class)->generate($this->source(standard: false), (object) ['id' => 2], 'default');

        $this->assertSame(ZatcaInvoiceStatus::PendingClearance, $standard->status);
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $simplified->status);
    }

    public function test_the_reporting_job_never_reports_a_clearance_document(): void
    {
        Bus::fake([RetryZatcaClearanceJob::class]);
        $this->seedFakeProductionCredentials();
        $row = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['submission_model' => 'clearance', 'document_type' => 'standard_invoice']);

        $this->connectorExpectingNoCalls();
        (new ReportZatcaInvoiceJob($row->id))->handle(app(ConnectorFactory::class));

        Bus::assertDispatched(RetryZatcaClearanceJob::class);
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $row->refresh()->status);
    }

    public function test_the_clearance_job_never_clears_a_reporting_document(): void
    {
        Bus::fake([RetryZatcaInvoiceJob::class]);
        $this->seedFakeProductionCredentials();
        $row = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);

        $this->connectorExpectingNoCalls();
        (new ClearZatcaInvoiceJob($row->id))->handle(app(ConnectorFactory::class));

        Bus::assertDispatched(RetryZatcaInvoiceJob::class);
    }

    public function test_requeue_never_resends_a_cleared_or_rejected_document(): void
    {
        Bus::fake();
        $cleared = $this->invoiceRow(ZatcaInvoiceStatus::Cleared, ['submission_model' => 'clearance']);
        $rejected = $this->invoiceRow(ZatcaInvoiceStatus::ClearanceRejected, ['submission_model' => 'clearance']);

        $this->artisan('zatca:requeue', ['--id' => [$cleared->id, $rejected->id]])->assertSuccessful();

        Bus::assertNothingDispatched();
        $this->assertSame(ZatcaInvoiceStatus::Cleared, $cleared->refresh()->status);
        $this->assertSame(ZatcaInvoiceStatus::ClearanceRejected, $rejected->refresh()->status);
    }

    public function test_requeue_sends_clearance_documents_back_to_clearance(): void
    {
        Bus::fake();
        $row = $this->invoiceRow(ZatcaInvoiceStatus::ClearanceManualReview, ['submission_model' => 'clearance', 'attempt_count' => 12]);

        $this->artisan('zatca:requeue', ['--status' => 'clearance_manual_review'])->assertSuccessful();

        Bus::assertDispatched(RetryZatcaClearanceJob::class);
        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
        $this->assertSame(ZatcaInvoiceStatus::ClearanceRetrying, $row->refresh()->status);
        $this->assertSame(0, $row->attempt_count);
    }

    public function test_a_connect_failure_during_clearance_is_recorded_as_in_doubt(): void
    {
        $this->seedFakeProductionCredentials();
        $zatca = new MockHandler([new ConnectException('cURL error 28: Connection timed out', new Request('POST', 'x'))]);
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create($zatca)]));
        $row = $this->invoiceRow(ZatcaInvoiceStatus::PendingClearance, ['submission_model' => 'clearance', 'document_type' => 'standard_invoice']);

        (new ClearZatcaInvoiceJob($row->id))->handle(app(ConnectorFactory::class));

        $this->assertSame(ZatcaInvoiceStatus::ClearanceInDoubt, $row->refresh()->status);
        $this->assertSame(0, (int) ZatcaSubmissionAttempt::query()->sole()->http_status);
    }

    public function test_compliance_test_documents_get_unique_numbers(): void
    {
        $factory = app(ComplianceTestInvoiceDataFactory::class);

        $first = $factory->make('default', 'Item', ZatcaComplianceDocumentType::Invoice);
        $second = $factory->make('default', 'Item', ZatcaComplianceDocumentType::CreditNote);

        $this->assertNotSame($first->sourceReference, $second->sourceReference);
    }

    private function connectorExpectingNoCalls(): void
    {
        $connector = Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldNotReceive('reportInvoice');
        $connector->shouldNotReceive('clearInvoice');
        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));
    }

    private function source(bool $standard): InvoiceSourceContract
    {
        return new class($standard) implements InvoiceSourceContract
        {
            public function __construct(private readonly bool $standard) {}

            public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
            {
                return new ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Riyadh Office Supplies Co.',
                    buyerVatNumber: null,
                    issueDateTime: new DateTimeImmutable('2026-10-13T10:00:00+03:00'),
                    currency: 'SAR',
                    items: [new ZatcaInvoiceItem('Office chair', 1, 460.00)],
                    documentType: $this->standard ? ZatcaComplianceDocumentType::StandardInvoice : ZatcaComplianceDocumentType::Invoice,
                    buyer: $this->standard ? new BuyerParty('Riyadh Office Supplies Co.', '300000000000003', null, new BuyerAddress('Prince Sultan St', '4321', 'Al Rawdah', 'Jeddah', '23434')) : null,
                    supplyDate: $this->standard ? new DateTimeImmutable('2026-10-13') : null,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'INV-R-'.$source->id;
            }
        };
    }
}
