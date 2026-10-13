<?php

namespace Zatca\Services\CreditNote;

use Zatca\DTOs\ZatcaInvoiceData;

/**
 * Decides what a Credit Note actually credits when the original invoice carried a
 * document-level allowance (today: a bundle discount).
 *
 * The problem it exists to solve: since bundle invoices moved the discount out of
 * the line values and into one `cac:AllowanceCharge`, each line now states the
 * item's ORIGINAL price. Copying those lines onto a Credit Note verbatim — which
 * is exactly what CreditNoteDataFactory does, correctly, for every other invoice —
 * would credit 320.00 against an invoice that only ever charged 288.00.
 *
 * The discount belongs to the bundle as a whole, not to any item in it, so it is
 * NOT split across the lines. It is a balance that the credited lines draw down, in
 * invoice line order:
 *
 *     applied = min(remaining discount, line's original value)
 *     credit  = line's original value - applied
 *
 * which makes the individual amounts depend on the order items are credited in,
 * while the cumulative total always closes on exactly what was invoiced. Crediting
 * A(120) then B(200) out of a 32.00 discount gives 88 then 200; the other order
 * gives 168 then 120; both total 288.
 *
 * Pure: no database, no clock, no host lookup. Everything it needs is the frozen
 * original snapshot plus what earlier notes already consumed.
 */
class CreditNoteDiscountAllocator
{
    /**
     * @param  ZatcaInvoiceData  $original  the frozen original snapshot — its lines are the
     *   original item values and its allowances are the discounts drawn against them.
     * @param  int[]  $selectedIndexes  lines this note credits.
     * @param  array<string, float>  $availableByPool  pool key => discount still unspent, as
     *   computed by CreditNoteLedger from lines that are currently reserved or credited.
     * @param  array<int, float>  $effectiveValues  line index => what the item is worth
     *   right now (see effectiveValues()). The discount a line may consume is capped by
     *   this rather than its invoiced value; a line missing here is capped by its line.
     */
    public function allocate(
        ZatcaInvoiceData $original,
        array $selectedIndexes,
        array $availableByPool,
        array $effectiveValues = [],
    ): CreditNoteDiscountAllocation {
        $poolOfLine = self::poolByLineIndex($original);

        // Ascending invoice line order, so the sequence a note consumes its discount in
        // is the document's own order rather than whatever order the admin happened to
        // tick the boxes in — two admins selecting the same items get the same amounts.
        $selectedIndexes = array_values(array_unique($selectedIndexes));
        sort($selectedIndexes);

        $credits = [];
        $applied = [];
        $poolKeys = [];
        $remaining = $availableByPool;

        foreach ($selectedIndexes as $index) {
            $line = $original->lines[$index] ?? null;

            if ($line === null) {
                continue;
            }

            $gross = $line->lineTotalInclVat();
            $pool = $poolOfLine[$index] ?? null;

            if ($pool === null) {
                // Not part of any allowance — a non-bundle item sharing the invoice.
                // Credited at its line value, exactly as it always was.
                $credits[$index] = $gross;
                $applied[$index] = 0.0;

                continue;
            }

            // An item a debit note priced up is worth more than its line, and the
            // discount is drawn against all of it; what exceeds the line itself comes
            // off the price increases credited beside it (see overflowOntoDebitLines()).
            $consume = min(max($remaining[$pool] ?? 0.0, 0.0), $effectiveValues[$index] ?? $gross);

            $credits[$index] = round(max(0.0, $gross - $consume), 2);
            $applied[$index] = round($consume, 2);
            $poolKeys[$index] = $pool;
            $remaining[$pool] = round(($remaining[$pool] ?? 0.0) - $consume, 2);
        }

        return $this->reconcileClosingPools($original, $credits, $applied, $poolKeys, $remaining, $availableByPool);
    }

    /**
     * Whether this original carries any allowance at all. False for every invoice
     * that is not a bundle purchase, which is the signal CreditNoteIssuer uses to
     * stay on its original verbatim-copy path.
     */
    public static function hasDiscountPools(ZatcaInvoiceData $original): bool
    {
        return $original->allowanceCharges !== [];
    }

