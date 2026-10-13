<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Services\CreditNote\CreditNoteDiscountAllocator;
use Zatca\Tests\TestCase;
use DateTimeImmutable;

/**
 * The sequential bundle-discount rule, at the level it is actually decided.
 *
 * Every case here is stated in gross (VAT-inclusive) money, which is how the
 * business states it and how the allocator works — the VAT split happens later,
 * in CreditNoteDataFactory, against the frozen line.
 *
 * The invariant every test is really checking: whatever order the courses are
 * credited in, and however the discount lands across them, the cumulative credits
 * equal what the bundle was actually invoiced. Individual amounts move; the total
 * does not.
 */
class CreditNoteDiscountAllocatorTest extends TestCase
{
    /** A=120, B=200, discount 32 → invoiced 288. Credit A first: 120-32 = 88. */
    public function test_first_credited_course_consumes_the_whole_discount(): void
    {
        $original = $this->bundleInvoice([120.00, 200.00], 32.00);

        $allocation = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 32.00]);

        $this->assertSame(88.00, $allocation->creditFor(0));
        $this->assertSame(32.00, $allocation->discountFor(0));
        $this->assertSame('bundle:1', $allocation->poolFor(0));
    }

    /** …and the next course is then credited in full, so the two total 288. */
    public function test_second_course_is_credited_in_full_once_the_discount_is_spent(): void
    {
        $original = $this->bundleInvoice([120.00, 200.00], 32.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 32.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 0.00]);

        $this->assertSame(88.00, $first->creditFor(0));
        $this->assertSame(200.00, $second->creditFor(1));
        $this->assertSame(288.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /** The other order gives different individual amounts and the SAME total. */
    public function test_crediting_the_larger_course_first_still_totals_the_invoiced_amount(): void
    {
        $original = $this->bundleInvoice([120.00, 200.00], 32.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 32.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 0.00]);

        $this->assertSame(168.00, $first->creditFor(1));
        $this->assertSame(120.00, $second->creditFor(0));
        $this->assertSame(288.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /** Bug report example 1 — fixed discount: A=100, B=50, D=20, B credited first. */
    public function test_fixed_discount_example_credits_thirty_then_one_hundred(): void
    {
        $original = $this->bundleInvoice([100.00, 50.00], 20.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 20.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 0.00]);

        $this->assertSame(30.00, $first->creditFor(1));
        $this->assertSame(100.00, $second->creditFor(0));
        $this->assertSame(130.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /** Bug report example 2 — same bundle, A credited first: 80 then 50. */
    public function test_fixed_discount_example_in_the_other_order_credits_eighty_then_fifty(): void
    {
        $original = $this->bundleInvoice([100.00, 50.00], 20.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 20.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 0.00]);

        $this->assertSame(80.00, $first->creditFor(0));
        $this->assertSame(50.00, $second->creditFor(1));
        $this->assertSame(130.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /**
     * Bug report example 3 — 20% of 150 = 30. A percentage is converted to money at
     * purchase and reaches the allocator as an amount, so it needs no special path:
     * this is the fixed case with a different number.
     */
    public function test_percentage_discount_is_just_its_monetary_value(): void
    {
        $original = $this->bundleInvoice([100.00, 50.00], 30.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 30.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 0.00]);

        $this->assertSame(20.00, $first->creditFor(1));
        $this->assertSame(100.00, $second->creditFor(0));
        $this->assertSame(120.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /** Bug report example 4 — 10% of 150 = 15: B credits 35, A credits 100. */
    public function test_ten_percent_discount_example(): void
    {
        $original = $this->bundleInvoice([100.00, 50.00], 15.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 15.00]);
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 0.00]);

        $this->assertSame(35.00, $first->creditFor(1));
        $this->assertSame(100.00, $second->creditFor(0));
        $this->assertSame(135.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /**
     * The edge case: a discount larger than the first course credited. The credit is
     * floored at zero rather than going negative, and the unspent remainder carries
     * to the next note.
     */
    public function test_a_discount_larger_than_the_course_credits_zero_and_carries_the_remainder(): void
    {
        $original = $this->bundleInvoice([20.00, 100.00], 30.00);

        $first = (new CreditNoteDiscountAllocator)->allocate($original, [0], ['bundle:1' => 30.00]);

        $this->assertSame(0.00, $first->creditFor(0));
        $this->assertSame(20.00, $first->discountFor(0));

        // 10.00 of discount is left for whatever is credited next.
        $second = (new CreditNoteDiscountAllocator)->allocate($original, [1], ['bundle:1' => 10.00]);

        $this->assertSame(90.00, $second->creditFor(1));
        $this->assertSame(90.00, round($first->totalInclVat() + $second->totalInclVat(), 2));
    }

    /** Full credit of an untouched bundle: the same algorithm, closing exactly on 288. */
    public function test_full_credit_of_the_whole_bundle_closes_on_the_invoiced_total(): void
    {
        $original = $this->bundleInvoice([120.00, 200.00], 32.00);

        $allocation = (new CreditNoteDiscountAllocator)->allocate($original, [0, 1], ['bundle:1' => 32.00]);

        $this->assertSame(88.00, $allocation->creditFor(0));
        $this->assertSame(200.00, $allocation->creditFor(1));
        $this->assertSame(288.00, $allocation->totalInclVat());
    }

    /**
     * Selection order must not change the answer: the discount is consumed in invoice
     * line order regardless of the order the indexes arrive in.
     */
    public function test_selection_order_does_not_change_the_allocation(): void
    {
        $original = $this->bundleInvoice([120.00, 200.00], 32.00);

        $ascending = (new CreditNoteDiscountAllocator)->allocate($original, [0, 1], ['bundle:1' => 32.00]);
        $descending = (new CreditNoteDiscountAllocator)->allocate($original, [1, 0], ['bundle:1' => 32.00]);

        $this->assertSame($ascending->creditInclVat, $descending->creditInclVat);
    }

    /** A course outside the bundle is untouched by the bundle's discount. */
    public function test_a_non_bundle_line_is_credited_at_its_full_value(): void
    {
        $original = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:1',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [
                $this->line('Course A', 120.00),
                $this->line('Course B', 200.00),
                $this->line('Course C', 300.00),
            ],
            totalExclVat: 0.0,
            totalVat: 0.0,
            totalInclVat: 588.00,
            allowanceCharges: [$this->allowance(32.00, 'bundle:1', [0, 1])],
        );

        $allocation = (new CreditNoteDiscountAllocator)->allocate($original, [2], ['bundle:1' => 32.00]);

        $this->assertSame(300.00, $allocation->creditFor(2));
        $this->assertSame(0.0, $allocation->discountFor(2));
        $this->assertNull($allocation->poolFor(2));
    }

    /** Two bundles on one invoice never share a balance. */
    public function test_two_bundles_keep_independent_discount_balances(): void
    {
        $original = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:2',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [
                $this->line('A1', 120.00),
                $this->line('A2', 200.00),
                $this->line('B1', 100.00),
                $this->line('B2', 50.00),
            ],
            totalExclVat: 0.0,
            totalVat: 0.0,
            totalInclVat: 418.00,
            allowanceCharges: [
                $this->allowance(32.00, 'bundle:1', [0, 1]),
                $this->allowance(20.00, 'bundle:2', [2, 3]),
            ],
        );

        $allocation = (new CreditNoteDiscountAllocator)->allocate(
            $original,
            [0, 2],
            ['bundle:1' => 32.00, 'bundle:2' => 20.00],
        );

        // Each course draws only on its own bundle: 120-32 and 100-20.
        $this->assertSame(88.00, $allocation->creditFor(0));
        $this->assertSame(80.00, $allocation->creditFor(2));
        $this->assertSame('bundle:1', $allocation->poolFor(0));
        $this->assertSame('bundle:2', $allocation->poolFor(2));
    }

    /** An invoice with no allowance at all reports no pools — the ordinary path. */
    public function test_an_invoice_without_an_allowance_has_no_pools(): void
    {
        $original = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:3',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [$this->line('Course A', 120.00)],
            totalExclVat: 104.35,
            totalVat: 15.65,
            totalInclVat: 120.00,
        );

        $this->assertFalse(CreditNoteDiscountAllocator::hasDiscountPools($original));
        $this->assertSame([], CreditNoteDiscountAllocator::poolByLineIndex($original));
    }

    /**
     * Three courses whose discount does not divide evenly. The point is not any one
     * line's figure but that the three credits sum to exactly what the bundle was
     * invoiced, with no fil left behind.
     */
    public function test_an_awkward_discount_still_closes_exactly(): void
    {
        $original = $this->bundleInvoice([33.33, 33.33, 33.34], 10.01);

        $allocation = (new CreditNoteDiscountAllocator)->allocate($original, [0, 1, 2], ['bundle:1' => 10.01]);

        $this->assertSame(89.99, $allocation->totalInclVat());

        foreach ([0, 1, 2] as $index) {
            $this->assertGreaterThanOrEqual(0.0, $allocation->creditFor($index));
        }
    }

    /**
     * @param  float[]  $grossLineValues
     */
    private function bundleInvoice(array $grossLineValues, float $discountGross): ZatcaInvoiceData
    {
        $lines = [];

        foreach ($grossLineValues as $position => $gross) {
            $lines[] = $this->line('Course '.($position + 1), $gross);
        }

        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:100',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: $lines,
            totalExclVat: 0.0,
            totalVat: 0.0,
            totalInclVat: round(array_sum($grossLineValues) - $discountGross, 2),
            allowanceCharges: [
                $this->allowance($discountGross, 'bundle:1', array_keys($grossLineValues)),
            ],
        );
    }

    /** A VAT-inclusive line, the shape PaymentInvoiceSource signs for a bundle course. */
    private function line(string $description, float $gross): ZatcaInvoiceLine
    {
        $net = round($gross / 1.15, 2);

        return new ZatcaInvoiceLine($description, 1, $net, 15.0, $net, round($gross - $net, 2));
    }

    /** @param  int[]  $lineIndexes */
    private function allowance(float $gross, string $poolKey, array $lineIndexes): ZatcaAllowanceCharge
    {
        $net = round($gross / 1.15, 2);

        return new ZatcaAllowanceCharge(
            reason: 'Bundle Discount',
            amountExclVat: $net,
            vatRate: 15.0,
            vatAmount: round($gross - $net, 2),
            poolKey: $poolKey,
            lineIndexes: $lineIndexes,
        );
    }
}
