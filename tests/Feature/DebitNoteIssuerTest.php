<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteEligibilityPolicy;
use Zatca\Services\DebitNote\DebitNoteEligibilityVerdict;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Exercises DebitNoteIssuer against a REPORTED original (built by driving the real
 * orchestrator, not by inserting rows), covering the behaviours that differ from the
 * Credit Note path as well as the ones that must not.
 */
class DebitNoteIssuerTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            \Zatca\Jobs\GenerateZatcaDebitNoteJob::class,
            \Zatca\Jobs\GenerateZatcaCreditNoteJob::class,
        ]);
    }

    public function test_an_additional_course_debit_note_records_its_charge_and_course(): void
    {
        $original = $this->reportedOriginal(7001);

        $debitNote = $this->issuer()->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Course D', 1, 200.00, 15.0, 200.00, 30.00, null, courseId: 44)],
            'إضافة دورة تدريبية لم تكن ضمن الفاتورة الأصلية',
            (string) Str::uuid(),
        );

        $this->assertSame('DN-'.str_pad((string) $debitNote->id, 6, '0', STR_PAD_LEFT), $debitNote->debit_note_number);
        $this->assertSame(ZatcaDebitNoteReason::AdditionalCourse, $debitNote->reason_type);
        $this->assertSame(200.00, (float) $debitNote->total_excl_vat);
        $this->assertSame(30.00, (float) $debitNote->total_vat);
        $this->assertSame(230.00, (float) $debitNote->total_incl_vat);

        $line = $debitNote->lines()->first();
        $this->assertSame(44, (int) $line->course_id);
        // An added course was never on the original, so it references no original line.
        $this->assertNull($line->original_line_index);
    }

    /**
     * A VAT Adjustment charges ONLY under-collected VAT: a zero net line. The arithmetic
     * validator must accept it (zero is not negative), which is the whole reason this
     * scenario is worth a test of its own.
     */
    public function test_a_vat_adjustment_may_have_a_zero_net_line(): void
    {
        $original = $this->reportedOriginal(7002);

        $debitNote = $this->issuer()->issue(
            $original,
            ZatcaDebitNoteReason::VatAdjustment,
            [new DebitNoteLineInput('تسوية ضريبة القيمة المضافة', 1, 0.00, 15.0, 0.00, 7.50, originalLineIndex: 0)],
            'تصحيح ضريبة القيمة المضافة المحتسبة على الفاتورة الأصلية',
            (string) Str::uuid(),
        );

        $this->assertSame(0.00, (float) $debitNote->total_excl_vat);
        $this->assertSame(7.50, (float) $debitNote->total_vat);
        $this->assertSame(7.50, (float) $debitNote->total_incl_vat);
        $this->assertSame(0, (int) $debitNote->lines()->first()->original_line_index);
    }

    /**
     * The defining difference from a Credit Note: nothing is consumed, so an invoice
     * never runs out of debit-note capacity and two notes may reference the SAME
     * original line — one after the other, once the first has settled.
     */
    public function test_multiple_debit_notes_may_be_issued_against_the_same_invoice_and_line(): void
    {
        $original = $this->reportedOriginal(7003);

        $first = $this->issueSimple($original, 'first', 100.00, 15.00, originalLineIndex: 0);
        $first->update(['accepted_at' => now()]);
        $second = $this->issueSimple($original, 'second', 50.00, 7.50, originalLineIndex: 0);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ZatcaDebitNote::count());

        // In progress blocks the next one; settled, the invoice is eligible again.
        $this->assertSame(
            DebitNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS,
            app(DebitNoteEligibilityPolicy::class)->verdict($original->fresh())->reason,
        );
        $second->update(['accepted_at' => now()]);
        $this->assertTrue(app(DebitNoteEligibilityPolicy::class)->allows($original->fresh()));
    }

    /** No note of either kind may be issued while a Debit Note against the invoice is in progress. */
    public function test_nothing_may_be_issued_while_a_debit_note_is_in_progress(): void
    {
        $original = $this->reportedOriginal(7021);
        $pending = $this->issueSimple($original, 'first', 100.00, 15.00, originalLineIndex: 0);

        $this->assertRefusedReference(
            $original,
            new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, originalLineIndex: 0),
            DebitNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS,
        );

        try {
            $this->creditNote($original, [0]);
            $this->fail('A Credit Note while a Debit Note is in progress should have been refused.');
        } catch (\Zatca\Exceptions\CreditNoteException $e) {
            $this->assertSame(\Zatca\Services\CreditNote\CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, $e->reason);
        }

        // Cancelled is final too: the invoice takes notes again.
        $pending->update(['cancelled_at' => now()]);
        $this->assertTrue(app(DebitNoteEligibilityPolicy::class)->allows($original->fresh()));
    }

    /** Replaying the same idempotency key returns the SAME note, never a second one. */
    public function test_the_same_idempotency_key_returns_the_same_note(): void
    {
        $original = $this->reportedOriginal(7004);
        $key = (string) Str::uuid();

        $first = $this->issuer()->issue($original, ZatcaDebitNoteReason::Other, [$this->line(100.00, 15.00)], 'x', $key);
        $second = $this->issuer()->issue($original, ZatcaDebitNoteReason::Other, [$this->line(100.00, 15.00)], 'x', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ZatcaDebitNote::count());
    }

    /** The original invoice row is never touched by issuing a Debit Note against it. */
    public function test_the_original_invoice_row_is_untouched(): void
    {
        $original = $this->reportedOriginal(7005);
        $before = $original->toArray();

        $this->issueSimple($original, 'x', 100.00, 15.00, originalLineIndex: 0);

        $after = ZatcaInvoice::find($original->id)->toArray();
        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after);
    }

    public function test_a_line_referencing_a_missing_original_index_is_refused(): void
    {
        $original = $this->reportedOriginal(7006);

        $this->expectException(DebitNoteException::class);

        // The fake original has exactly one line, so index 9 cannot exist.
        $this->issueSimple($original, 'x', 100.00, 15.00, originalLineIndex: 9);
    }

    public function test_an_empty_line_set_is_refused(): void
    {
        $original = $this->reportedOriginal(7007);

        $this->expectException(DebitNoteException::class);

        $this->issuer()->issue($original, ZatcaDebitNoteReason::Other, [], 'x', (string) Str::uuid());
    }

    public function test_a_line_whose_arithmetic_is_negative_is_refused_before_anything_is_created(): void
    {
        $original = $this->reportedOriginal(7008);

        try {
            $this->issuer()->issue(
                $original,
                ZatcaDebitNoteReason::Other,
                [new DebitNoteLineInput('bad', 1, -10.00, 15.0, -10.00, -1.50)],
                'x',
                (string) Str::uuid(),
            );
            $this->fail('A negative line should not have produced a Debit Note.');
        } catch (\Zatca\Exceptions\ZatcaException) {
            // Expected — and the transaction must have rolled the record back with it.
        }

        $this->assertSame(0, ZatcaDebitNote::count());
    }

    public function test_a_non_reported_original_is_not_eligible(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $draft = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7009], self::EGS);
        $draft->update(['status' => \Zatca\Enums\ZatcaInvoiceStatus::PendingReporting]);

        $verdict = app(DebitNoteEligibilityPolicy::class)->verdict($draft->fresh());
        $this->assertFalse($verdict->eligible);
        $this->assertSame(DebitNoteEligibilityVerdict::NOT_REPORTED, $verdict->reason);

        $this->expectException(DebitNoteException::class);
        $this->issueSimple($draft->fresh(), 'x', 100.00, 15.00);
    }

    /** A note may not be issued against a note. */
    public function test_a_debit_note_may_not_be_issued_against_another_note(): void
    {
        $original = $this->reportedOriginal(7010);
        $original->update(['document_type' => \Zatca\Enums\ZatcaComplianceDocumentType::DebitNote->value]);

        $verdict = app(DebitNoteEligibilityPolicy::class)->verdict($original->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(DebitNoteEligibilityVerdict::NOT_AN_INVOICE, $verdict->reason);
    }

    public function test_it_records_a_requested_audit_event(): void
    {
        $original = $this->reportedOriginal(7011);

        $debitNote = $this->issueSimple($original, 'x', 100.00, 15.00);

        $event = $debitNote->events()->where('event', 'requested')->first();
        $this->assertNotNull($event);
        $this->assertSame(115.0, (float) $event->detail['total_incl_vat']);
    }

    /**
     * A course an earlier Debit Note added is not a line of the original invoice, so a
     * price adjustment of it references that debit note line instead.
     */
    public function test_a_price_adjustment_may_reference_a_course_an_accepted_debit_note_added(): void
    {
        $original = $this->reportedOriginal(7012);
        $added = $this->additionalCourse($original, courseId: 44);

        $adjustment = $this->issuer()->issue(
            $original->fresh(),
            ZatcaDebitNoteReason::PriceAdjustment,
            [new DebitNoteLineInput('تسوية سعر - Course D', 1, 50.00, 15.0, 50.00, 7.50, sourceDebitNoteLineId: $added->id)],
            'x',
            (string) Str::uuid(),
        );

        $line = $adjustment->lines()->first();
        $this->assertSame($added->id, (int) $line->source_debit_note_line_id);
        $this->assertNull($line->original_line_index);
        $this->assertNull($line->course_id);
        $this->assertSame($added->id, $line->sourceDebitNoteLine->id);
        $this->assertSame(57.50, (float) $adjustment->total_incl_vat);
    }

    /** Only an ACCEPTED debit note has added anything; a pending one references nothing. */
    public function test_a_reference_to_a_course_from_a_debit_note_that_is_not_accepted_is_refused(): void
    {
        $original = $this->reportedOriginal(7013);
        $pending = $this->additionalCourse($original, courseId: 44, accept: false);
        $reference = new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, sourceDebitNoteLineId: $pending->id);

        // While it is pending nothing may be issued against the invoice at all.
        $this->assertRefusedReference($original, $reference, DebitNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS);

        // Cancelled, it never added anything, so its course is not a reference either.
        $pending->debitNote->update(['cancelled_at' => now()]);
        $this->assertRefusedReference($original, $reference);
    }

    /** A price adjustment line is itself a correction, not a course that has a price. */
    public function test_a_reference_to_a_debit_note_line_that_did_not_add_a_course_is_refused(): void
    {
        $original = $this->reportedOriginal(7014);
        $adjustment = $this->issueSimple($original, 'x', 100.00, 15.00, originalLineIndex: 0);
        $adjustment->update(['accepted_at' => now()]);

        $this->assertRefusedReference($original, new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, sourceDebitNoteLineId: $adjustment->lines()->first()->id));
    }

    public function test_a_reference_to_a_course_added_to_another_invoice_is_refused(): void
    {
        $other = $this->reportedOriginal(7015);
        $added = $this->additionalCourse($other, courseId: 44);
        $original = $this->reportedOriginal(7016);

        $this->assertRefusedReference($original, new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, sourceDebitNoteLineId: $added->id));
    }

    public function test_a_line_may_not_reference_both_an_original_line_and_an_added_course(): void
    {
        $original = $this->reportedOriginal(7017);
        $added = $this->additionalCourse($original, courseId: 44);

        $this->assertRefusedReference($original, new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, originalLineIndex: 0, sourceDebitNoteLineId: $added->id));
    }

    /** Bug #33280: a line an accepted Credit Note refunded cannot be charged more for. */
    public function test_a_price_adjustment_of_a_credited_original_line_is_refused(): void
    {
        $original = $this->reportedOriginal(7018);
        $this->creditNote($original, [0])->update(['accepted_at' => now()]);

        $this->assertRefusedReference(
            $original,
            new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, originalLineIndex: 0),
            DebitNoteException::LINE_NOT_ADJUSTABLE,
        );
    }

    /** A line a Credit Note in flight holds is refused until that note settles — here, is cancelled. */
    public function test_a_line_held_by_a_credit_note_in_flight_is_refused_until_it_is_released(): void
    {
        $original = $this->reportedOriginal(7019);
        $pending = $this->creditNote($original, [0]);
        $line = new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, originalLineIndex: 0);

        $this->assertRefusedReference($original, $line, DebitNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS);

        app(CreditNoteCanceller::class)->cancel($pending);

        $debitNote = $this->issuer()->issue($original->fresh(), ZatcaDebitNoteReason::PriceAdjustment, [$line], 'x', (string) Str::uuid());
        $this->assertSame(0, (int) $debitNote->lines()->first()->original_line_index);
    }

    /**
     * After a full credit only what is still open may be adjusted: a course a later debit
     * note added is, until it is credited back too.
     */
    public function test_a_credited_added_course_is_refused_while_an_uncredited_one_is_still_adjustable(): void
    {
        $original = $this->reportedOriginal(7020);
        $this->creditNote($original, [0])->update(['accepted_at' => now()]);
        $added = $this->additionalCourse($original, courseId: 44);
        $adjustAdded = new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, sourceDebitNoteLineId: $added->id);

        $this->assertRefusedReference(
            $original,
            new DebitNoteLineInput('x', 1, 50.00, 15.0, 50.00, 7.50, originalLineIndex: 0),
            DebitNoteException::LINE_NOT_ADJUSTABLE,
        );
        $this->issuer()->issue($original->fresh(), ZatcaDebitNoteReason::PriceAdjustment, [$adjustAdded], 'x', (string) Str::uuid())
            ->update(['accepted_at' => now()]);

        $this->creditNote($original, [], [$added->id])->update(['accepted_at' => now()]);

        $this->assertRefusedReference($original, $adjustAdded, DebitNoteException::LINE_NOT_ADJUSTABLE);
    }

    /**
     * One Price Adjustment note may charge more for several items at once: an original
     * line and a course an accepted Debit Note added, each with its own amount and its
     * own reference. The note is worth their sum.
     */
    public function test_a_price_adjustment_may_correct_several_items_in_one_note(): void
    {
        $original = $this->reportedOriginal(7140);
        $added = $this->additionalCourse($original, 77);

        $note = $this->issuer()->issue(
            $original->fresh(),
            ZatcaDebitNoteReason::PriceAdjustment,
            [
                $this->line(20.00, 3.00, 0),
                new DebitNoteLineInput(
                    'Adjustment',
                    1,
                    40.00,
                    15.0,
                    40.00,
                    6.00,
                    sourceDebitNoteLineId: (int) $added->getKey(),
                ),
            ],
            'x',
            (string) Str::uuid(),
        );

        $this->assertCount(2, $note->fresh(['lines'])->lines);
        $this->assertSame(69.00, (float) $note->total_incl_vat);
        $this->assertSame(
            [0, null],
            $note->fresh(['lines'])->lines->map(fn ($line) => $line->original_line_index === null ? null : (int) $line->original_line_index)->all()
        );
    }

    /** Two lines naming the same item is a request nobody meant to make. */
    public function test_a_price_adjustment_refuses_two_lines_naming_the_same_item(): void
    {
        $original = $this->reportedOriginal(7141);

        $before = ZatcaDebitNote::count();

        try {
            $this->issuer()->issue(
                $original->fresh(),
                ZatcaDebitNoteReason::PriceAdjustment,
                [$this->line(20.00, 3.00, 0), $this->line(10.00, 1.50, 0)],
                'x',
                (string) Str::uuid(),
            );
            $this->fail('Expected the duplicate reference to be refused.');
        } catch (DebitNoteException $e) {
            $this->assertStringContainsString('line:0', $e->getMessage());
        }

        $this->assertSame($before, ZatcaDebitNote::count());
    }

    private function assertRefusedReference(
        ZatcaInvoice $original,
        DebitNoteLineInput $line,
        string $reason = DebitNoteException::INVALID_DEBIT_NOTE_LINE_REFERENCE,
    ): void {
        $before = ZatcaDebitNote::count();

        try {
            $this->issuer()->issue($original->fresh(), ZatcaDebitNoteReason::PriceAdjustment, [$line], 'x', (string) Str::uuid());
            $this->fail('The reference should have been refused.');
        } catch (DebitNoteException $e) {
            $this->assertSame($reason, $e->reason);
        }

        $this->assertSame($before, ZatcaDebitNote::count());
    }

    /**
     * @param  int[]  $lineIndexes
     * @param  int[]  $debitNoteLineIds
     */
    private function creditNote(ZatcaInvoice $original, array $lineIndexes, array $debitNoteLineIds = []): ZatcaCreditNote
    {
        return app(CreditNoteIssuer::class)->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            $lineIndexes,
            'Refund',
            (string) Str::uuid(),
            null,
            null,
            $debitNoteLineIds,
        );
    }

    /** @return \Zatca\Models\ZatcaDebitNoteLine the line that added the course */
    private function additionalCourse(ZatcaInvoice $original, int $courseId, bool $accept = true): \Zatca\Models\ZatcaDebitNoteLine
    {
        $debitNote = $this->issuer()->issue(
            $original->fresh(),
            ZatcaDebitNoteReason::AdditionalCourse,
            [new DebitNoteLineInput('Course D', 1, 200.00, 15.0, 200.00, 30.00, courseId: $courseId)],
            'x',
            (string) Str::uuid(),
        );

        if ($accept) {
            $debitNote->update(['accepted_at' => now()]);
        }

        return $debitNote->lines()->first();
    }

    private function issuer(): DebitNoteIssuer
    {
        return app(DebitNoteIssuer::class);
    }

    private function line(float $net, float $vat, ?int $originalLineIndex = null): DebitNoteLineInput
    {
        return new DebitNoteLineInput('Adjustment', 1, $net, 15.0, $net, $vat, $originalLineIndex);
    }

    private function issueSimple(ZatcaInvoice $original, string $reason, float $net, float $vat, ?int $originalLineIndex = null): ZatcaDebitNote
    {
        return $this->issuer()->issue(
            $original,
            ZatcaDebitNoteReason::PriceAdjustment,
            [$this->line($net, $vat, $originalLineIndex)],
            $reason,
            (string) Str::uuid(),
        );
    }

    private function reportedOriginal(int $sourceId): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], self::EGS);

        $invoice->update([
            'status' => \Zatca\Enums\ZatcaInvoiceStatus::Reported,
            'reported_at' => now(),
        ]);

        return $invoice->fresh();
    }
}
