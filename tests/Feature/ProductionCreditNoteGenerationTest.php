<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteDataFactory;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use DateTimeImmutable;

/**
 * Proves ProductionInvoiceOrchestrator::generateCreditNote() shares the same ICV/PIH
 * chain safety as a plain invoice, produces a real 381 document from the ORIGINAL's
 * frozen snapshot, and is idempotent by the Credit Note's own source_reference.
 */
class ProductionCreditNoteGenerationTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    public function test_it_generates_a_381_document_continuing_the_same_icv_pih_chain(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8001], self::EGS);
        $this->assertSame(1, $original->icv);

        $creditNoteData = $this->creditNoteDataFor($original, 'CN-000001');
        $creditNote = $orchestrator->generateCreditNote($creditNoteData, self::EGS, creditNoteId: 1);

        $this->assertSame(2, $creditNote->icv);
        $this->assertSame($original->invoice_hash, $creditNote->previous_invoice_hash);
        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $creditNote->document_type);
        $this->assertSame(1, $creditNote->credit_note_id);
        $this->assertNotSame($original->uuid, $creditNote->uuid);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $creditNote->unsigned_xml);
        $this->assertStringContainsString('<cbc:ID>CN-000001</cbc:ID>', $creditNote->unsigned_xml);
        $this->assertStringContainsString('order:8001', $creditNote->unsigned_xml); // billing reference to the original

        // Chain continues correctly for the NEXT invoice.
        $next = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8002], self::EGS);
        $this->assertSame(3, $next->icv);
        $this->assertSame($creditNote->invoice_hash, $next->previous_invoice_hash);

        $state = ZatcaEgsState::where('egs_identifier', self::EGS)->where('state_type', 'production')->first();
        $this->assertSame(3, $state->icv);
    }

    public function test_it_is_idempotent_by_credit_note_source_reference(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8101], self::EGS);
        $creditNoteData = $this->creditNoteDataFor($original, 'CN-000002');

        $first = $orchestrator->generateCreditNote($creditNoteData, self::EGS, creditNoteId: 2);
        $second = $orchestrator->generateCreditNote($creditNoteData, self::EGS, creditNoteId: 2);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame($first->icv, $second->icv);
        $this->assertSame(2, ZatcaInvoice::where('egs_identifier', self::EGS)->count());
    }

    public function test_its_snapshot_round_trips_document_type_billing_reference_and_reason(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8201], self::EGS);
        $creditNoteData = $this->creditNoteDataFor($original, 'CN-000003');

        $creditNote = $orchestrator->generateCreditNote($creditNoteData, self::EGS, creditNoteId: 3);

        $restored = InvoiceDataSnapshot::fromArray($creditNote->invoice_data_snapshot);

        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $restored->documentType);
        $this->assertSame($original->source_reference, $restored->billingReferenceId);
        $this->assertSame('Course refunded per policy', $restored->adjustmentReason);
    }

    private function creditNoteDataFor(ZatcaInvoice $original, string $creditNoteNumber): ZatcaInvoiceData
    {
        $originalData = InvoiceDataSnapshot::fromArray($original->invoice_data_snapshot);

        return app(CreditNoteDataFactory::class)->make(
            $originalData,
            lineIndexes: [0],
            creditNoteNumber: $creditNoteNumber,
            billingReferenceId: $original->source_reference,
            reason: 'Course refunded per policy',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );
    }
}
