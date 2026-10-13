<?php

namespace Zatca\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\Payloads\ClearancePayload;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Events\ZatcaCreditNoteAccepted;
use Zatca\Events\ZatcaDebitNoteAccepted;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Jobs\ClearZatcaInvoiceJob;
use Zatca\Jobs\GenerateZatcaCreditNoteJob;
use Zatca\Jobs\GenerateZatcaDebitNoteJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteStatusResolver;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\DebitNote\DebitNoteCanceller;
use Zatca\Services\DebitNote\DebitNoteEligibilityPolicy;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\DebitNote\DebitNoteStatusResolver;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * End-to-end proof that a Credit/Debit Note against a Standard (B2B) original is
 * itself Standard, cleared through the Clearance architecture, and carries the
 * ORIGINAL invoice's own frozen buyer — never re-resolved — plus the P42 clearance
 * status/cancellation lifecycle for both note kinds.
 *
 * Companion to ProductionCreditNoteGenerationTest/ProductionDebitNoteGenerationTest
 * and CreditNoteCancellerTest/DebitNoteLifecycleTest, which cover the Simplified
 * (Reporting) path this test must never change.
 */
class StandardNoteClearanceLifecycleTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            GenerateZatcaCreditNoteJob::class,
            GenerateZatcaDebitNoteJob::class,
            ReportZatcaInvoiceJob::class,
            ClearZatcaInvoiceJob::class,
        ]);
    }

    // ---- Credit Note ----------------------------------------------------

    public function test_a_credit_note_against_a_standard_original_is_itself_standard_with_the_inherited_buyer(): void
    {
        $original = $this->clearedStandardOriginal(7101);

        // Eligibility no longer refuses a Standard original by kind (P41).
        $this->assertTrue(app(CreditNoteEligibilityPolicy::class)->allows($original));

        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'Company cancelled the course', (string) Str::uuid()
        );

        $document = $this->runCreditNoteGeneration($creditNote);

        $this->assertSame(ZatcaComplianceDocumentType::StandardCreditNote, $document->document_type);
        $this->assertSame(ZatcaSubmissionModel::Clearance, $document->submission_model);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">381</cbc:InvoiceTypeCode>', $document->unsigned_xml);
        $this->assertStringContainsString('شركة الاختبار المحدودة', $document->unsigned_xml);
        $this->assertStringContainsString('310123456700003', $document->unsigned_xml);
        $this->assertStringContainsString('order:7101', $document->unsigned_xml); // billing reference to the original

        // Routing to Clearance came from the document's OWN type, not a guess about
        // which EGS is "current": Clearance was dispatched, Reporting never was.
        Queue::assertPushed(ClearZatcaInvoiceJob::class);
        Queue::assertNotPushed(ReportZatcaInvoiceJob::class);

        $restored = InvoiceDataSnapshot::fromArray($document->fresh()->invoice_data_snapshot);
        $this->assertSame('شركة الاختبار المحدودة', $restored->buyer->registrationName);
        $this->assertSame('310123456700003', $restored->buyer->vatNumber);
    }

    /** A B2C original stays exactly as it always has: Simplified, Reporting, no buyer. */
    public function test_a_credit_note_against_a_simplified_original_is_unaffected(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $original = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7102], self::EGS);
        $original->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original->fresh(), ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );

        $document = $this->runCreditNoteGeneration($creditNote);

        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $document->document_type);
        $this->assertSame(ZatcaSubmissionModel::Reporting, $document->submission_model);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $document->unsigned_xml);

        Queue::assertPushed(ReportZatcaInvoiceJob::class);
        Queue::assertNotPushed(ClearZatcaInvoiceJob::class);

        $restored = InvoiceDataSnapshot::fromArray($document->fresh()->invoice_data_snapshot);
        $this->assertNull($restored->buyer);
    }

    public function test_clearance_rejected_credit_note_is_definitively_rejected_and_cancellable(): void
    {
        $original = $this->clearedStandardOriginal(7103);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );
        $document = $this->runCreditNoteGeneration($creditNote);

        $document->update(['status' => ZatcaInvoiceStatus::ClearanceRejected]);

        $this->assertSame(
            \Zatca\Enums\ZatcaCreditNoteDisplayStatus::Rejected,
            app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh())
        );
        $this->assertTrue(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));

        // Cancelling releases the reserved line, exactly as a Simplified rejection does.
        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());
        $this->assertNotNull($creditNote->fresh()->cancelled_at);
    }

    /** ClearanceInDoubt means "unknown whether ZATCA received it" — never cancellable, never re-signed. */
    public function test_clearance_in_doubt_credit_note_is_processing_and_not_cancellable(): void
    {
        $original = $this->clearedStandardOriginal(7104);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );
        $document = $this->runCreditNoteGeneration($creditNote);

        $document->update(['status' => ZatcaInvoiceStatus::ClearanceInDoubt, 'in_doubt_since' => now()]);

        $this->assertSame(
            \Zatca\Enums\ZatcaCreditNoteDisplayStatus::Processing,
            app(CreditNoteStatusResolver::class)->resolve($creditNote->fresh())
        );
        $this->assertFalse(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));

        $this->expectException(CreditNoteException::class);
        app(CreditNoteCanceller::class)->cancel($creditNote->fresh());
    }

    /** A cleared document is never sent again — the same guarantee ReportsZatcaInvoice gives. */
    public function test_an_already_cleared_credit_note_is_never_resubmitted(): void
    {
        $original = $this->clearedStandardOriginal(7105);
        $creditNote = app(CreditNoteIssuer::class)->issue(
            $original, ZatcaCreditNoteType::Full, [], 'x', (string) Str::uuid()
        );
        $document = $this->runCreditNoteGeneration($creditNote);

        $this->clearSuccessfully($document);
        $this->assertNotNull($document->fresh()->cleared_at);
        $this->assertNotNull($creditNote->fresh()->accepted_at);

        $connector = \Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldNotReceive('clearInvoice');
        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));

        // A second clear() call for the same document must be a silent no-op.
        (new ClearZatcaInvoiceJob($document->id))->handle(app(ConnectorFactory::class));

        $this->assertFalse(app(CreditNoteCanceller::class)->isCancellable($creditNote->fresh()));
    }

    // ---- Debit Note -------------------------------------------------------

    public function test_a_debit_note_against_a_standard_original_is_itself_standard_with_the_inherited_buyer(): void
    {
        $original = $this->clearedStandardOriginal(7201);

        $this->assertTrue(app(DebitNoteEligibilityPolicy::class)->allows($original));

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00, null, 44)],
            'Additional course added',
            (string) Str::uuid(),
        );

        $document = $this->runDebitNoteGeneration($debitNote);

        $this->assertSame(ZatcaComplianceDocumentType::StandardDebitNote, $document->document_type);
        $this->assertSame(ZatcaSubmissionModel::Clearance, $document->submission_model);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">383</cbc:InvoiceTypeCode>', $document->unsigned_xml);
        $this->assertStringContainsString('شركة الاختبار المحدودة', $document->unsigned_xml);

        Queue::assertPushed(ClearZatcaInvoiceJob::class);
        Queue::assertNotPushed(ReportZatcaInvoiceJob::class);
    }

    public function test_a_debit_note_against_a_simplified_original_is_unaffected(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $original = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7202], self::EGS);
        $original->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original->fresh(),
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00, null, 44)],
            'x',
            (string) Str::uuid(),
        );

        $document = $this->runDebitNoteGeneration($debitNote);

        $this->assertSame(ZatcaComplianceDocumentType::DebitNote, $document->document_type);
        $this->assertSame(ZatcaSubmissionModel::Reporting, $document->submission_model);
        Queue::assertPushed(ReportZatcaInvoiceJob::class);
        Queue::assertNotPushed(ClearZatcaInvoiceJob::class);
    }

    public function test_clearance_rejected_debit_note_is_definitively_rejected_and_cancellable(): void
    {
        $original = $this->clearedStandardOriginal(7203);
        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00, null, 44)],
            'x',
            (string) Str::uuid(),
        );
        $document = $this->runDebitNoteGeneration($debitNote);

        $document->update(['status' => ZatcaInvoiceStatus::ClearanceRejected]);

        $this->assertSame(
            \Zatca\Enums\ZatcaDebitNoteDisplayStatus::Rejected,
            app(DebitNoteStatusResolver::class)->resolve($debitNote->fresh())
        );
        $this->assertTrue(app(DebitNoteCanceller::class)->isCancellable($debitNote->fresh()));
    }

    public function test_clearance_manual_review_debit_note_cannot_be_cancelled_when_ambiguous(): void
    {
        $original = $this->clearedStandardOriginal(7204);
        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00, null, 44)],
            'x',
            (string) Str::uuid(),
        );
        $document = $this->runDebitNoteGeneration($debitNote);

        $document->update(['status' => ZatcaInvoiceStatus::ClearanceManualReview]);
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $document->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => 0,
            'error_message' => 'network error',
            'attempted_at' => now(),
        ]);

        $this->assertSame(
            \Zatca\Enums\ZatcaDebitNoteDisplayStatus::Failed,
            app(DebitNoteStatusResolver::class)->resolve($debitNote->fresh())
        );
        $this->assertFalse(app(DebitNoteCanceller::class)->isCancellable($debitNote->fresh()));

        $this->expectException(DebitNoteException::class);
        app(DebitNoteCanceller::class)->cancel($debitNote->fresh());
    }

    public function test_clearance_success_accepts_the_debit_note_once_and_announces_it(): void
    {
        \Illuminate\Support\Facades\Event::fake([ZatcaDebitNoteAccepted::class]);

        $original = $this->clearedStandardOriginal(7205);
        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00, null, 44)],
            'x',
            (string) Str::uuid(),
        );
        $document = $this->runDebitNoteGeneration($debitNote);

        $this->clearSuccessfully($document);

        $debitNote = $debitNote->fresh();
        $this->assertNotNull($debitNote->accepted_at);
        $this->assertSame(\Zatca\Enums\ZatcaDebitNoteDisplayStatus::Accepted, app(DebitNoteStatusResolver::class)->resolve($debitNote));
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(ZatcaDebitNoteAccepted::class, 1);
    }

    // ---- Fixtures -----------------------------------------------------

    /** A Standard (B2B) original, already cleared, with a real structured buyer — built via the real orchestrator. */
    private function clearedStandardOriginal(int $sourceId): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeStandardInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update(['status' => ZatcaInvoiceStatus::Cleared, 'cleared_at' => now()]);

        return $invoice->fresh();
    }

    private function fakeStandardInvoiceSource(): InvoiceSourceContract
    {
        return new class implements InvoiceSourceContract
        {
            public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
            {
                return new ZatcaInvoiceData(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'شركة الاختبار المحدودة',
                    buyerVatNumber: '310123456700003',
                    issueDateTime: new DateTimeImmutable('2026-09-20T10:30:00+03:00'),
                    currency: 'SAR',
                    lines: [new ZatcaInvoiceLine('Course Enrollment', 1, 100.00, 15.0, 100.00, 15.00)],
                    totalExclVat: 100.00,
                    totalVat: 15.00,
                    totalInclVat: 115.00,
                    documentType: ZatcaComplianceDocumentType::StandardInvoice,
                    buyer: new BuyerParty(
                        registrationName: 'شركة الاختبار المحدودة',
                        vatNumber: '310123456700003',
                        otherId: null,
                        address: new BuyerAddress(
                            street: 'شارع الاختبار',
                            buildingNumber: '1234',
                            district: 'حي الاختبار',
                            city: 'الرياض',
                            postalCode: '12345',
                            countryCode: 'SA',
                        ),
                    ),
                    supplyDate: new DateTimeImmutable('2026-09-20'),
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'order:'.$source->id;
            }
        };
    }

    private function runCreditNoteGeneration(ZatcaCreditNote $creditNote): ZatcaInvoice
    {
        app(GenerateZatcaCreditNoteJob::class, ['creditNoteId' => $creditNote->id])
            ->handle(app(ProductionInvoiceOrchestrator::class), app(ZatcaGate::class));

        return ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail();
    }

    private function runDebitNoteGeneration(ZatcaDebitNote $debitNote): ZatcaInvoice
    {
        app(GenerateZatcaDebitNoteJob::class, ['debitNoteId' => $debitNote->id])
            ->handle(app(ProductionInvoiceOrchestrator::class), app(ZatcaGate::class));

        return ZatcaInvoice::where('debit_note_id', $debitNote->id)->firstOrFail();
    }

    /** Runs the real clearing trait against a scripted PASS response. */
    private function clearSuccessfully(ZatcaInvoice $document): void
    {
        $result = new ZatcaApiResult(
            successful: true,
            status: ZatcaResponseStatus::Pass,
            httpStatus: 200,
            requestId: null,
            sanitizedResponse: ['clearanceStatus' => 'CLEARED'],
            payload: new ClearancePayload(clearanceStatus: 'CLEARED', clearedInvoiceBase64: base64_encode('cleared-xml')),
        );

        $connector = \Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldReceive('clearInvoice')->once()->andReturn($result);

        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));

        (new ClearZatcaInvoiceJob($document->id))->handle(app(ConnectorFactory::class));
    }
}
