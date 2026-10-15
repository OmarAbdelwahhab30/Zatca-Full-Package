<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteReason;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteEligibilityVerdict;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\CreditNote\CreditNoteLedgerSnapshot;
use Zatca\Services\CreditNote\CreditSource;
use Zatca\Services\CreditNote\PriceAdjustmentInput;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Credit Note price adjustments: crediting the difference when a item was charged
 * more than its correct price, without taking the item back.
 *
 * The invoice from the bug report throughout, unless a test says otherwise:
 *
 *   Original invoice   Item A   700.00   (correct price 500.00)
 *   Price adjustment   Item A  -200.00
 *
 * The invariants every test protects: the adjusted item stays creditable, what is
 * later credited for it is only what is left, and cumulative credits still close on
 * exactly what was invoiced.
 */
class CreditNotePriceAdjustmentTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            \Zatca\Jobs\GenerateZatcaCreditNoteJob::class,
            \Zatca\Jobs\GenerateZatcaDebitNoteJob::class,
            \Zatca\Jobs\ReportZatcaInvoiceJob::class,
        ]);
    }

    public function test_a_price_adjustment_credits_the_difference_and_leaves_the_item_available(): void
    {
        $original = $this->reportedInvoice(9101);

        $note = $this->adjust($original, 'line:0', 200.00);

        $this->assertSame(ZatcaCreditNoteReason::PriceAdjustment, $note->reason_type);
        $this->assertTrue($note->isPriceAdjustment());
        $this->assertSame(ZatcaCreditNoteType::Partial, $note->type);
        $this->assertSame(200.00, (float) $note->total_incl_vat);
        $this->assertSame(173.91, (float) $note->total_excl_vat);
        $this->assertSame(26.09, (float) $note->total_vat);

        $line = $note->lines->sole();
        $this->assertSame(CreditSource::TYPE_PRICE_ADJUSTMENT, $line->source_type);
        $this->assertSame(0, (int) $line->original_line_index);
        $this->assertNull($line->source_debit_note_line_id);
        $this->assertSame(15.00, (float) $line->vat_rate);
        $this->assertSame('pa:'.$note->id.':line:0', $line->active_line_key);

        $snapshot = InvoiceDataSnapshot::fromArray($note->document_snapshot);
        $this->assertSame($original->source_reference, $snapshot->billingReferenceId);
        $this->assertSame('Price adjustment reason', $snapshot->adjustmentReason);
        $this->assertSame(['تسوية سعر - Item A'], array_map(fn ($l) => $l->description, $snapshot->lines));

        $pending = $this->ledger($original);
        $this->assertSame(200.00, $pending->lineAdjustments[0]);
        $this->assertSame(200.00, $pending->pendingTotalInclVat);
        $this->assertSame(500.00, $pending->remainingCreditableInclVat);
        $this->assertFalse($pending->isFullyCredited());

        $note->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);
        $this->assertSame('available', $ledger->lineStates[0]);
        $this->assertSame([0], $ledger->availableIndexes);
        $this->assertSame(500.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(200.00, $ledger->creditedTotalInclVat);
        $this->assertSame(500.00, $ledger->remainingCreditableInclVat);
    }

    public function test_an_accepted_price_adjustment_makes_the_invoice_partially_credited_not_fully(): void
    {
        $original = $this->reportedInvoice(9102);

        $this->adjust($original, 'line:0', 200.00)->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);
        $this->assertSame(200.00, $ledger->creditedTotalInclVat);
        $this->assertTrue($ledger->isPartiallyCredited());
        $this->assertFalse($ledger->isFullyCredited());
        $this->assertTrue($ledger->hasAvailableSources());
    }

    /** The bug report's arithmetic, carried through a later cancellation: 200 now, 500 later, 700 in all. */
    public function test_cancelling_an_adjusted_item_credits_only_what_is_left(): void
    {
        $original = $this->reportedInvoice(9103);
        $this->adjust($original, 'line:0', 200.00)->update(['accepted_at' => now()]);

        $cancellation = $this->cancelLines($original, [0]);

        $this->assertSame(ZatcaCreditNoteReason::ItemCancellation, $cancellation->reason_type);
        $this->assertSame(500.00, (float) $cancellation->total_incl_vat);
        $this->assertSame(CreditSource::TYPE_INVOICE_LINE, $cancellation->lines->sole()->source_type);

        $cancellation->update(['accepted_at' => now()]);

        $ledger = $this->ledger($original);
        $this->assertTrue($ledger->isFullyCredited());
        $this->assertSame(700.00, $ledger->creditedTotalInclVat);
        $this->assertSame(0.00, $ledger->remainingCreditableInclVat);
    }

    /**
     * An adjustment in flight holds its item. Cancelling the item for what is left
     * would lose the adjusted amount for good if ZATCA then rejected the adjustment.
     */
    public function test_an_adjustment_in_flight_holds_its_item_until_accepted(): void
    {
        $original = $this->reportedInvoice(9104, [['Item A', 700.00], ['Item B', 300.00]]);
        $adjustment = $this->adjust($original, 'line:0', 200.00);

        $held = $this->ledger($original);
        $this->assertSame('reserved', $held->lineStates[0]);
        $this->assertSame([1], $held->availableIndexes);
        $this->assertNull($held->adjustableFor('line:0'));

        // And while it is in flight nothing else may be issued against the invoice.
        $this->assertRefused(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, fn () => $this->cancelLines($original, [0]));
        $this->assertRefused(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, fn () => $this->adjust($original, 'line:0', 50.00));
        $this->assertRefused(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, fn () => $this->cancelLines($original, [1]));

        $adjustment->update(['accepted_at' => now()]);

        $this->assertSame(500.00, (float) $this->cancelLines($original, [0])->total_incl_vat);
    }

    /** A rejected adjustment that is cancelled gives the item back its whole value. */
    public function test_a_cancelled_adjustment_leaves_the_item_creditable_in_full(): void
    {
        $original = $this->reportedInvoice(9111);
        $adjustment = $this->adjust($original, 'line:0', 200.00);

        app(CreditNoteCanceller::class)->cancel($adjustment);

        $this->assertSame('available', $this->ledger($original)->lineStates[0]);
        $this->assertSame(700.00, (float) $this->cancelLines($original, [0])->total_incl_vat);
    }

    public function test_an_adjustment_must_stay_strictly_below_what_is_left_of_the_item(): void
    {
        $original = $this->reportedInvoice(9105);

        $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 700.00));
        $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 800.00));

        $this->adjust($original, 'line:0', 200.00)->update(['accepted_at' => now()]);

        // What is left is 500 now, not 700.
        $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 500.00));

        $second = $this->adjust($original, 'line:0', 499.99);
        $this->assertSame(499.99, (float) $second->total_incl_vat);

        $second->update(['accepted_at' => now()]);
        $this->assertSame(0.01, $this->ledger($original)->adjustableFor('line:0'));

        $this->assertSame(2, ZatcaCreditNote::count());
    }

    public function test_a_zero_or_negative_adjustment_is_refused(): void
    {
        $original = $this->reportedInvoice(9106);

        $this->assertRefused(CreditNoteException::ZERO_VALUE, fn () => $this->issuer()->issuePriceAdjustment(
            $original->fresh(), 'line:0', 0.00, 'x', 'reason', (string) Str::uuid()
        ));
        $this->assertRefused(CreditNoteException::ZERO_VALUE, fn () => $this->issuer()->issuePriceAdjustment(
            $original->fresh(), 'line:0', -5.00, 'x', 'reason', (string) Str::uuid()
        ));

        $this->assertSame(0, ZatcaCreditNote::count());
    }

    public function test_cancelling_an_adjustment_releases_its_amount(): void
    {
        $original = $this->reportedInvoice(9107);
        $note = $this->adjust($original, 'line:0', 200.00);

        app(CreditNoteCanceller::class)->cancel($note);

        $ledger = $this->ledger($original);
        $this->assertSame([], $ledger->lineAdjustments);
        $this->assertSame(700.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(700.00, $ledger->remainingCreditableInclVat);
        $this->assertNull(ZatcaCreditNoteLine::query()->where('credit_note_id', $note->id)->value('active_line_key'));

        $this->assertSame(700.00, (float) $this->cancelLines($original, [0])->total_incl_vat);
    }

    public function test_a_reserved_or_credited_item_cannot_be_adjusted(): void
    {
        $original = $this->reportedInvoice(9108, [['Item A', 700.00], ['Item B', 300.00]]);

        $reserved = $this->cancelLines($original, [0]);
        $this->assertRefused(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, fn () => $this->adjust($original, 'line:0', 100.00));

        $reserved->update(['accepted_at' => now()]);
        $this->assertRefused(CreditNoteException::NOT_ADJUSTABLE, fn () => $this->adjust($original, 'line:0', 100.00));

        // The other item is untouched by either.
        $this->assertSame(100.00, (float) $this->adjust($original, 'line:1', 100.00)->total_incl_vat);
    }

    public function test_only_a_item_of_this_invoice_can_be_adjusted(): void
    {
        $original = $this->reportedInvoice(9109);
        $vatLine = $this->debitNote($original, ZatcaDebitNoteReason::VatAdjustment, 15.00, referencedIndex: 0, vatOnly: true)->lines->sole();
        $priceLine = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 50.00, referencedIndex: 0)->lines->sole();

        foreach (['line:1', 'line:99', 'dn_line:'.$vatLine->id, 'dn_line:'.$priceLine->id, 'dn_line:999999', 'item:1', ''] as $key) {
            $this->assertRefused(CreditNoteException::NOT_ADJUSTABLE, fn () => $this->adjust($original, $key, 10.00));
        }

        $this->assertSame(0, ZatcaCreditNote::count());
    }

    /** A item a debit note added can be adjusted too, and crediting it later takes only what is left. */
    public function test_an_additional_item_from_a_debit_note_can_be_adjusted(): void
    {
        $original = $this->reportedInvoice(9110);
        $debitNote = $this->debitNote($original, ZatcaDebitNoteReason::AdditionalItem, 100.00, itemId: 55);
        $debitLineId = $debitNote->lines->sole()->id;

        $this->assertSame(100.00, $this->ledger($original)->adjustableFor('dn_line:'.$debitLineId));

        $note = $this->adjust($original, 'dn_line:'.$debitLineId, 30.00);
        $this->assertSame('reserved', $this->ledger($original)->debitLineStates[$debitLineId]);
        $note->update(['accepted_at' => now()]);

        $line = $note->lines->sole();
        $this->assertSame(CreditSource::TYPE_PRICE_ADJUSTMENT, $line->source_type);
        $this->assertNull($line->original_line_index);
        $this->assertSame((int) $debitNote->id, (int) $line->source_debit_note_id);
        $this->assertSame((int) $debitLineId, (int) $line->source_debit_note_line_id);
        $this->assertSame(55, (int) $line->item_id);

        $ledger = $this->ledger($original);
        $this->assertSame('available', $ledger->debitLineStates[$debitLineId]);
        $this->assertSame(70.00, $ledger->adjustableFor('dn_line:'.$debitLineId));
        // The original line's own value is untouched.
        $this->assertSame(700.00, $ledger->adjustableFor('line:0'));

        $credit = $this->issuer()->issue(
            $original->fresh(), ZatcaCreditNoteType::Partial, [], 'Cancel', (string) Str::uuid(), null, null, [$debitLineId]
        );

        $this->assertSame(70.00, (float) $credit->total_incl_vat);
        $this->assertSame(70.00, (float) $credit->lines->sole()->line_total_incl_vat);
        $this->assertSame('dn_line:'.$debitLineId, $credit->lines->sole()->active_line_key);
    }

    /**
     * A bundle item worth less than the discount left is refused, until a price
     * settlement moves value onto it: 5.00 from B to A makes A worth 17.00, the whole
     * 15.00 discount is drawn against that, and cancelling A credits 2.00 — the line
     * absorbs 12.00 of the discount and its price increase the last 3.00.
     */
    public function test_a_price_increase_counts_toward_what_a_bundle_item_absorbs_of_the_discount(): void
    {
        $original = $this->reportedInvoice(9140, [['Item A', 12.00], ['Item B', 200.00]], [15.00, 'bundle:1', [0, 1]]);

        $this->assertRefused(CreditNoteException::ZERO_VALUE, fn () => $this->cancelLines($original, [0]));

        $this->adjust($original, 'line:1', 5.00)->update(['accepted_at' => now()]);
        $debit = $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 5.00, 0);
        $debitLineId = $debit->lines->sole()->id;

        $note = $this->cancelLines($original, [0]);

        $this->assertSame(2.00, (float) $note->total_incl_vat);
        $lines = $note->lines->keyBy('source_type');
        $this->assertSame(0.00, (float) $lines[CreditSource::TYPE_INVOICE_LINE]->line_total_incl_vat);
        $this->assertSame(15.00, (float) $lines[CreditSource::TYPE_INVOICE_LINE]->discount_applied_incl_vat);
        $this->assertSame(2.00, (float) $lines[CreditSource::TYPE_DEBIT_NOTE_LINE]->line_total_incl_vat);
        $this->assertSame((int) $debitLineId, (int) $lines[CreditSource::TYPE_DEBIT_NOTE_LINE]->source_debit_note_line_id);
        $this->assertSame(0.00, $this->ledger($original)->discountPools['bundle:1']['available']);
        $note->update(['accepted_at' => now()]);

        // B is credited what is left of it, and the whole sale closes: 197 + 5 charged,
        // 5 + 2 + 195 credited.
        $this->assertSame(195.00, (float) $this->cancelLines($original, [1])->total_incl_vat);
    }

    /** The settlement does not rescue a item still worth less than the discount. */
    public function test_a_price_increase_too_small_to_cover_the_discount_is_still_refused(): void
    {
        $original = $this->reportedInvoice(9141, [['Item A', 12.00], ['Item B', 200.00]], [15.00, 'bundle:1', [0, 1]]);
        $this->debitNote($original, ZatcaDebitNoteReason::PriceAdjustment, 2.00, 0);

        $this->assertRefused(CreditNoteException::ZERO_VALUE, fn () => $this->cancelLines($original, [0]));
        $this->assertSame(15.00, $this->ledger($original)->discountPools['bundle:1']['available']);
    }

    /**
     * On a bundle a item's worth depends on how much of the shared discount it ends
     * up absorbing, so the cap assumes the worst: all of it. Either cancellation order
     * then closes on exactly what the bundle charged.
     */
    public function test_a_bundle_item_is_capped_at_its_worst_case_value_and_closes_in_either_order(): void
    {
        foreach ([[0, 1], [1, 0]] as $run => $order) {
            $original = $this->reportedInvoice(9120 + $run, [['Item A', 120.00], ['Item B', 200.00]], [32.00, 'bundle:1', [0, 1]]);

            $this->assertSame(88.00, $this->ledger($original)->adjustableFor('line:0'));
            $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 88.00));

            $this->adjust($original, 'line:0', 80.00)->update(['accepted_at' => now()]);

            // Adjusting touches no discount.
            $this->assertSame(32.00, $this->ledger($original)->discountPools['bundle:1']['available']);

            $total = 80.00;

            foreach ($order as $index) {
                $credit = $this->cancelLines($original, [$index]);
                $this->assertGreaterThan(0.0, (float) $credit->total_incl_vat);
                $credit->update(['accepted_at' => now()]);
                $total = round($total + (float) $credit->total_incl_vat, 2);
            }

            $this->assertSame(288.00, $total, 'Order '.implode(',', $order));

            $ledger = $this->ledger($original);
            $this->assertTrue($ledger->isFullyCredited());
            $this->assertSame(288.00, $ledger->creditedTotalInclVat);
        }
    }

    /** Discount a pending note only reserves may come back, so it still counts against the cap. */
    public function test_a_bundle_cap_ignores_discount_that_is_only_reserved(): void
    {
        $original = $this->reportedInvoice(9130, [['Item A', 120.00], ['Item B', 200.00]], [32.00, 'bundle:1', [0, 1]]);

        $pending = $this->cancelLines($original, [1]); // reserves the whole 32.00 discount
        $this->assertSame(0.00, $this->ledger($original)->discountPools['bundle:1']['available']);
        // Reserved, not consumed: the cap still assumes the 32.00 may come back to Item A.
        $this->assertSame(88.00, $this->ledger($original)->adjustableFor('line:0'));
        // Nothing may be issued while that note is in progress.
        $this->assertRefused(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, fn () => $this->adjust($original, 'line:0', 87.99));

        app(CreditNoteCanceller::class)->cancel($pending);
        $this->assertSame(88.00, $this->ledger($original)->adjustableFor('line:0'));
        $this->adjust($original, 'line:0', 87.99)->update(['accepted_at' => now()]);

        // The discount is back and lands on Item A; its credit is still positive.
        $this->assertSame(0.01, (float) $this->cancelLines($original, [0])->total_incl_vat);
    }

    public function test_replaying_the_same_request_returns_the_same_note(): void
    {
        $original = $this->reportedInvoice(9140);
        $key = (string) Str::uuid();

        $first = $this->adjust($original, 'line:0', 200.00, $key);
        $second = $this->adjust($original, 'line:0', 200.00, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ZatcaCreditNote::count());
        $this->assertSame(1, ZatcaCreditNoteLine::count());
        Queue::assertPushed(\Zatca\Jobs\GenerateZatcaCreditNoteJob::class, 1);
    }

    public function test_a_confirmed_amount_that_no_longer_matches_is_refused(): void
    {
        $original = $this->reportedInvoice(9141);

        $this->assertRefused(CreditNoteException::AMOUNT_CHANGED, fn () => $this->issuer()->issuePriceAdjustment(
            $original->fresh(), 'line:0', 200.00, 'x', 'reason', (string) Str::uuid(), null, 150.00
        ));

        $this->assertSame(0, ZatcaCreditNote::count());
    }

    /**
     * The rule a bundle item is held to: whatever is left of it after the adjustment
     * must still be worth more than the discount it could absorb — item minus the
     * adjustments standing against it, strictly greater than the remaining discount.
     *
     * Item A 100.00 on a 20.00 bundle, so A may be adjusted by anything under 80.00.
     * A smaller adjustment is not "too small": the discount is untouched by it and the
     * item is still worth cancelling afterwards, which is the whole point of the rule.
     */
    public function test_a_bundle_item_must_stay_worth_more_than_the_discount_after_adjusting(): void
    {
        $original = $this->reportedInvoice(9131, [['Item A', 100.00], ['Item B', 50.00]], [20.00, 'bundle:1', [0, 1]]);

        $this->assertSame(80.00, $this->ledger($original)->adjustableFor('line:0'));

        $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 80.00));
        $this->assertRefused(CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE, fn () => $this->adjust($original, 'line:0', 90.00));
        $this->assertSame(0, ZatcaCreditNote::count());

        $small = $this->adjust($original, 'line:0', 10.00);
        $this->assertSame(10.00, (float) $small->total_incl_vat);

        $small->update(['accepted_at' => now()]);
        $this->assertSame(20.00, $this->ledger($original)->discountPools['bundle:1']['available']);
        $this->assertSame(70.00, (float) $this->cancelLines($original, [0])->total_incl_vat);
    }

    /**
     * Several items corrected by ONE note: each keeps its own amount and its own line,
     * and the note is worth their sum. The bug report's shape — 200.00, 150.00, 300.00.
     */
    public function test_one_note_can_correct_several_items_at_once(): void
    {
        $original = $this->reportedInvoice(9140, [['Item A', 700.00], ['Item B', 400.00], ['Item C', 500.00]]);

        $note = $this->adjustMany($original, ['line:0' => 200.00, 'line:1' => 150.00, 'line:2' => 300.00]);

        $this->assertSame(650.00, (float) $note->total_incl_vat);
        $this->assertCount(3, $note->lines);
        $this->assertSame(
            [200.00, 150.00, 300.00],
            $note->lines->map(fn ($line) => (float) $line->line_total_incl_vat)->all()
        );
        $this->assertSame([0, 1, 2], $note->lines->map(fn ($line) => (int) $line->original_line_index)->all());

        // One claim per item, so the unique active_line_key cannot collide.
        $this->assertSame([
            'pa:'.$note->id.':line:0',
            'pa:'.$note->id.':line:1',
            'pa:'.$note->id.':line:2',
        ], $note->lines->map(fn ($line) => $line->active_line_key)->all());

        $note->update(['accepted_at' => now()]);
        $ledger = $this->ledger($original);

        $this->assertSame([0 => 200.00, 1 => 150.00, 2 => 300.00], $ledger->lineAdjustments);
        // Every item stays creditable for what is left of it.
        $this->assertSame([0, 1, 2], $ledger->availableIndexes);
        $this->assertSame(500.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(250.00, $ledger->adjustableFor('line:1'));
    }

    /** The signed document states every item, and its totals are the sum of them. */
    public function test_the_signed_document_states_every_corrected_item(): void
    {
        $original = $this->reportedInvoice(9141, [['Item A', 700.00], ['Item B', 400.00]]);

        $note = $this->adjustMany($original, ['line:0' => 230.00, 'line:1' => 115.00]);

        (new \Zatca\Jobs\GenerateZatcaCreditNoteJob($note->id))->handle(
            app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class),
            app(\Zatca\Support\ZatcaGate::class)
        );

        $xml = ZatcaInvoice::where('credit_note_id', $note->id)->firstOrFail()->unsigned_xml;

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Generated XML must be well-formed.');

        $this->assertSame(2, substr_count($xml, '<cac:InvoiceLine>'));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxInclusiveAmount currencyID="SAR">345.00</cbc:TaxInclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="SAR">300.00</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="SAR">45.00</cbc:TaxAmount>', $xml);
        $this->assertStringContainsString('<cbc:ID>'.$original->source_reference.'</cbc:ID>', $xml);

        $this->assertSame(345.00, (float) $note->fresh()->total_incl_vat);
    }

    /**
     * One item refused takes the whole note with it. A partially applied correction is
     * not something an admin can reason about, so nothing is written at all.
     */
    public function test_one_item_over_its_value_refuses_the_whole_note(): void
    {
        $original = $this->reportedInvoice(9142, [['Item A', 700.00], ['Item B', 400.00]]);

        $this->assertRefused(
            CreditNoteException::ADJUSTMENT_EXCEEDS_VALUE,
            fn () => $this->adjustMany($original, ['line:0' => 200.00, 'line:1' => 400.00])
        );

        $this->assertSame(0, ZatcaCreditNote::count());
        $this->assertSame(0, ZatcaCreditNoteLine::count());
        $this->assertSame([], $this->ledger($original)->lineAdjustments);
    }

    /** Two amounts for one item is a request nobody meant to make. */
    public function test_the_same_item_cannot_be_named_twice_in_one_note(): void
    {
        $original = $this->reportedInvoice(9143, [['Item A', 700.00]]);

        $this->assertRefused(CreditNoteException::NOT_ADJUSTABLE, fn () => $this->issuer()->issuePriceAdjustments(
            $original->fresh(),
            [
                $this->adjustmentInput('line:0', 100.00),
                $this->adjustmentInput('line:0', 50.00),
            ],
            'Price adjustment reason',
            (string) Str::uuid(),
        ));

        $this->assertSame(0, ZatcaCreditNote::count());
    }

    /**
     * The two kinds of item share a note as readily as two original ones: an invoice
     * line and a item an accepted Debit Note added, each corrected by its own amount and
     * each measured against its own cap.
     */
    public function test_one_note_can_correct_an_original_item_and_an_added_one(): void
    {
        $original = $this->reportedInvoice(9144, [['Item A', 700.00]]);
        $addedLineId = (int) $this->debitNote($original, ZatcaDebitNoteReason::AdditionalItem, 500.00, itemId: 77)
            ->lines->first()->getKey();
        $addedKey = CreditSource::debitNoteLineKey($addedLineId);

        $note = $this->adjustMany($original, ['line:0' => 200.00, $addedKey => 150.00]);

        $this->assertSame(350.00, (float) $note->total_incl_vat);
        $this->assertCount(2, $note->lines);

        $note->update(['accepted_at' => now()]);
        $ledger = $this->ledger($original);

        $this->assertSame(200.00, $ledger->lineAdjustments[0]);
        $this->assertSame(150.00, $ledger->debitLineAdjustments[$addedLineId]);
        $this->assertSame(500.00, $ledger->adjustableFor('line:0'));
        $this->assertSame(350.00, $ledger->adjustableFor($addedKey));
        // Both items are still there to credit for what is left of them.
        $this->assertSame([0], $ledger->availableIndexes);
        $this->assertSame([$addedLineId], $ledger->availableDebitLineIds());
    }

    /**
     * @param  array<string, float>  $amounts  source key => VAT-inclusive difference
     */
    private function adjustMany(ZatcaInvoice $original, array $amounts, ?string $key = null): ZatcaCreditNote
    {
        $inputs = [];

        foreach ($amounts as $sourceKey => $gross) {
            $inputs[] = $this->adjustmentInput((string) $sourceKey, (float) $gross);
        }

        return $this->issuer()->issuePriceAdjustments(
            $original->fresh(),
            $inputs,
            'Price adjustment reason',
            $key ?? (string) Str::uuid(),
        )->fresh(['lines']);
    }

    /** The VAT-inclusive difference; the package splits it at the item's own rate. */
    private function adjustmentInput(string $sourceKey, float $gross): PriceAdjustmentInput
    {
        return new PriceAdjustmentInput($sourceKey, $gross, 'تسوية سعر - '.$sourceKey);
    }

    private function issuer(): CreditNoteIssuer
    {
        return app(CreditNoteIssuer::class);
    }

    /** The VAT-inclusive difference; the package splits it at the item's own rate. */
    private function adjust(ZatcaInvoice $original, string $sourceKey, float $gross, ?string $key = null): ZatcaCreditNote
    {
        return $this->issuer()->issuePriceAdjustment(
            $original->fresh(),
            $sourceKey,
            $gross,
            'تسوية سعر - Item A',
            'Price adjustment reason',
            $key ?? (string) Str::uuid(),
        )->fresh(['lines']);
    }

    /** @param  int[]  $lineIndexes */
    private function cancelLines(ZatcaInvoice $original, array $lineIndexes): ZatcaCreditNote
    {
        return $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            $lineIndexes,
            'Cancel',
            (string) Str::uuid(),
        )->fresh(['lines']);
    }

    private function assertRefused(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected the request to be refused with [{$reason}].");
        } catch (CreditNoteException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function ledger(ZatcaInvoice $original): CreditNoteLedgerSnapshot
    {
        return app(CreditNoteLedger::class)->forOriginal($original->fresh());
    }

    private function debitNote(
        ZatcaInvoice $original,
        ZatcaDebitNoteReason $reason,
        float $gross,
        ?int $referencedIndex = null,
        ?int $itemId = null,
        bool $vatOnly = false,
    ): ZatcaDebitNote {
        $description = $reason === ZatcaDebitNoteReason::AdditionalItem ? 'Item B' : 'Adjustment';

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original->fresh(),
            $reason,
            [$vatOnly
                ? DebitNoteLineInput::vatAdjustment($description, $gross, $referencedIndex)
                : new DebitNoteLineInput($description, 1, $gross, originalLineIndex: $referencedIndex, itemId: $itemId)],
            'Debit note reason',
            (string) Str::uuid(),
        );

        $debitNote->update(['accepted_at' => now()]);

        return $debitNote->fresh(['lines']);
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $lines  description, VAT-inclusive value
     * @param  array{0: float, 1: string, 2: int[]}|null  $bundle  discount, pool key, covered line indexes
     */
    private function reportedInvoice(int $sourceId, array $lines = [['Item A', 700.00]], ?array $bundle = null): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $source = new class($lines, $bundle) implements \Zatca\Contracts\InvoiceSourceContract
        {
            public function __construct(private readonly array $lines, private readonly ?array $bundle) {}

            public function toInvoiceInput(mixed $source, string $egsIdentifier): \Zatca\DTOs\ZatcaInvoiceInput
            {
                // VAT-inclusive prices at the default 15%: the package takes the VAT out.
                $items = array_map(
                    static fn (array $line) => new \Zatca\DTOs\ZatcaInvoiceItem($line[0], 1, $line[1]),
                    $this->lines,
                );

                $discounts = [];

                if ($this->bundle !== null) {
                    [$discount, $poolKey, $indexes] = $this->bundle;
                    $discounts[] = new \Zatca\DTOs\ZatcaInvoiceDiscount('Bundle Discount', $discount, poolKey: $poolKey, lineIndexes: $indexes);
                }

                return new \Zatca\DTOs\ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Original Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new \DateTimeImmutable('2026-09-01T10:00:00+03:00'),
                    currency: 'SAR',
                    items: $items,
                    discounts: $discounts,
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
