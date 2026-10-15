<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaDebitNoteDisplayStatus;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaDebitNoteAccepted;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Jobs\GenerateZatcaDebitNoteJob;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\DebitNote\DebitNoteCanceller;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\DebitNote\DebitNoteStatusResolver;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Cancellation, derived status, and the one fact the whole feature hangs on:
 * acceptance is written exactly once, means ZATCA accepted the DOCUMENT, and fires
 * the host-facing event — and never implies the money was collected.
 */
class DebitNoteLifecycleTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([GenerateZatcaDebitNoteJob::class, \Zatca\Jobs\ReportZatcaInvoiceJob::class]);
    }

    public function test_a_never_generated_note_can_be_cancelled(): void
    {
        $debitNote = $this->issuedNote(9101);

        $this->assertTrue(app(DebitNoteCanceller::class)->isCancellable($debitNote));

        $cancelled = app(DebitNoteCanceller::class)->cancel($debitNote);

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(ZatcaDebitNoteDisplayStatus::Cancelled, app(DebitNoteStatusResolver::class)->resolve($cancelled));
        $this->assertNotNull($cancelled->events()->where('event', 'cancelled')->first());
    }

    public function test_an_accepted_note_can_never_be_cancelled(): void
    {
        $debitNote = $this->issuedNote(9102);
        $debitNote->update(['accepted_at' => now()]);

        $this->assertFalse(app(DebitNoteCanceller::class)->isCancellable($debitNote->fresh()));

        $this->expectException(DebitNoteException::class);
        app(DebitNoteCanceller::class)->cancel($debitNote->fresh());
    }

    /**
     * A transient failure leaves it unknown whether ZATCA accepted the document, so
     * cancelling is refused — the same protection Credit Notes have, exercised the
     * same way CreditNoteCancellerTest exercises it (HTTP 0, a failed trip).
     */
    public function test_a_transiently_failed_note_cannot_be_cancelled(): void
    {
        [$debitNote, $document] = $this->generatedNote(9103);

        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 0,
            'error_message' => 'network error',
            'attempted_at' => now(),
        ]);

        $this->assertFalse(app(DebitNoteCanceller::class)->isCancellable($debitNote->fresh()));
        $this->assertSame(
            ZatcaDebitNoteDisplayStatus::Failed,
            app(DebitNoteStatusResolver::class)->resolve($debitNote->fresh())
        );
    }

    public function test_a_definitely_rejected_note_can_be_cancelled_and_reads_as_rejected(): void
    {
        [$debitNote, $document] = $this->generatedNote(9104);

        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 400,
            'attempted_at' => now(),
        ]);

        $this->assertSame(
            ZatcaDebitNoteDisplayStatus::Rejected,
            app(DebitNoteStatusResolver::class)->resolve($debitNote->fresh())
        );
        $this->assertTrue(app(DebitNoteCanceller::class)->isCancellable($debitNote->fresh()));
    }

    /**
     * The acceptance contract: reporting success writes accepted_at, announces it once,
     * and — the rule the entire Additional Item flow depends on — says nothing at all
     * about payment.
     */
    public function test_reporting_success_accepts_the_note_once_and_announces_it(): void
    {
        Event::fake([ZatcaDebitNoteAccepted::class]);

        [$debitNote, $document] = $this->generatedNote(9105);

        $this->reportSuccessfully($document);

        $debitNote = $debitNote->fresh();
        $this->assertNotNull($debitNote->accepted_at);
        $this->assertSame(ZatcaDebitNoteDisplayStatus::Accepted, app(DebitNoteStatusResolver::class)->resolve($debitNote));
        Event::assertDispatchedTimes(ZatcaDebitNoteAccepted::class, 1);
    }

    /** A cancelled note is skipped by its generation job rather than producing a document. */
    public function test_generation_is_skipped_for_a_cancelled_note(): void
    {
        $debitNote = $this->issuedNote(9106);
        app(DebitNoteCanceller::class)->cancel($debitNote);

        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        (new GenerateZatcaDebitNoteJob($debitNote->id))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(\Zatca\Support\ZatcaGate::class),
        );

        $this->assertSame(0, ZatcaInvoice::where('debit_note_id', $debitNote->id)->count());
    }

    /** Runs the real reporting trait against a scripted PASS response. */
    private function reportSuccessfully(ZatcaInvoice $document): void
    {
        $result = new \Zatca\DTOs\ZatcaApiResult(
            successful: true,
            status: \Zatca\Enums\ZatcaResponseStatus::Pass,
            httpStatus: 200,
            requestId: null,
            sanitizedResponse: ['reportingStatus' => 'REPORTED'],
        );

        $connector = \Mockery::mock(\Zatca\Contracts\ZatcaConnectorInterface::class);
        $connector->shouldReceive('reportInvoice')->andReturn($result);

        $this->mock(\Zatca\Services\Connectors\ConnectorFactory::class, function ($mock) use ($connector) {
            $mock->shouldReceive('make')->andReturn($connector);
        });

        (new \Zatca\Jobs\ReportZatcaInvoiceJob($document->id))
            ->handle(app(\Zatca\Services\Connectors\ConnectorFactory::class));
    }

    private function issuedNote(int $sourceId): ZatcaDebitNote
    {
        $original = $this->reportedOriginal($sourceId);

        return app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalItem,
            [new DebitNoteLineInput('Extra item', 1, 230.00, itemId: 44)],
            'Additional item',
            (string) Str::uuid(),
        );
    }

    /** @return array{0: ZatcaDebitNote, 1: ZatcaInvoice} */
    private function generatedNote(int $sourceId): array
    {
        $debitNote = $this->issuedNote($sourceId);

        $document = app(ProductionInvoiceOrchestrator::class)->generateDebitNote(
            \Zatca\Support\InvoiceDataSnapshot::fromArray($debitNote->document_snapshot),
            $debitNote->egs_identifier,
            $debitNote->getKey(),
        );

        return [$debitNote->fresh(), $document->fresh()];
    }

    private function reportedOriginal(int $sourceId): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        return $invoice->fresh();
    }
}
