<?php

namespace Zatca\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaCreditNoteDisplayStatus;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaCreditNoteAccepted;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Jobs\GenerateZatcaCreditNoteJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteStatusResolver;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\ZatcaGate;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class GenerateZatcaCreditNoteJobTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([GenerateZatcaCreditNoteJob::class, ReportZatcaInvoiceJob::class]);
    }

    public function test_it_generates_and_signs_the_technical_document_then_queues_reporting(): void
    {
        $original = $this->reportedInvoice(6201);
        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());

        $this->assertSame(ZatcaCreditNoteDisplayStatus::Pending, app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh()));

        $this->runJob($creditNote->id);

        $document = ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail();
        $this->assertSame('pending_reporting', $document->status->value);
        Queue::assertPushed(ReportZatcaInvoiceJob::class, fn ($job) => true);

        $this->assertSame(ZatcaCreditNoteDisplayStatus::Processing, app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh()));
    }

    /** Scenario 14: on total generation failure, the note is marked so and its line stays reserved. */
    public function test_on_final_generation_failure_it_records_the_error_and_keeps_the_line_reserved(): void
    {
        // ONE scripted SDK bound before the orchestrator singleton is first resolved (the
        // orchestrator captures its dependency at construction — rebinding afterward would
        // have no effect). Sign attempt #1 is the original invoice (must succeed); attempt
        // #2 is this Credit Note's generation, which must fail.
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2]));

        $original = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 6202], self::EGS);
        $original->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        $creditNote = app(CreditNoteIssuer::class)->issue($original->fresh(), ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());

        try {
            $this->runJob($creditNote->id);
            $this->fail('Expected the scripted SDK to throw.');
        } catch (ZatcaSdkProcessException) {
            // expected — simulates the real queue worker catching it and exhausting retries
        }

        // failed() runs when the queue worker gives up — invoked directly here since the
        // job is faked rather than actually processed by a worker.
        (new GenerateZatcaCreditNoteJob($creditNote->id))->failed(new ZatcaSdkProcessException('simulated'));

        $fresh = $creditNote->fresh();
        $this->assertNotNull($fresh->generation_failed_at);
        $this->assertNotEmpty($fresh->last_error);

        $line = $fresh->lines()->first();
        $this->assertNotNull($line->active_line_key, 'A failed generation must not release the reserved line.');

        $this->assertSame(ZatcaCreditNoteDisplayStatus::GenerationFailed, app(CreditNoteStatusResolver::class)->resolve($fresh));
    }

    public function test_rejected_document_resolves_to_rejected_status(): void
    {
        $original = $this->reportedInvoice(6203);
        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());
        $this->runJob($creditNote->id);

        $document = ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail();
        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        \Zatca\Models\ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 400,
            'error_message' => 'rejected',
            'attempted_at' => now(),
        ]);

        $this->assertSame(ZatcaCreditNoteDisplayStatus::Rejected, app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh()));
    }

    public function test_accepted_note_resolves_to_accepted_regardless_of_document_state(): void
    {
        $original = $this->reportedInvoice(6204);
        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());
        $creditNote->update(['accepted_at' => now()]);

        $this->assertSame(ZatcaCreditNoteDisplayStatus::Accepted, app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh()));
    }

    /**
     * The B2C acceptance path end to end: a real reporting attempt through the real
     * connector. accepted_at is the only thing that makes a credit count and the event is
     * what host apps refund from, so it must be written once and announced once.
     */
    public function test_reporting_success_accepts_the_credit_note_once_and_announces_it(): void
    {
        Event::fake([ZatcaCreditNoteAccepted::class]);
        [$creditNote, $document] = $this->generatedCreditNote(6205);

        $this->zatca(new Response(200, [], '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[],"status":"PASS"},"reportingStatus":"REPORTED"}'));
        $this->report($document);

        $acceptedAt = $creditNote->fresh()->accepted_at;
        $this->assertNotNull($acceptedAt);
        $this->assertSame(ZatcaCreditNoteDisplayStatus::Accepted, app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh()));

        // A second job for the same, already reported document changes nothing.
        $this->report($document);

        $this->assertEquals($acceptedAt, $creditNote->fresh()->accepted_at);
        Event::assertDispatchedTimes(ZatcaCreditNoteAccepted::class, 1);
    }

    public function test_a_rejected_report_does_not_accept_the_credit_note(): void
    {
        Event::fake([ZatcaCreditNoteAccepted::class]);
        [$creditNote, $document] = $this->generatedCreditNote(6206);

        $this->zatca(new Response(400, [], '{"validationResults":{"infoMessages":[],"warningMessages":[],"errorMessages":[{"code":"BR-KSA-37","message":"a rule failed"}],"status":"ERROR"},"reportingStatus":"NOT_REPORTED"}'));
        $this->report($document);

        $this->assertNull($creditNote->fresh()->accepted_at);
        Event::assertNotDispatched(ZatcaCreditNoteAccepted::class);
    }

    /** @return array{0: \Zatca\Models\ZatcaCreditNote, 1: ZatcaInvoice} */
    private function generatedCreditNote(int $sourceId): array
    {
        $original = $this->reportedInvoice($sourceId);
        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid());
        $this->runJob($creditNote->id);

        return [$creditNote->fresh(), ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail()];
    }

    private function zatca(Response $response): void
    {
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
    }

    private function report(ZatcaInvoice $document): void
    {
        (new ReportZatcaInvoiceJob($document->id))->handle(app(ConnectorFactory::class));
    }

    private function runJob(int $creditNoteId): void
    {
        (new GenerateZatcaCreditNoteJob($creditNoteId))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(ZatcaGate::class)
        );
    }

    private function reportedInvoice(int $sourceId): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        return $invoice->fresh();
    }
}
