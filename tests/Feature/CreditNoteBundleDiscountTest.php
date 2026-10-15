<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteCanceller;
use Zatca\Services\CreditNote\CreditNoteEligibilityVerdict;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * End-to-end cover for crediting a BUNDLE invoice — one whose lines state each
 * item's original price and whose discount sits in a document-level allowance.
 *
 * The invoice under test throughout is the one from the bug report:
 *
 *   Item A   120.00
 *   Item B   200.00
 *   Bundle discount  -32.00
 *   Payable    288.00
 *
 * Every test is ultimately asserting one thing — that however the items are
 * credited, in whatever order, across however many notes, the cumulative credits
 * never exceed 288.00, and equal it exactly once everything is credited.
 */
class CreditNoteBundleDiscountTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([\Zatca\Jobs\GenerateZatcaCreditNoteJob::class]);
    }

    /**
     * The bug: crediting one bundle item used to hand back its full original
     * price (120.00), ignoring the discount that item was sold under.
     */
    public function test_crediting_the_first_bundle_item_consumes_the_discount(): void
    {
        $original = $this->reportedBundleInvoice(7001);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0],
            'Item A refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(88.00, (float) $creditNote->total_incl_vat);

        $line = $creditNote->lines->firstWhere('original_line_index', 0);
        $this->assertSame('bundle:1', $line->discount_pool_key);
        $this->assertSame(32.00, (float) $line->discount_applied_incl_vat);

        // Net and VAT must reconcile with the credited amount, not with the
        // original line — the document is validated on its own arithmetic.
        $this->assertSame(88.00, round((float) $creditNote->total_excl_vat + (float) $creditNote->total_vat, 2));
    }

    /** …and the second item is then credited in full, totalling exactly 288.00. */
    public function test_sequential_partial_credits_total_the_invoiced_amount(): void
    {
        $original = $this->reportedBundleInvoice(7002);

        $first = $this->issueAccepted($original, [0], 'A refunded');
        $second = $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            [1],
            'B refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(88.00, (float) $first->total_incl_vat);
        $this->assertSame(200.00, (float) $second->total_incl_vat);
        $this->assertSame(288.00, round((float) $first->total_incl_vat + (float) $second->total_incl_vat, 2));
    }

    /** The reverse order gives different amounts and the same total. */
    public function test_crediting_the_larger_item_first_still_totals_the_invoiced_amount(): void
    {
        $original = $this->reportedBundleInvoice(7003);

        $first = $this->issueAccepted($original, [1], 'B refunded');
        $second = $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            [0],
            'A refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(168.00, (float) $first->total_incl_vat);
        $this->assertSame(120.00, (float) $second->total_incl_vat);
        $this->assertSame(288.00, round((float) $first->total_incl_vat + (float) $second->total_incl_vat, 2));
    }

    /**
     * Full credit must close on 288.00, not on the 320.00 its two lines add up to.
     * This is the regression that would over-credit an entire bundle discount.
     */
    public function test_full_credit_closes_on_the_invoiced_total_not_the_line_total(): void
    {
        $original = $this->reportedBundleInvoice(7004);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Full,
            [],
            'Whole bundle refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(288.00, (float) $creditNote->total_incl_vat);
        $this->assertNotSame(320.00, (float) $creditNote->total_incl_vat);
        $this->assertCount(2, $creditNote->lines);
    }

    /** A pending note's discount is reserved — a second note cannot spend it again. */
    public function test_a_pending_credit_note_reserves_the_discount(): void
    {
        $original = $this->reportedBundleInvoice(7005);

        // Not accepted: still pending, so its 32.00 is reserved rather than consumed.
        $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'A refunded', (string) Str::uuid());

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());

        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['reserved']);
        $this->assertSame(0.0, $ledger->discountPools['bundle:1']['consumed']);
        $this->assertSame(0.0, $ledger->discountPools['bundle:1']['available']);

        // And no second note may be issued against this invoice until the first settles.
        try {
            $this->issuer()->issue($original->fresh(), ZatcaCreditNoteType::Partial, [1], 'B refunded', (string) Str::uuid());
            $this->fail('A second note while one is in progress should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS, $e->reason);
        }
    }

    /** Accepting moves the discount from reserved to consumed; it is never double-counted. */
    public function test_accepting_moves_the_discount_from_reserved_to_consumed(): void
    {
        $original = $this->reportedBundleInvoice(7006);

        $this->issueAccepted($original, [0], 'A refunded');

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());

        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['consumed']);
        $this->assertSame(0.0, $ledger->discountPools['bundle:1']['reserved']);
        $this->assertSame(0.0, $ledger->discountPools['bundle:1']['available']);
    }

    /**
     * Cancelling a note releases its discount along with its lines — one mechanism,
     * because the ledger only counts lines that still hold their claim.
     */
    public function test_cancelling_a_credit_note_releases_the_discount(): void
    {
        $original = $this->reportedBundleInvoice(7007);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0],
            'A refunded',
            (string) Str::uuid(),
        );

        app(CreditNoteCanceller::class)->cancel($creditNote);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());

        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['available']);
        $this->assertSame(0.0, $ledger->discountPools['bundle:1']['reserved']);

        // And the discount is genuinely spendable again.
        $reissued = $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            [0],
            'A refunded again',
            (string) Str::uuid(),
        );

        $this->assertSame(88.00, (float) $reissued->total_incl_vat);
    }

    /**
     * Replaying the same idempotency key returns the same note without allocating a
     * second time — the guarantee that a retried submit cannot spend the discount twice.
     */
    public function test_replaying_the_same_request_does_not_allocate_the_discount_twice(): void
    {
        $original = $this->reportedBundleInvoice(7008);
        $key = (string) Str::uuid();

        $first = $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'A refunded', $key);
        $second = $this->issuer()->issue($original->fresh(), ZatcaCreditNoteType::Partial, [0], 'A refunded', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ZatcaCreditNote::query()->where('original_zatca_invoice_id', $original->id)->count());

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['reserved']);
    }

    /**
     * A selection worth nothing once the discount is applied is refused rather than
     * filed as a zero-value document — and, because the transaction rolls back, the
     * discount it would have consumed stays available.
     */
    public function test_a_selection_worth_nothing_after_the_discount_is_refused(): void
    {
        // Item A is 20.00 against a 30.00 discount: crediting it alone returns nothing.
        $original = $this->reportedBundleInvoice(7009, [20.00, 100.00], 30.00);

        try {
            $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'A refunded', (string) Str::uuid());
            $this->fail('A zero-value Credit Note should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::ZERO_VALUE, $e->reason);
        }

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());

        $this->assertSame(30.00, $ledger->discountPools['bundle:1']['available']);
        $this->assertSame([0, 1], $ledger->availableIndexes);
    }

    /** A item the remaining discount covers exactly (20.00 against 20.00) is refused the same way. */
    public function test_a_item_the_remaining_discount_covers_exactly_is_refused(): void
    {
        $original = $this->reportedBundleInvoice(7019, [20.00, 100.00], 20.00);

        try {
            $this->issuer()->issue($original, ZatcaCreditNoteType::Partial, [0], 'A refunded', (string) Str::uuid());
            $this->fail('A zero-value Credit Note should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::ZERO_VALUE, $e->reason);
        }
    }

    /**
     * A item that was free — 0.00 on the invoice with no discount drawn — is cancelled
     * by a 0.00 note: there is no discount to protect, and it is the only way to take it
     * back. (A coupon also leaves a 0.00 line; the host refuses that one, since only the
     * item's Transaction records the coupon.)
     */
    public function test_a_free_item_is_cancelled_at_zero(): void
    {
        $original = $this->reportedInvoice(7020, $this->source([['Item A', 0.00], ['Item B', 115.00]], []));

        $creditNote = $this->issueAccepted($original, [0], 'A refunded');

        $this->assertSame(0.00, (float) $creditNote->total_incl_vat);
        $this->assertSame('credited', app(CreditNoteLedger::class)->forOriginal($original->fresh())->lineStates[0]);
    }

    /**
     * The same items credited TOGETHER are issuable, even though the cheaper one
     * credits nothing on its own: the note as a whole is worth 90.00, so only its
     * first line is zero. This is the route an admin has to a bundle whose discount
     * exceeds one of its items.
     */
    public function test_a_item_worth_nothing_can_still_be_credited_alongside_another(): void
    {
        $original = $this->reportedBundleInvoice(7017, [20.00, 100.00], 30.00);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0, 1],
            'Both refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(90.00, (float) $creditNote->total_incl_vat);

        $cheap = $creditNote->lines->firstWhere('original_line_index', 0);
        $this->assertSame(0.00, (float) $cheap->line_total_incl_vat);
        $this->assertSame(20.00, (float) $cheap->discount_applied_incl_vat);

        $expensive = $creditNote->lines->firstWhere('original_line_index', 1);
        $this->assertSame(90.00, (float) $expensive->line_total_incl_vat);
        $this->assertSame(10.00, (float) $expensive->discount_applied_incl_vat);
    }

    /**
     * The loophole this guards against: a bundle item worth less than its discount
     * credits nothing on its own, and alone it is cancelled at 0.00. But pairing it with
     * a item from OUTSIDE its bundle gives the note positive value from the unrelated
     * item, hiding that the bundle item still drained its discount for free.
     */
    public function test_a_worthless_bundle_item_cannot_be_hidden_behind_an_unrelated_item(): void
    {
        // Item A is 20.00 against a 30.00 discount: worthless by itself. Item B keeps
        // the bundle paid for — one its discount covers entirely was sold for nothing.
        $original = $this->reportedInvoice(7018, $this->source(
            [['Item A', 20.00], ['Item C', 300.00], ['Item B', 100.00]],
            [[30.00, 'bundle:1', [0, 2]]],
        ));

        try {
            $this->issuer()->issue(
                $original,
                ZatcaCreditNoteType::Partial,
                [0, 1],
                'A and C refunded',
                (string) Str::uuid(),
            );
            $this->fail('A worthless bundle line hidden behind an unrelated item should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::WORTHLESS_BUNDLE_LINE, $e->reason);
        }

        // Refused inside the transaction, so nothing was issued and the discount is intact.
        $this->assertSame(0, ZatcaCreditNote::query()->where('original_zatca_invoice_id', $original->id)->count());

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame(30.00, $ledger->discountPools['bundle:1']['available']);
    }

    /**
     * A bundle item that was free in its own right (priced 0) drew nothing from the
     * discount, so it is the zero the rules exempt: it may be cancelled alongside a item
     * from outside its bundle, and the bundle's discount is left whole.
     */
    public function test_a_free_bundle_item_may_be_cancelled_alongside_an_unrelated_item(): void
    {
        $original = $this->reportedInvoice(7021, $this->source(
            [['Item A', 0.00], ['Item B', 100.00], ['Item D', 200.00]],
            [[30.00, 'bundle:1', [0, 1]]],
        ));

        $creditNote = $this->issueAccepted($original, [0, 2], 'A and D refunded');

        $this->assertSame(200.00, (float) $creditNote->total_incl_vat);
        $this->assertSame(0.00, (float) $creditNote->lines->firstWhere('original_line_index', 0)->discount_applied_incl_vat);
        $this->assertSame(30.00, app(CreditNoteLedger::class)->forOriginal($original->fresh())->discountPools['bundle:1']['available']);
    }

    /** A free bundle item alone is a 0.00 cancellation with no discount drawn: issued. */
    public function test_a_free_bundle_item_alone_is_cancelled_at_zero(): void
    {
        $original = $this->reportedInvoice(7022, $this->source(
            [['Item A', 0.00], ['Item B', 100.00]],
            [[30.00, 'bundle:1', [0, 1]]],
        ));

        $this->assertSame(0.00, (float) $this->issueAccepted($original, [0], 'A refunded')->total_incl_vat);
    }

    /**
     * The free item does not shield a bundle item the discount DID make worthless: with
     * that item (10.00 against a 30.00 discount) the selection is a discounted zero and is
     * refused — alone, and alongside a item from outside the bundle.
     */
    public function test_a_free_bundle_item_does_not_shield_a_item_the_discount_made_worthless(): void
    {
        $original = $this->reportedInvoice(7023, $this->source(
            [['Item A', 0.00], ['Item B', 10.00], ['Item C', 100.00], ['Item D', 200.00]],
            [[30.00, 'bundle:1', [0, 1, 2]]],
        ));

        foreach ([[[0, 1], CreditNoteException::ZERO_VALUE], [[0, 1, 3], CreditNoteException::WORTHLESS_BUNDLE_LINE]] as [$lines, $reason]) {
            try {
                $this->issuer()->issue($original->fresh(), ZatcaCreditNoteType::Partial, $lines, 'x', (string) Str::uuid());
                $this->fail('A selection the discount made worthless should have been refused.');
            } catch (CreditNoteException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $this->assertSame(30.00, app(CreditNoteLedger::class)->forOriginal($original->fresh())->discountPools['bundle:1']['available']);
    }

    /**
     * A bundle its discount covered entirely (12 + 2 against 14) was sold for nothing, so
     * its items were free: they go back at 0.00 alone, together, or beside a item
     * from outside the bundle.
     */
    public function test_the_items_of_a_bundle_sold_for_nothing_are_cancelled_like_free_ones(): void
    {
        $cases = [
            7024 => [[0], 0.00],
            7025 => [[0, 1], 0.00],
            7026 => [[0, 2], 50.00],
            7027 => [[0, 1, 2], 50.00],
        ];

        foreach ($cases as $sourceId => [$lines, $total]) {
            $original = $this->reportedInvoice($sourceId, $this->source(
                [['Item A', 12.00], ['Item B', 2.00], ['Item C', 50.00]],
                [[14.00, 'bundle:1', [0, 1]]],
            ));

            $this->assertSame($total, (float) $this->issueAccepted($original, $lines, 'refunded')->total_incl_vat, implode(',', $lines));
        }
    }

    /** An amount confirmed by the admin that no longer holds is refused. */
    public function test_a_stale_confirmed_amount_is_refused(): void
    {
        $original = $this->reportedBundleInvoice(7010);

        try {
            $this->issuer()->issue(
                $original,
                ZatcaCreditNoteType::Partial,
                [0],
                'A refunded',
                (string) Str::uuid(),
                null,
                // What the admin would have seen if they had not been shown the discount.
                120.00,
            );
            $this->fail('A stale expected total should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::AMOUNT_CHANGED, $e->reason);
        }

        $this->assertSame(0, ZatcaCreditNote::query()->where('original_zatca_invoice_id', $original->id)->count());
    }

    /** The confirmed amount matching what the lock computes issues normally. */
    public function test_a_matching_confirmed_amount_is_accepted(): void
    {
        $original = $this->reportedBundleInvoice(7011);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0],
            'A refunded',
            (string) Str::uuid(),
            null,
            88.00,
        );

        $this->assertSame(88.00, (float) $creditNote->total_incl_vat);
    }

    /**
     * A item sharing the invoice but not the bundle is credited at its own value,
     * and the bundle's discount is left entirely intact for the bundle's items.
     */
    public function test_a_non_bundle_item_is_unaffected_by_the_bundle_discount(): void
    {
        $original = $this->reportedMixedInvoice(7012);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [2],
            'Item C refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(300.00, (float) $creditNote->total_incl_vat);

        $line = $creditNote->lines->firstWhere('original_line_index', 2);
        $this->assertNull($line->discount_pool_key);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['available']);
    }

    /**
     * An ordinary invoice with no allowance must behave EXACTLY as it did before any
     * of this existed: lines copied verbatim, no pools, no allocation recorded.
     */
    public function test_an_invoice_without_a_bundle_is_credited_exactly_as_before(): void
    {
        $original = $this->reportedPlainInvoice(7013);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0],
            'A refunded',
            (string) Str::uuid(),
        );

        $this->assertSame(115.00, (float) $creditNote->total_incl_vat);

        $line = $creditNote->lines->firstWhere('original_line_index', 0);
        $this->assertNull($line->discount_pool_key);
        $this->assertNull($line->discount_applied_incl_vat);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame([], $ledger->discountPools);
    }

    /** Two bundles on one invoice draw on their own discounts only. */
    public function test_two_bundles_on_one_invoice_stay_isolated(): void
    {
        $original = $this->reportedTwoBundleInvoice(7014);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Partial,
            [0, 2],
            'One item from each bundle',
            (string) Str::uuid(),
        );

        // 120-32 from bundle 1, 100-20 from bundle 2 — never 120-52 or 100-52.
        $this->assertSame(168.00, (float) $creditNote->total_incl_vat);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original->fresh());
        $this->assertSame(32.00, $ledger->discountPools['bundle:1']['reserved']);
        $this->assertSame(20.00, $ledger->discountPools['bundle:2']['reserved']);
    }

    /** Crediting every item of both bundles closes on both bundles' invoiced totals. */
    public function test_full_credit_across_two_bundles_closes_exactly(): void
    {
        $original = $this->reportedTwoBundleInvoice(7015);

        $creditNote = $this->issuer()->issue(
            $original,
            ZatcaCreditNoteType::Full,
            [],
            'Everything refunded',
            (string) Str::uuid(),
        );

        // (120+200-32) + (100+50-20) = 288 + 130
        $this->assertSame(418.00, (float) $creditNote->total_incl_vat);
    }

    /**
     * An invoice whose allowance never recorded which lines it covers is refused
     * rather than credited on a guess.
     */
    public function test_an_allowance_without_line_membership_blocks_issuance(): void
    {
        $original = $this->reportedBundleInvoice(7016);

        // Simulate a document signed before line membership was frozen.
        $snapshot = $original->invoice_data_snapshot;
        $snapshot['allowance_charges'][0]['line_indexes'] = [];
        $snapshot['allowance_charges'][0]['pool_key'] = null;
        $original->update(['invoice_data_snapshot' => $snapshot]);

        try {
            $this->issuer()->issue($original->fresh(), ZatcaCreditNoteType::Partial, [0], 'A', (string) Str::uuid());
            $this->fail('An unresolvable discount pool should have been refused.');
        } catch (CreditNoteException $e) {
            $this->assertSame(CreditNoteException::DISCOUNT_POOL_UNRESOLVED, $e->reason);
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function issuer(): CreditNoteIssuer
    {
        return app(CreditNoteIssuer::class);
    }

    /** Issues a note and marks it accepted, so its discount counts as consumed. */
    private function issueAccepted(ZatcaInvoice $original, array $lineIndexes, string $reason): ZatcaCreditNote
    {
        $creditNote = $this->issuer()->issue(
            $original->fresh(),
            ZatcaCreditNoteType::Partial,
            $lineIndexes,
            $reason,
            (string) Str::uuid(),
        );

        $creditNote->update(['accepted_at' => now()]);

        return $creditNote->fresh();
    }

    /** @param  float[]  $grossValues */
    private function reportedBundleInvoice(
        int $sourceId,
        array $grossValues = [120.00, 200.00],
        float $discountGross = 32.00,
    ): ZatcaInvoice {
        return $this->reportedInvoice($sourceId, $this->bundleSource($grossValues, $discountGross));
    }

    private function reportedMixedInvoice(int $sourceId): ZatcaInvoice
    {
        return $this->reportedInvoice($sourceId, $this->source(
            [['Item A', 120.00], ['Item B', 200.00], ['Item C', 300.00]],
            [[32.00, 'bundle:1', [0, 1]]],
        ));
    }

    private function reportedTwoBundleInvoice(int $sourceId): ZatcaInvoice
    {
        return $this->reportedInvoice($sourceId, $this->source(
            [['A1', 120.00], ['A2', 200.00], ['B1', 100.00], ['B2', 50.00]],
            [[32.00, 'bundle:1', [0, 1]], [20.00, 'bundle:2', [2, 3]]],
        ));
    }

    private function reportedPlainInvoice(int $sourceId): ZatcaInvoice
    {
        return $this->reportedInvoice($sourceId, $this->source([['Item A', 115.00]], []));
    }

    private function reportedInvoice(int $sourceId, \Zatca\Contracts\InvoiceSourceContract $source): ZatcaInvoice
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($source, (object) ['id' => $sourceId], self::EGS);

        $invoice->update([
            'status' => \Zatca\Enums\ZatcaInvoiceStatus::Reported,
            'reported_at' => now(),
        ]);

        return $invoice->fresh();
    }

    /** @param  float[]  $grossValues */
    private function bundleSource(array $grossValues, float $discountGross): \Zatca\Contracts\InvoiceSourceContract
    {
        $lines = [];

        foreach ($grossValues as $position => $gross) {
            $lines[] = ['Item '.chr(65 + $position), $gross];
        }

        return $this->source($lines, [[$discountGross, 'bundle:1', array_keys($grossValues)]]);
    }

    /**
     * Builds the shape PaymentInvoiceSource produces for a bundle purchase: lines at
     * their ORIGINAL VAT-inclusive prices, with the discount as a document allowance.
     *
     * @param  array<int, array{0: string, 1: float}>  $lines
     * @param  array<int, array{0: float, 1: string, 2: int[]}>  $allowances
     */
    private function source(array $lines, array $allowances): \Zatca\Contracts\InvoiceSourceContract
    {
        return new class($lines, $allowances) implements \Zatca\Contracts\InvoiceSourceContract
        {
            public function __construct(
                private readonly array $lines,
                private readonly array $allowances,
            ) {}

            public function toInvoiceInput(mixed $source, string $egsIdentifier): \Zatca\DTOs\ZatcaInvoiceInput
            {
                // VAT-inclusive prices and discounts at the default 15%: the package takes the VAT out.
                return new \Zatca\DTOs\ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Original Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new \DateTimeImmutable('2026-09-01T10:00:00+03:00'),
                    currency: 'SAR',
                    items: array_map(
                        static fn (array $line) => new \Zatca\DTOs\ZatcaInvoiceItem($line[0], 1, $line[1]),
                        $this->lines,
                    ),
                    discounts: array_map(
                        static fn (array $allowance) => new \Zatca\DTOs\ZatcaInvoiceDiscount(
                            'Bundle Discount', $allowance[0], poolKey: $allowance[1], lineIndexes: $allowance[2]
                        ),
                        $this->allowances,
                    ),
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'payment:'.(is_object($source) ? $source->id : $source);
            }
        };
    }
}