    /**
     * What each selected item is worth right now, for drawing its bundle discount:
     * its line, minus the price adjustments already credited against it, plus the
     * debit note price increases on it that this same note credits.
     *
     * Only increases credited in this note count. One credited earlier was given back
     * already, and one left out is not this note's to discount.
     *
     * @param  int[]  $selected
     * @param  int[]  $selectedDebit  debit note line ids this note credits
     * @return array<int, float>
     */
    public static function effectiveValues(
        ZatcaInvoiceData $original,
        CreditNoteLedgerSnapshot $ledger,
        array $selected,
        array $selectedDebit,
    ): array {
        $values = [];

        foreach ($selected as $index) {
            $line = $original->lines[$index] ?? null;

            if ($line === null) {
                continue;
            }

            $key = CreditSource::invoiceLineKey($index);
            $value = $line->lineTotalInclVat() - $ledger->adjustmentFor($key);

            foreach (self::creditedIncreases($ledger, $key, $selectedDebit) as $id) {
                $value += self::increaseValue($ledger, $id);
            }

            $values[$index] = round(max(0.0, $value), 2);
        }

        return $values;
    }

    /**
     * The discount each item drew beyond what is left of its own line, taken off the
     * price increases credited beside it, in debit note line order.
     *
     * @param  int[]  $selected
     * @param  int[]  $selectedDebit
     * @return array<int, array{amount: float, pool: string}>  debit note line id => discount taken off it
     */
    public static function overflowOntoDebitLines(
        ZatcaInvoiceData $original,
        CreditNoteLedgerSnapshot $ledger,
        CreditNoteDiscountAllocation $allocation,
        array $selected,
        array $selectedDebit,
    ): array {
        $taken = [];

        foreach ($selected as $index) {
            $pool = $allocation->poolFor($index);
            $line = $original->lines[$index] ?? null;

            if ($pool === null || $line === null) {
                continue;
            }

            $key = CreditSource::invoiceLineKey($index);
            $own = max(0.0, $line->lineTotalInclVat() - $ledger->adjustmentFor($key));
            $overflow = round($allocation->discountFor($index) - $own, 2);

            foreach (self::creditedIncreases($ledger, $key, $selectedDebit) as $id) {
                if ($overflow <= 0.0) {
                    break;
                }

                $take = round(min($overflow, self::increaseValue($ledger, $id)), 2);

                if ($take <= 0.0) {
                    continue;
                }

                $taken[$id] = ['amount' => $take, 'pool' => $pool];
                $overflow = round($overflow - $take, 2);
            }
        }

        return $taken;
    }

    /** @return int[] the price increases on $sourceKey that this note credits */
    private static function creditedIncreases(CreditNoteLedgerSnapshot $ledger, string $sourceKey, array $selectedDebit): array
    {
        return array_values(array_intersect($ledger->attachedDebitLineIds[$sourceKey] ?? [], $selectedDebit));
    }

    /** A price increase as this note would credit it before any discount: its value less its own adjustments. */
    private static function increaseValue(CreditNoteLedgerSnapshot $ledger, int $debitNoteLineId): float
    {
        $source = $ledger->debitSources[$debitNoteLineId] ?? null;

        if ($source === null) {
            return 0.0;
        }

        return round(max(0.0, $source->grossInclVat() - $ledger->adjustmentFor($source->key())), 2);
    }

    /**
     * Pools whose discount covered every item in them: the bundle was sold for
     * nothing, so each of its items was free and none can be over- or under-credited
     * however the discount is spread. Their items are cancelled at zero, alone,
     * together or beside any other item, exactly like an item priced 0.
     *
     * @return string[]
     */
    public static function freePools(ZatcaInvoiceData $original): array
    {
        $lineTotals = self::poolLineTotals($original);
        $free = [];

        foreach (self::poolTotals($original) as $pool => $discount) {
            if (round(($lineTotals[$pool] ?? 0.0) - $discount, 2) <= 0.0) {
                $free[] = $pool;
            }
        }

        return $free;
    }

    /**
     * Total discount per pool, gross, as frozen on the original.
     *
     * @return array<string, float>
     */
    public static function poolTotals(ZatcaInvoiceData $original): array
    {
        $totals = [];

        foreach ($original->allowanceCharges as $position => $allowance) {
            $key = $allowance->poolKey ?? 'allowance:'.$position;
            $totals[$key] = round(($totals[$key] ?? 0.0) + $allowance->amountInclVat(), 2);
        }

        return $totals;
    }

