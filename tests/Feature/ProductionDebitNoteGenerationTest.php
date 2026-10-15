<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\DebitNote\DebitNoteDataFactory;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use DateTimeImmutable;

/**
 * Proves ProductionInvoiceOrchestrator::generateDebitNote() shares the SAME ICV/PIH
 * chain as invoices and credit notes (the approved design: no separate counter),
 * produces a real 383 document, and is idempotent by the Debit Note's own
 * source_reference.
 */
class ProductionDebitNoteGenerationTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    public function test_it_generates_a_383_document_continuing_the_same_icv_pih_chain(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8501], self::EGS);
        $this->assertSame(1, $original->icv);

        $debitNoteData = $this->debitNoteDataFor($original, 'DN-000001');
        $debitNote = $orchestrator->generateDebitNote($debitNoteData, self::EGS, debitNoteId: 1);

        $this->assertSame(2, $debitNote->icv);
        $this->assertSame($original->invoice_hash, $debitNote->previous_invoice_hash);
        $this->assertSame(ZatcaComplianceDocumentType::DebitNote, $debitNote->document_type);
        $this->assertSame(1, $debitNote->debit_note_id);
        $this->assertNull($debitNote->credit_note_id);
        $this->assertNotSame($original->uuid, $debitNote->uuid);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">383</cbc:InvoiceTypeCode>', $debitNote->unsigned_xml);
        $this->assertStringContainsString('<cbc:ID>DN-000001</cbc:ID>', $debitNote->unsigned_xml);
        $this->assertStringContainsString('order:8501', $debitNote->unsigned_xml); // billing reference to the original

        // The chain continues correctly for the NEXT invoice — a debit note is not a
        // parallel sequence, it is the same one.
        $next = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8502], self::EGS);
        $this->assertSame(3, $next->icv);
        $this->assertSame($debitNote->invoice_hash, $next->previous_invoice_hash);

        $state = ZatcaEgsState::where('egs_identifier', self::EGS)->where('state_type', 'production')->first();
        $this->assertSame(3, $state->icv);
    }

    /** The reason reaches the XML — the fix to SimplifiedDebitNoteXmlGenerator. */
    public function test_the_real_reason_reaches_the_payment_means_instruction_note(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8601], self::EGS);
        $data = $this->debitNoteDataFor($original, 'DN-000010', 'Additional item added after purchase');

        $debitNote = $orchestrator->generateDebitNote($data, self::EGS, debitNoteId: 10);

        $this->assertStringContainsString(
            '<cbc:InstructionNote>Additional item added after purchase</cbc:InstructionNote>',
            $debitNote->unsigned_xml
        );
        $this->assertStringNotContainsString('Compliance test debit note', $debitNote->unsigned_xml);
    }

    public function test_it_is_idempotent_by_debit_note_source_reference(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8701], self::EGS);
        $data = $this->debitNoteDataFor($original, 'DN-000002');

        $first = $orchestrator->generateDebitNote($data, self::EGS, debitNoteId: 2);
        $second = $orchestrator->generateDebitNote($data, self::EGS, debitNoteId: 2);

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

        $original = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 8801], self::EGS);
        $data = $this->debitNoteDataFor($original, 'DN-000003', 'Price correction');
        $debitNote = $orchestrator->generateDebitNote($data, self::EGS, debitNoteId: 3);

        $restored = InvoiceDataSnapshot::fromArray($debitNote->fresh()->invoice_data_snapshot);

        $this->assertSame(ZatcaComplianceDocumentType::DebitNote, $restored->documentType);
        $this->assertSame('order:8801', $restored->billingReferenceId);
        $this->assertSame('Price correction', $restored->adjustmentReason);
        $this->assertSame('DN-000003', $restored->sourceReference);
    }

    private function debitNoteDataFor(ZatcaInvoice $original, string $number, string $reason = 'Additional amount due'): ZatcaInvoiceData
    {
        $originalData = InvoiceDataSnapshot::fromArray($original->invoice_data_snapshot);

        return app(DebitNoteDataFactory::class)->make(
            $originalData,
            [new DebitNoteLineInput('Extra item', 1, 230.00, itemId: 44)],
            $number,
            $original->source_reference,
            $reason,
            new DateTimeImmutable('2026-09-15T12:00:00+03:00'),
        );
    }
}
