<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Jobs\GenerateZatcaCreditNoteJob;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteEligibilityVerdict;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\CreditNote\CreditNoteLedgerSnapshot;
use Zatca\Services\CreditNote\CreditSource;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use DOMDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Lines of ACCEPTED Debit Notes as Credit Note sources, and the Fully Credited status
 * that follows from them.
 *
 * The invoice throughout is the one from the bug reports:
 *
 *   Original invoice   Course A   200.00
 *   Debit Note         Course B  +100.00
 *
 * The invariant every test protects: cumulative credits never exceed the original
 * plus what accepted debit notes added, and each source is credited at most once.
 */
class CreditNoteDebitSourceTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            \Zatca\Jobs\GenerateZatcaCreditNoteJob::class,
            \Zatca\Jobs\GenerateZatcaDebitNoteJob::class,
            // Queued by a generation this class runs by hand; nothing here reports.
            \Zatca\Jobs\ReportZatcaInvoiceJob::class,
        ]);
    }

    /** Bug 1: the course a debit note added is offered, and credited at its own value. */
    public function test_an_accepted_additional_course_line_becomes_creditable(): void
    {
        $original = $this->reportedInvoice(8001);
        $debitNote = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00, courseId: 55);
        $debitLineId = $debitNote->lines->first()->id;

        $ledger = $this->ledger($original);
        $this->assertSame('available', $ledger->debitLineStates[$debitLineId]);
        $this->assertSame(300.00, $ledger->creditableTotal());
        $this->assertSame(300.00, $ledger->remainingCreditableInclVat);

        $creditNote = $this->issue($original, [], [$debitLineId]);

        $this->assertSame(100.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(1, $creditNote->lines);

        $line = $creditNote->lines->first();
        $this->assertNull($line->original_line_index);
        $this->assertSame(CreditSource::TYPE_DEBIT_NOTE_LINE, $line->source_type);
        $this->assertSame((int) $debitNote->id, (int) $line->source_debit_note_id);
        $this->assertSame((int) $debitLineId, (int) $line->source_debit_note_line_id);
        $this->assertSame(55, (int) $line->course_id);
        $this->assertSame('dn_line:'.$debitLineId, $line->active_line_key);
        $this->assertNull($line->discount_pool_key);

        // The debit-sourced claim must not be mistaken for a claim on original line 0.
        $after = $this->ledger($original);
        $this->assertSame('available', $after->lineStates[0]);
        $this->assertSame([0], $after->availableIndexes);
        $this->assertSame('reserved', $after->debitLineStates[$debitLineId]);
        $this->assertSame(200.00, $after->remainingCreditableInclVat);
    }

    /** Pending, cancelled, never-accepted debit notes introduce nothing. */
    public function test_debit_notes_that_are_not_accepted_introduce_nothing(): void
    {
        // One invoice per state: while a pending or failed note stands, no second note of
        // either kind may be issued against the same invoice at all.
        $cases = [
            // A pending or failed note blocks every new note until it settles; a cancelled
            // one blocks nothing and is simply not a source.
            [8002, null, CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS],
            [8040, 'generation_failed_at', CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS],
            [8041, 'cancelled_at', CreditNoteException::EXCEEDS_REMAINING],
        ];

        foreach ($cases as [$sourceId, $state, $refusal]) {
            $original = $this->reportedInvoice($sourceId);
            $note = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00, accept: false);

            if ($state !== null) {
                $note->update([$state => now()]);
            }

            $ledger = $this->ledger($original);
            $this->assertSame([], $ledger->debitSources);
            $this->assertSame(200.00, $ledger->creditableTotal());

            try {
                $this->issue($original, [], [$note->lines->first()->id]);
                $this->fail('A debit note that is not accepted must not be creditable.');
            } catch (CreditNoteException $e) {
                $this->assertSame($refusal, $e->reason);
            }
        }

        $this->assertSame(0, ZatcaCreditNote::count());
    }

    /** Bug 2: fully credited → debit note accepted → creditable again → fully credited again. */
    public function test_fully_credited_reopens_for_an_accepted_debit_note_and_closes_again(): void
    {
        $original = $this->reportedInvoice(8003);

        $full = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full refund', (string) Str::uuid());
        $full->update(['accepted_at' => now()]);

        $this->assertSame(CreditNoteEligibilityVerdict::FULLY_CREDITED, $this->verdict($original)->reason);
        $this->assertTrue($this->ledger($original)->isFullyCredited());

        // A pending debit note adds nothing creditable — and while it is in progress, no
        // new note may be issued at all.
        $debitNote = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00, accept: false);
        $this->assertTrue($this->ledger($original)->isFullyCredited());
        $this->assertSame(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, $this->verdict($original)->reason);

        $debitNote->update(['accepted_at' => now()]);

        $this->assertTrue($this->verdict($original)->eligible);
        $ledger = $this->ledger($original);
        $this->assertFalse($ledger->isFullyCredited());
        $this->assertTrue($ledger->isPartiallyCredited());
        $this->assertSame(100.00, $ledger->remainingCreditableInclVat);

        $second = $this->issue($original, [], [$debitNote->lines->first()->id]);
        $second->update(['accepted_at' => now()]);

        $closed = $this->ledger($original);
        $this->assertTrue($closed->isFullyCredited());
        $this->assertSame(0.00, $closed->remainingCreditableInclVat);
        $this->assertSame(300.00, $closed->creditedTotalInclVat);
        $this->assertSame(CreditNoteEligibilityVerdict::FULLY_CREDITED, $this->verdict($original)->reason);
    }

    /**
     * A price adjustment is its own source — it never reopens the original line it
     * refers to — but crediting that line takes the adjustment with it (bug #33285):
     * the student paid 200 for the course and 100 more for the same course, so
     * cancelling it hands back 300.
     */
    public function test_crediting_a_line_also_credits_the_debit_note_that_raised_its_price(): void
    {
        $original = $this->reportedInvoice(8004);

        // Adjusted first: once the line is credited it is no longer open to a price
        // adjustment (DebitNoteIssuer refuses one).
        $debitNote = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 100.00, referencedIndex: 0);
        $debitLineId = $debitNote->lines->first()->id;

        // Only the course is asked for; the adjustment is added by the ledger.
        $creditNote = $this->issue($original, [0], []);
        $creditNote->update(['accepted_at' => now()]);

        $this->assertSame(300.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(2, $creditNote->lines);
        $this->assertSame(
            [$debitLineId],
            $creditNote->lines
                ->where('source_type', CreditSource::TYPE_DEBIT_NOTE_LINE)
                ->pluck('source_debit_note_line_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $ledger = $this->ledger($original);
        $this->assertSame('credited', $ledger->lineStates[0]);
        $this->assertSame([], $ledger->availableIndexes);
        $this->assertSame([], $ledger->availableDebitLineIds());
        $this->assertSame(0, $ledger->debitSources[$debitLineId]->referencedOriginalLineIndex);
        $this->assertTrue($ledger->isFullyCredited());

        foreach ([[[0], []], [[], [$debitLineId]]] as [$lineIndexes, $debitLineIds]) {
            try {
                $this->issue($original, $lineIndexes, $debitLineIds);
                $this->fail('Nothing credited by that note may be credited again.');
            } catch (CreditNoteException $e) {
                $this->assertSame(CreditNoteEligibilityVerdict::FULLY_CREDITED, $e->reason);
            }
        }
    }

    /**
     * The document ZATCA actually receives for such a note: both the course and the
     * price increase are lines of ONE credit note, and its totals are the sum of the two.
     */
    public function test_the_signed_document_carries_the_course_and_its_price_increase(): void
    {
        $original = $this->reportedInvoice(8022);
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 100.00, referencedIndex: 0);

        $creditNote = $this->issue($original, [0], []);

        (new GenerateZatcaCreditNoteJob($creditNote->id))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(ZatcaGate::class)
        );

        $document = ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail();
        $xml = $document->unsigned_xml;

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Generated XML must be well-formed.');

        $this->assertSame(2, substr_count($xml, '<cac:InvoiceLine>'));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">300.00</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="SAR">260.87</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="SAR">39.13</cbc:TaxAmount>', $xml);
        // Billed against the ORIGINAL invoice, not against the debit note that raised the price.
        $this->assertStringContainsString('<cbc:ID>'.$original->source_reference.'</cbc:ID>', $xml);

        // What was signed is what the business record says, to the fil.
        $this->assertSame(300.00, (float) $creditNote->fresh()->total_incl_vat);
        $this->assertSame(
            300.00,
            round(array_sum($creditNote->fresh(['lines'])->lines->map(fn ($l) => (float) $l->line_total_incl_vat)->all()), 2)
        );
    }

    /**
     * What ZATCA is actually sent when an adjustment reverses an increase: ONE credit note
     * stating both parts — 850.00 off the course and the 200.00 increase given back — with
     * totals that add up to the 1,050.00 the admin asked for, billed against the original.
     */
    public function test_the_signed_document_states_the_adjustment_and_the_increase_it_reversed(): void
    {
        $original = $this->reportedInvoice(8037, [['Course A', 1000.00]]);
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);

        $creditNote = $this->adjust($original, 'line:0', 1050.00);

        (new GenerateZatcaCreditNoteJob($creditNote->id))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(ZatcaGate::class)
        );

        $xml = ZatcaInvoice::where('credit_note_id', $creditNote->id)->firstOrFail()->unsigned_xml;

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Generated XML must be well-formed.');

        $this->assertSame(2, substr_count($xml, '<cac:InvoiceLine>'));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">1050.00</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="SAR">913.04</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="SAR">136.96</cbc:TaxAmount>', $xml);
        // Billed against the ORIGINAL invoice, not the debit note whose increase it reversed.
        $this->assertStringContainsString('<cbc:ID>'.$original->source_reference.'</cbc:ID>', $xml);

        // What was signed is what the business record says, to the fil.
        $this->assertSame(1050.00, (float) $creditNote->fresh()->total_incl_vat);
        $this->assertSame(
            1050.00,
            round(array_sum($creditNote->fresh(['lines'])->lines->map(fn ($l) => (float) $l->line_total_incl_vat)->all()), 2)
        );
    }

    /** The same rule one level up: a price adjustment on a course a debit note added. */
    public function test_crediting_an_added_course_also_credits_a_price_adjustment_on_it(): void
    {
        $original = $this->reportedInvoice(8019);

        $addedLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00, courseId: 55)
            ->lines->first()->id;
        $adjustmentLineId = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 50.00, referencedDebitLineId: $addedLineId)
            ->lines->first()->id;

        $creditNote = $this->issue($original, [], [$addedLineId]);

        $this->assertSame(150.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(2, $creditNote->lines);

        $ledger = $this->ledger($original);
        $this->assertSame('reserved', $ledger->debitLineStates[$addedLineId]);
        $this->assertSame('reserved', $ledger->debitLineStates[$adjustmentLineId]);
        // The original course is untouched by any of it.
        $this->assertSame([0], $ledger->availableIndexes);
    }

    /** Only the adjustments of what is actually being credited come along. */
    public function test_an_adjustment_on_another_line_is_left_alone(): void
    {
        $original = $this->reportedInvoice(8020, [['Course A', 120.00], ['Course B', 200.00]]);

        $adjustmentLineId = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 60.00, referencedIndex: 1)
            ->lines->first()->id;

        $creditNote = $this->issue($original, [0], []);

        $this->assertSame(120.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(1, $creditNote->lines);
        $this->assertSame([$adjustmentLineId], $this->ledger($original)->availableDebitLineIds());
    }

    /** An adjustment another note is already crediting is that note's to credit, not this one's. */
    public function test_an_adjustment_a_pending_note_is_crediting_is_not_pulled_in_again(): void
    {
        $original = $this->reportedInvoice(8021);

        $adjustmentLineId = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 100.00, referencedIndex: 0)
            ->lines->first()->id;

        // Still in flight: it holds the adjustment, so crediting the course would not take it.
        $this->issue($original, [], [$adjustmentLineId]);
        $ledger = $this->ledger($original);
        $this->assertSame('reserved', $ledger->debitLineStates[$adjustmentLineId]);
        $this->assertSame([], $ledger->attachedAvailableDebitLineIds('line:0'));

        // And the course cannot be credited at all until that note settles.
        try {
            $this->issue($original, [0], []);
            $this->fail('A second note while one is in progress should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, $e->reason);
        }
    }

    public function test_the_same_debit_note_line_cannot_be_credited_twice(): void
    {
        $original = $this->reportedInvoice(8005);
        $debitLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $this->issue($original, [], [$debitLineId])->update(['accepted_at' => now()]);

        $this->expectException(CreditNoteException::class);
        $this->expectExceptionMessageMatches('/cannot exceed the remaining eligible amount/');

        $this->issue($original, [], [$debitLineId]);
    }

    /** If the row lock were ever bypassed, the unique active_line_key still refuses the second claim. */
    public function test_the_unique_key_backstops_a_debit_note_line(): void
    {
        $original = $this->reportedInvoice(8006);
        $debitLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $first = $this->issue($original, [], [$debitLineId]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/UNIQUE constraint failed: zatca_credit_note_lines\.active_line_key/');

        ZatcaCreditNoteLine::create([
            ...$first->lines->first()->only([
                'credit_note_id', 'original_zatca_invoice_id', 'source_type', 'source_debit_note_id',
                'source_debit_note_line_id', 'description', 'quantity', 'unit_price', 'vat_rate',
                'line_total_excl_vat', 'vat_amount', 'line_total_incl_vat',
            ]),
            'active_line_key' => ZatcaCreditNoteLine::debitNoteLineKey($debitLineId),
        ]);
    }

    public function test_cancelling_a_credit_note_releases_its_debit_note_line(): void
    {
        $original = $this->reportedInvoice(8007);
        $debitLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $creditNote = $this->issue($original, [], [$debitLineId]);
        $this->assertSame('reserved', $this->ledger($original)->debitLineStates[$debitLineId]);

        app(CreditNoteCanceller::class)->cancel($creditNote);

        $ledger = $this->ledger($original);
        $this->assertSame('available', $ledger->debitLineStates[$debitLineId]);
        $this->assertSame(300.00, $ledger->remainingCreditableInclVat);

        $this->assertSame(100.00, (float) $this->issue($original, [], [$debitLineId])->total_incl_vat);
    }

    public function test_replaying_the_same_request_never_claims_the_line_twice(): void
    {
        $original = $this->reportedInvoice(8008);
        $debitLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;
        $key = (string) Str::uuid();

        $first = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [], 'x', $key, null, null, [$debitLineId]);
        $second = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [], 'x', $key, null, null, [$debitLineId]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ZatcaCreditNote::count());
        $this->assertSame(1, ZatcaCreditNoteLine::count());
    }

    /** One note may credit both kinds; original lines come first, then debit note lines. */
    public function test_a_note_can_credit_an_original_line_and_a_debit_note_line_together(): void
    {
        $original = $this->reportedInvoice(8009);
        $debitLineId = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $creditNote = $this->issue($original, [0], [$debitLineId]);

        $this->assertSame(300.00, (float) $creditNote->total_incl_vat);

        $snapshot = InvoiceDataSnapshot::fromArray($creditNote->document_snapshot);
        $this->assertSame(['Course A', 'Course B'], array_map(fn ($l) => $l->description, $snapshot->lines));
        $this->assertSame($creditNote->billing_reference_id, $snapshot->billingReferenceId);
        $this->assertSame($original->source_reference, $snapshot->billingReferenceId);

        $keys = $creditNote->lines->pluck('active_line_key')->sort()->values()->all();
        $this->assertSame([$original->id.':0', 'dn_line:'.$debitLineId], $keys);
    }

    public function test_full_credit_includes_accepted_debit_note_lines(): void
    {
        $original = $this->reportedInvoice(8010);
        $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00);
        // One that never became a source: cancelled, so it no longer blocks a new note.
        $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 60.00, accept: false)
            ->update(['cancelled_at' => now()]);

        $creditNote = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full', (string) Str::uuid());

        $this->assertSame(300.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(2, $creditNote->lines);
    }

    /** After the original is fully credited, Full credits just what the debit note added. */
    public function test_full_credit_after_the_original_is_credited_takes_only_debit_note_lines(): void
    {
        $original = $this->reportedInvoice(8011);
        $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full', (string) Str::uuid())
            ->update(['accepted_at' => now()]);

        $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00);

        $creditNote = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full again', (string) Str::uuid());

        $this->assertSame(100.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(1, $creditNote->lines);
        $this->assertTrue($creditNote->lines->first()->isDebitNoteSource());
    }

    /**
     * A VAT adjustment is credited as an exact mirror of the signed line (no net, its
     * VAT), only in a note of its own, and never swept into a Full credit.
     */
    public function test_a_vat_adjustment_is_mirrored_and_only_credited_alone(): void
    {
        $original = $this->reportedInvoice(8012);
        $vatNote = $this->debitNote($original, ZatcaDebitNoteReason::VatAdjustment, 15.00, referencedIndex: 0, vatOnly: true);
        $vatLineId = $vatNote->lines->first()->id;

        try {
            $this->issue($original, [0], [$vatLineId]);
            $this->fail('A VAT adjustment must not be combined with other lines.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::MUST_BE_CREDITED_ALONE, $e->reason);
        }

        $full = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full', (string) Str::uuid());
        $this->assertSame(200.00, (float) $full->total_incl_vat);
        $this->assertFalse($full->lines->contains(fn ($l) => $l->isDebitNoteSource()));
        $full->update(['accepted_at' => now()]);

        $alone = $this->issue($original, [], [$vatLineId]);

        $line = $alone->lines->first();
        $this->assertSame(0.00, (float) $line->line_total_excl_vat);
        $this->assertSame(15.00, (float) $line->vat_amount);
        $this->assertSame(15.00, (float) $line->vat_rate);
        $this->assertSame(15.00, (float) $alone->total_incl_vat);
    }

    public function test_a_debit_note_against_another_invoice_is_never_a_source(): void
    {
        $original = $this->reportedInvoice(8013);
        $other = $this->reportedInvoice(8014);
        $foreignLineId = $this->debitNote($other, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $this->assertSame([], $this->ledger($original)->debitSources);

        $this->expectException(CreditNoteException::class);
        $this->issue($original, [], [$foreignLineId]);
    }

    /** A debit note line whose row disagrees with what was signed is left out, never guessed at. */
    public function test_a_debit_note_line_that_disagrees_with_its_signed_snapshot_is_not_creditable(): void
    {
        $original = $this->reportedInvoice(8015);
        $line = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first();

        $line->update(['line_total_excl_vat' => 90.00]);

        $ledger = $this->ledger($original);
        $this->assertSame([], $ledger->debitSources);
        $this->assertSame(200.00, $ledger->creditableTotal());
    }

    /** The whole lifecycle closes on exactly original + accepted debits, never more. */
    public function test_cumulative_credits_close_on_the_original_plus_accepted_debits(): void
    {
        $original = $this->reportedInvoice(8016);

        // Adjusted before line 0 is credited, which is the only order that is allowed.
        // Crediting line 0 credits that adjustment along with it.
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 40.00, referencedIndex: 0);
        $this->issue($original, [0], [])->update(['accepted_at' => now()]);

        $b = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;

        $this->issue($original, [], [$b])->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);
        $this->assertSame(340.00, $ledger->creditableTotal());
        $this->assertSame(340.00, $ledger->creditedTotalInclVat);
        $this->assertSame(0.00, $ledger->remainingCreditableInclVat);
        $this->assertTrue($ledger->isFullyCredited());
        $this->assertFalse($this->verdict($original)->eligible);
    }

    /** A course added after a bundle purchase takes none of the bundle's discount. */
    public function test_a_debit_note_line_on_a_bundle_invoice_never_touches_the_discount(): void
    {
        $original = $this->reportedInvoice(8017, [['Course A', 120.00], ['Course B', 200.00]], [32.00, 'bundle:1', [0, 1]]);

        $added = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 100.00)->lines->first()->id;
        $adjustment = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 50.00, referencedIndex: 0)->lines->first()->id;

        $addedCredit = $this->issue($original, [], [$added]);
        $this->assertSame(100.00, (float) $addedCredit->total_incl_vat);
        $addedCredit->update(['accepted_at' => now()]);

        $adjustmentCredit = $this->issue($original, [], [$adjustment]);
        $this->assertSame(50.00, (float) $adjustmentCredit->total_incl_vat);
        $adjustmentCredit->update(['accepted_at' => now()]);

        $pool = $this->ledger($original)->discountPools['bundle:1'];
        $this->assertSame(0.00, $pool['reserved']);
        $this->assertSame(32.00, $pool['available']);

        // The bundle's own courses still draw the discount down exactly as before.
        $first = $this->issue($original, [0], []);
        $this->assertSame(88.00, (float) $first->total_incl_vat);
    }

    /** A fully credited bundle reopened by a debit note: only the debit is creditable, the discount is not spent again. */
    public function test_a_fully_credited_bundle_reopened_by_a_debit_note_does_not_spend_the_discount_again(): void
    {
        $original = $this->reportedInvoice(8018, [['Course A', 120.00], ['Course B', 200.00]], [32.00, 'bundle:1', [0, 1]]);

        $full = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full', (string) Str::uuid());
        $full->update(['accepted_at' => now()]);
        $this->assertSame(288.00, (float) $full->total_incl_vat);

        // An added course: a price adjustment of a credited bundle line is refused (bug #33280).
        $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 50.00, courseId: 44);

        $reopened = $this->issuer()->issue($original, ZatcaCreditNoteType::Full, [], 'Full again', (string) Str::uuid());
        $reopened->update(['accepted_at' => now()]);

        $this->assertSame(50.00, (float) $reopened->total_incl_vat);

        $ledger = $this->ledger($original);
        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['consumed']);
        $this->assertSame(338.00, $ledger->creditedTotalInclVat);
        $this->assertTrue($ledger->isFullyCredited());
    }

    /**
     * What BOTH note screens must show for one course, from the bug report's numbers:
     * 1,000.00 charged, an accepted Debit Note raised it by 200.00, an accepted Credit
     * Note price adjustment cut it by 100.00, so the course is worth 1,100.00 now.
     *
     * Neither flow could see the other's correction before: the Credit Note screen read
     * the invoice's frozen line, and the Debit Note screen read the same one.
     */
    public function test_a_course_reports_what_it_is_worth_after_both_kinds_of_adjustment(): void
    {
        $original = $this->reportedInvoice(8030, [['Course A', 1000.00]]);

        $this->assertSame(0.0, $this->ledger($original)->debitUpliftFor('line:0'));
        $this->assertSame(1000.00, $this->ledger($original)->currentValueFor('line:0', 1000.00));
        $this->assertSame([], $this->ledger($original)->adjustmentHistoryFor('line:0'));

        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);

        $this->assertSame(200.00, $this->ledger($original)->debitUpliftFor('line:0'));
        $this->assertSame(1200.00, $this->ledger($original)->currentValueFor('line:0', 1000.00));

        $adjustment = $this->adjust($original, 'line:0', 100.00);
        $adjustment->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);

        $this->assertSame(200.00, $ledger->debitUpliftFor('line:0'));
        $this->assertSame(100.00, $ledger->adjustmentFor('line:0'));
        $this->assertSame(1100.00, $ledger->currentValueFor('line:0', 1000.00));

        $this->assertSame([
            [
                'document_type' => 'debit_note',
                'document_number' => $debit->debit_note_number,
                'direction' => 'debit',
                'amount_incl_vat' => 200.00,
                'accepted' => true,
            ],
            [
                'document_type' => 'credit_note',
                'document_number' => (string) $adjustment->fresh()->credit_note_number,
                'direction' => 'credit',
                'amount_incl_vat' => 100.00,
                'accepted' => true,
            ],
        ], $ledger->adjustmentHistoryFor('line:0'));
    }

    /**
     * An adjustment ZATCA has not accepted yet already holds the course, so the admin has
     * to see it — listed, and marked as not accepted, exactly as it counts against the cap.
     */
    public function test_an_adjustment_still_in_flight_is_listed_and_marked_unaccepted(): void
    {
        $original = $this->reportedInvoice(8031, [['Course A', 1000.00]]);

        $this->adjust($original, 'line:0', 100.00);

        $history = $this->ledger($original)->adjustmentHistoryFor('line:0');

        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['accepted']);
        $this->assertSame(100.00, $history[0]['amount_incl_vat']);
        $this->assertSame(900.00, $this->ledger($original)->currentValueFor('line:0', 1000.00));
    }

    /**
     * From the bug report: 100.00 charged, an accepted Credit Note cut 50.00, an accepted
     * Debit Note raised it 100.00, and a Credit Note then gave that 100.00 back. The
     * reversed increase must stop counting: the course is worth 50.00, not 150.00.
     */
    public function test_an_increase_given_back_no_longer_counts_toward_the_course(): void
    {
        $original = $this->reportedInvoice(8039, [['Course A', 100.00]]);

        $cut = $this->adjust($original, 'line:0', 50.00);
        $cut->update(['accepted_at' => now()]);

        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 100.00, 0);
        $increaseId = (int) $debit->lines->first()->getKey();
        $this->assertSame(150.00, $this->ledger($original)->currentValueFor('line:0', 100.00));

        $reversal = $this->issue($original, [], [$increaseId]);

        // In flight already holds the increase, exactly like an adjustment in flight.
        $pending = $this->ledger($original);
        $this->assertSame(50.00, $pending->currentValueFor('line:0', 100.00));
        $this->assertFalse($pending->adjustmentHistoryFor('line:0')[2]['accepted']);

        $reversal->update(['accepted_at' => now()]);
        $ledger = $this->ledger($original);

        $this->assertSame(100.00, $ledger->debitUpliftFor('line:0'));
        $this->assertSame(150.00, $ledger->creditReductionFor('line:0'));
        // A reversed increase is credited as its own source, never against the course.
        $this->assertSame(50.00, $ledger->adjustmentFor('line:0'));
        $this->assertSame(50.00, $ledger->currentValueFor('line:0', 100.00));

        $this->assertSame([
            [
                'document_type' => 'debit_note',
                'document_number' => $debit->debit_note_number,
                'direction' => 'debit',
                'amount_incl_vat' => 100.00,
                'accepted' => true,
            ],
            [
                'document_type' => 'credit_note',
                'document_number' => (string) $cut->fresh()->credit_note_number,
                'direction' => 'credit',
                'amount_incl_vat' => 50.00,
                'accepted' => true,
            ],
            [
                'document_type' => 'credit_note',
                'document_number' => (string) $reversal->fresh()->credit_note_number,
                'direction' => 'credit',
                'amount_incl_vat' => 100.00,
                'accepted' => true,
            ],
        ], $ledger->adjustmentHistoryFor('line:0'));
    }

    /** A course a Debit Note ADDED carries its own history the same way an original line does. */
    public function test_a_debit_note_course_reports_the_increase_raised_on_it(): void
    {
        $original = $this->reportedInvoice(8032, [['Course A', 1000.00]]);

        $added = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalCourse, 300.00);
        $addedLineId = (int) $added->lines->first()->getKey();
        $key = CreditSource::debitNoteLineKey($addedLineId);

        $raise = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 50.00, null, null, true, false, $addedLineId);

        $ledger = $this->ledger($original);

        $this->assertSame(50.00, $ledger->debitUpliftFor($key));
        $this->assertSame(350.00, $ledger->currentValueFor($key, 300.00));
        $this->assertSame([[
            'document_type' => 'debit_note',
            'document_number' => $raise->debit_note_number,
            'direction' => 'debit',
            'amount_incl_vat' => 50.00,
            'accepted' => true,
        ]], $ledger->adjustmentHistoryFor($key));
    }

    /** Splits the VAT-inclusive difference at 15%, as a host with an inclusive price policy would. */
    private function adjust(ZatcaInvoice $original, string $sourceKey, float $gross): ZatcaCreditNote
    {
        $net = round($gross / 1.15, 2);

        return $this->issuer()->issuePriceAdjustment(
            $original->fresh(),
            $sourceKey,
            $net,
            round($gross - $net, 2),
            'Price adjustment',
            'Price adjustment reason',
            (string) Str::uuid(),
        )->fresh(['lines']);
    }

    /**
     * The cap a price adjustment is held to spans both note kinds: a course invoiced at
     * 1,000.00 that an accepted Debit Note raised by 200.00 may be corrected by anything
     * under 1,200.00, because 1,200.00 is what was charged for it.
     */
    public function test_an_adjustment_may_reach_the_whole_of_what_was_charged_for_the_course(): void
    {
        $original = $this->reportedInvoice(8033, [['Course A', 1000.00]]);
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);

        $ledger = $this->ledger($original);

        $this->assertSame(1000.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(1200.00, $ledger->mergedAdjustableFor('line:0'));

        $this->assertRefused(fn () => $this->adjust($original, 'line:0', 1200.00));
        $this->assertRefused(fn () => $this->adjust($original, 'line:0', 1500.00));
        $this->assertSame(0, ZatcaCreditNote::count());

        $this->assertSame(1199.00, (float) $this->adjust($original, 'line:0', 1199.00)->total_incl_vat);
    }

    /** An adjustment the course can absorb on its own leaves the increase alone — the ordinary case. */
    public function test_an_adjustment_within_the_course_leaves_the_increase_untouched(): void
    {
        $original = $this->reportedInvoice(8034, [['Course A', 1000.00]]);
        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);
        $increaseId = (int) $debit->lines->first()->getKey();

        $note = $this->adjust($original, 'line:0', 100.00);

        $this->assertCount(1, $note->lines);
        $this->assertSame(100.00, (float) $note->total_incl_vat);
        $this->assertSame('available', $this->ledger($original)->debitLineStates[$increaseId]);
    }

    /**
     * Reaching past the course's own value reverses the increase that paid for the rest,
     * in the SAME note: 1,050.00 is 850.00 off the course plus the 200.00 increase given
     * back. Afterwards the increase is credited, so cancelling the course pays only what
     * is left of the course itself — and the invoice closes on exactly what it charged.
     */
    public function test_an_adjustment_past_the_course_reverses_the_increase_that_paid_for_it(): void
    {
        $original = $this->reportedInvoice(8035, [['Course A', 1000.00]]);
        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);
        $increaseId = (int) $debit->lines->first()->getKey();

        $note = $this->adjust($original, 'line:0', 1050.00);

        $this->assertSame(1050.00, (float) $note->total_incl_vat);
        $this->assertCount(2, $note->lines);

        $adjustmentLine = $note->lines->firstWhere('source_type', CreditSource::TYPE_PRICE_ADJUSTMENT);
        $reversedLine = $note->lines->firstWhere('source_type', CreditSource::TYPE_DEBIT_NOTE_LINE);

        $this->assertSame(850.00, (float) $adjustmentLine->line_total_incl_vat);
        $this->assertSame(0, (int) $adjustmentLine->original_line_index);
        $this->assertSame(200.00, (float) $reversedLine->line_total_incl_vat);
        $this->assertSame($increaseId, (int) $reversedLine->source_debit_note_line_id);

        $note->update(['accepted_at' => now()]);
        $ledger = $this->ledger($original);

        // The increase is spent: nothing may credit it a second time.
        $this->assertSame('credited', $ledger->debitLineStates[$increaseId]);
        $this->assertNotContains($increaseId, $ledger->availableDebitLineIds());
        $this->assertSame(850.00, $ledger->lineAdjustments[0]);
        $this->assertSame(150.00, $ledger->remainingCreditableInclVat);

        $this->assertSame(150.00, (float) $this->issue($original, [0], [])->total_incl_vat);

        $closed = $this->ledger($original);
        $this->assertSame(0.0, $closed->remainingCreditableInclVat);
        $this->assertSame(1200.00, $closed->creditableTotal());
    }

    /**
     * An increase is credited whole or not at all, so an amount that needs part of one and
     * cannot be absorbed by what is left of the course is refused rather than approximated.
     */
    public function test_an_amount_that_needs_half_an_increase_is_refused(): void
    {
        $original = $this->reportedInvoice(8036, [['Course A', 1000.00]]);
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);

        $this->adjust($original, 'line:0', 950.00)->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);
        $this->assertSame(50.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(250.00, $ledger->mergedAdjustableFor('line:0'));

        // Needs 50.00 of the 200.00 increase, which cannot be split.
        $this->assertRefused(fn () => $this->adjust($original, 'line:0', 100.00));
        // Reverses the increase and takes nothing off the course: that is crediting the
        // increase, which is issued by naming it, not by adjusting the course.
        $this->assertRefused(fn () => $this->adjust($original, 'line:0', 200.00));

        // 10.00 off the course plus the whole increase back.
        $note = $this->adjust($original, 'line:0', 210.00);
        $this->assertSame(210.00, (float) $note->total_incl_vat);
        $this->assertCount(2, $note->lines);
    }

    /** Cancelling such a note gives BOTH parts back: the course's room and the increase. */
    public function test_cancelling_a_reversing_adjustment_releases_the_increase_again(): void
    {
        $original = $this->reportedInvoice(8038, [['Course A', 1000.00]]);
        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 200.00, 0);
        $increaseId = (int) $debit->lines->first()->getKey();

        $note = $this->adjust($original, 'line:0', 1050.00);
        $this->assertSame('reserved', $this->ledger($original)->debitLineStates[$increaseId]);

        app(CreditNoteCanceller::class)->cancel($note->fresh(), null);

        $ledger = $this->ledger($original);
        $this->assertSame('available', $ledger->debitLineStates[$increaseId]);
        $this->assertArrayNotHasKey(0, $ledger->lineAdjustments);
        $this->assertSame(1200.00, $ledger->mergedAdjustableFor('line:0'));
    }

    private function assertRefused(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the adjustment to be refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, $e->reason);
        }
    }

    private function issuer(): CreditNoteIssuer
    {
        return app(CreditNoteIssuer::class);
    }

    /**
     * @param  int[]  $lineIndexes
     * @param  int[]  $debitNoteLineIds
     */
    private function issue(ZatcaInvoice $original, array $lineIndexes, array $debitNoteLineIds): ZatcaCreditNote
    {
        return $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            $lineIndexes,
            'Refund',
            (string) Str::uuid(),
            null,
            null,
            $debitNoteLineIds,
        )->fresh(['lines']);
    }

    private function ledger(ZatcaInvoice $original): CreditNoteLedgerSnapshot
    {
        return app(CreditNoteLedger::class)->forOriginal($original->fresh());
    }

    private function verdict(ZatcaInvoice $original): CreditNoteEligibilityVerdict
    {
        return app(CreditNoteEligibilityPolicy::class)->verdict($original->fresh());
    }

    private function debitNote(
        ZatcaInvoice $original,
        ZatcaDebitNoteReason $reason,
        float $gross,
        ?int $referencedIndex = null,
        ?int $courseId = null,
        bool $accept = true,
        bool $vatOnly = false,
        ?int $referencedDebitLineId = null,
    ): ZatcaDebitNote {
        $net = $vatOnly ? 0.00 : round($gross / 1.15, 2);
        $vat = round($gross - $net, 2);

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original->fresh(),
            $reason,
            [new DebitNoteLineInput(
                description: $reason === ZatcaDebitNoteReason::AdditionalCourse ? 'Course B' : 'Adjustment',
                quantity: 1,
                unitPrice: $net,
                vatRate: 15.0,
                lineTotalExclVat: $net,
                vatAmount: $vat,
                originalLineIndex: $referencedIndex,
                courseId: $courseId,
                sourceDebitNoteLineId: $referencedDebitLineId,
            )],
            'Debit note reason',
            (string) Str::uuid(),
        );

        if ($accept) {
            $debitNote->update(['accepted_at' => now()]);
        }

        return $debitNote->fresh(['lines']);
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $lines  description, VAT-inclusive value
     * @param  array{0: float, 1: string, 2: int[]}|null  $bundle  discount, pool key, covered line indexes
     */
    private function reportedInvoice(int $sourceId, array $lines = [['Course A', 200.00]], ?array $bundle = null): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $source = new class($lines, $bundle) implements \Zatca\Contracts\InvoiceSourceContract
        {
            public function __construct(private readonly array $lines, private readonly ?array $bundle) {}

            public function toInvoiceData(mixed $source, string $egsIdentifier): \Zatca\DTOs\ZatcaInvoiceData
            {
                $invoiceLines = [];
                $net = 0.0;
                $vat = 0.0;

                foreach ($this->lines as [$description, $gross]) {
                    $lineNet = round($gross / 1.15, 2);
                    $lineVat = round($gross - $lineNet, 2);
                    $invoiceLines[] = new \Zatca\DTOs\ZatcaInvoiceLine($description, 1, $lineNet, 15.0, $lineNet, $lineVat);
                    $net += $lineNet;
                    $vat += $lineVat;
                }

                $allowances = [];

                if ($this->bundle !== null) {
                    [$discount, $poolKey, $indexes] = $this->bundle;
                    $discountNet = round($discount / 1.15, 2);
                    $discountVat = round($discount - $discountNet, 2);
                    $allowances[] = new \Zatca\DTOs\ZatcaAllowanceCharge('Bundle Discount', $discountNet, 15.0, $discountVat, $poolKey, $indexes);
                    $net -= $discountNet;
                    $vat -= $discountVat;
                }

                return new \Zatca\DTOs\ZatcaInvoiceData(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Original Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new \DateTimeImmutable('2026-09-01T10:00:00+03:00'),
                    currency: 'SAR',
                    lines: $invoiceLines,
                    totalExclVat: round($net, 2),
                    totalVat: round($vat, 2),
                    totalInclVat: round($net + $vat, 2),
                    allowanceCharges: $allowances,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'payment:'.(is_object($source) ? $source->id : $source);
            }
        };

        $invoice = app(ProductionInvoiceOrchestrator::class)->generate($source, (object) ['id' => $sourceId], self::EGS);

        $invoice->update([
            'status' => \Zatca\Enums\ZatcaInvoiceStatus::Reported,
            'reported_at' => now(),
        ]);

        return $invoice->fresh();
    }
}
