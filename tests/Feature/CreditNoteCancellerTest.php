<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Jobs\GenerateZatcaCreditNoteJob;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class CreditNoteCancellerTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([GenerateZatcaCreditNoteJob::class, \Zatca\Jobs\ReportZatcaInvoiceJob::class]);
    }

    public function test_a_never_generated_note_can_be_cancelled_and_releases_its_line(): void
    {
        $original = $this->reportedInvoice(6101);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );

        $this->assertTrue(app(CreditNoteCanceller::class)->isCancellable($creditNote));

        $cancelled = app(CreditNoteCanceller::class)->cancel($creditNote);

        $this->assertNotNull($cancelled->cancelled_at);

        $line = ZatcaCreditNoteLine::where('credit_note_id', $creditNote->id)->first();
        $this->assertNull($line->active_line_key);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame([0], $ledger->availableIndexes);
    }

    /** A 4xx rejection (not 401/403) proves ZATCA did not accept it — cancellable. */
    public function test_a_definitely_rejected_note_can_be_cancelled(): void
    {
        [$creditNote, $document] = $this->issuedAndGeneratedCreditNote(6102);

        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 400,
            'error_code' => 'BR-KSA-XX',
            'error_message' => 'rejected',
            'attempted_at' => now(),
        ]);

        $this->assertTrue(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));

        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());

        $this->assertNotNull($creditNote->fresh()->cancelled_at);
    }

    /** HTTP 0/5xx is ambiguous — ZATCA may have accepted it despite the failed trip. */
    public function test_a_transient_failure_cannot_be_cancelled(): void
    {
        [$creditNote, $document] = $this->issuedAndGeneratedCreditNote(6103);

        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 0,
            'error_message' => 'network error',
            'attempted_at' => now(),
        ]);

        $this->assertFalse(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));

        $this->expectException(CreditNoteException::class);
        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());
    }

    /** 401/403 rejects OUR CREDENTIALS, not the document — its fate is still unknown. */
    public function test_a_credentials_rejection_cannot_be_cancelled(): void
    {
        [$creditNote, $document] = $this->issuedAndGeneratedCreditNote(6104);

        $document->update(['status' => ZatcaInvoiceStatus::ManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 401,
            'error_message' => 'unauthorized',
            'attempted_at' => now(),
        ]);

        $this->assertFalse(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));
    }

    public function test_an_accepted_note_cannot_be_cancelled(): void
    {
        $original = $this->reportedInvoice(6105);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );
        $creditNote->update(['accepted_at' => now()]);

        $this->assertFalse(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));

        $this->expectException(CreditNoteException::class);
        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());
    }

    public function test_cancelling_twice_refuses_the_second_time(): void
    {
        $original = $this->reportedInvoice(6106);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );

        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());

        $this->expectException(CreditNoteException::class);
        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());
    }

    private function reportedInvoice(int $sourceId): \Zatca\Models\ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        return $invoice->fresh();
    }

    /** @return array{0: ZatcaCreditNote, 1: \Zatca\Models\ZatcaInvoice} */
    private function issuedAndGeneratedCreditNote(int $sourceId): array
    {
        $original = $this->reportedInvoice($sourceId);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );

        app(GenerateZatcaCreditNoteJob::class, ['creditNoteId' => $creditNote->id])
            ->handle(app(ProductionInvoiceOrchestrator::class), app(\Zatca\Support\ZatcaGate::class));

        $document = \Zatca\Models\ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail();

        return [$creditNote->fresh(), $document];
    }
}