    /**
     * Line index => pool key, for lines covered by an allowance.
     *
     * When an allowance records no line indexes — an invoice signed before that was
     * frozen — it cannot say which lines it covers, so no line is mapped to it and
     * the caller is expected to refuse rather than guess. CreditNoteIssuer does
     * exactly that via assertPoolsResolvable().
     *
     * @return array<int, string>
     */
    public static function poolByLineIndex(ZatcaInvoiceData $original): array
    {
        $map = [];

        foreach ($original->allowanceCharges as $position => $allowance) {
            $key = $allowance->poolKey ?? 'allowance:'.$position;

            foreach ($allowance->lineIndexes as $index) {
                $map[$index] = $key;
            }
        }

        return $map;
    }

    /**
     * Gross value of every line belonging to a pool, as invoiced.
     *
     * @return array<string, float>
     */
    public static function poolLineTotals(ZatcaInvoiceData $original): array
    {
        $totals = [];

        foreach (self::poolByLineIndex($original) as $index => $key) {
            $line = $original->lines[$index] ?? null;

            if ($line !== null) {
                $totals[$key] = round(($totals[$key] ?? 0.0) + $line->lineTotalInclVat(), 2);
            }
        }

        return $totals;
    }

    /**
     * When this note claims the last unclaimed lines of a pool, the pool's credits
     * must close on exactly what the pool was invoiced (its line total minus its
     * whole discount). Per-line rounding of the VAT split can otherwise leave the
     * final figure a fil out, which would mean the cumulative credits never quite
     * equal the sale — the one thing this whole mechanism exists to guarantee.
     *
     * The correction lands on the pool's highest selected line and is only ever a
     * rounding-sized nudge; it is abandoned rather than allowed to make a line
     * negative.
     *
     * @param  array<int, float>  $credits
     * @param  array<int, float>  $applied
     * @param  array<int, string>  $poolKeys
     * @param  array<string, float>  $remaining
     * @param  array<string, float>  $availableByPool
     */
    private function reconcileClosingPools(
        ZatcaInvoiceData $original,
        array $credits,
        array $applied,
        array $poolKeys,
        array $remaining,
        array $availableByPool,
    ): CreditNoteDiscountAllocation {
        foreach (array_keys(self::poolTotals($original)) as $pool) {
            $poolLineIndexes = array_keys($poolKeys, $pool, true);

            if ($poolLineIndexes === []) {
                continue;
            }

            // The pool closes here only if this note leaves no discount unspent AND
            // spends every last unit of what was still available to it. Anything else
            // means further lines of this pool are still creditable later, and their
            // own rounding is not this note's to absorb.
            $spent = round(array_sum(array_intersect_key($applied, array_flip($poolLineIndexes))), 2);
            $wasAvailable = round($availableByPool[$pool] ?? 0.0, 2);

            if (round($remaining[$pool] ?? 0.0, 2) !== 0.0 || $spent !== $wasAvailable) {
                continue;
            }

            // Discount drawn beyond a line's own value was taken off its price increase,
            // not off the line, so it counts as credited here all the same.
            $creditedHere = round(array_sum(array_intersect_key($credits, array_flip($poolLineIndexes))), 2);

            foreach ($poolLineIndexes as $index) {
                $creditedHere = round($creditedHere + max(0.0, $applied[$index] - $original->lines[$index]->lineTotalInclVat()), 2);
            }

            $lineTotalHere = round(array_sum(array_map(
                fn (int $index) => $original->lines[$index]->lineTotalInclVat(),
                $poolLineIndexes
            )), 2);

            $expected = round($lineTotalHere - $wasAvailable, 2);
            $drift = round($expected - $creditedHere, 2);

            if ($drift === 0.0) {
                continue;
            }

            $lastIndex = max($poolLineIndexes);
            $patched = round($credits[$lastIndex] + $drift, 2);

            if ($patched < 0) {
                continue;
            }

            $credits[$lastIndex] = $patched;
        }

        return new CreditNoteDiscountAllocation($credits, $applied, $poolKeys);
    }
}
