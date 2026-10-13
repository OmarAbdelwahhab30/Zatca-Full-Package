<?php

namespace Zatca\Services\CreditNote;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use DateTimeImmutable;

/**
 * Builds the ZatcaInvoiceData for a Credit Note from the ORIGINAL invoice's frozen
 * snapshot — never from the host's current product/order prices. That is what
 * makes scenario 9 (a discounted invoice) work by construction: the copied line
 * already carries its post-discount net/VAT, and this class has no path back to the
 * host record that could recompute it (BR-07).
 *
 * Host-agnostic and pure, like ZatcaInvoiceData itself: given the same inputs it
 * always produces the same output, no clock or host lookup involved beyond the
 * issueDateTime the caller passes in.
 *
 * Standard/Simplified subtype, the structured BuyerParty and the supply date are all
 * INHERITED from the original's own frozen snapshot (never re-resolved from the
 * host's current company/customer state, and never decided by this class): a Credit
 * Note against a Standard (B2B) invoice is itself a Standard Credit Note, and one
 * against a Simplified invoice stays Simplified. See note().
 */
class CreditNoteDataFactory
{
    /**
     * @param  ZatcaInvoiceData  $original  the authoritative original's snapshot, restored via
     *   InvoiceDataSnapshot::fromArray() — never re-read from the host.
     * @param  int[]  $lineIndexes  zero-based indexes into $original->lines to credit. Whole
     *   lines only — every line is credited whole (quantity included), so there is no partial
     *   quantity to represent.
     * @param  string  $creditNoteNumber  becomes both the row's source_reference and the XML cbc:ID.
     * @param  string  $billingReferenceId  the original document's OWN cbc:ID (its source_reference
     *   at signing time) — stable across regeneration of the original.
     * @param  string  $reason  KSA-10 / BR-KSA-17 reason. Length is enforced by InvoiceDataValidator,
     *   not here — this class only shapes data, it does not decide what is acceptable.
     * @param  array<int, float>  $creditInclVatOverrides  line index => gross amount to credit, for
     *   an original whose lines state a pre-discount value and carry the discount separately as a
     *   document-level allowance, or a line whose price an earlier note already adjusted. Absent
     *   index means credit the line as invoiced, which is what every other invoice does and what
     *   this class did before overrides existed.
     *
     * @param  ZatcaInvoiceLine[]  $debitNoteLines  lines of ACCEPTED debit notes against this original,
     *   credited after the original's own lines exactly as passed. They are never part of a discount
     *   pool, so nothing here re-prices them; a caller crediting less than the signed line (its
     *   price was adjusted) passes the line through reprice() first.
     *
     * @throws ZatcaException when a requested index does not exist on the original, or nothing is selected.
     */
    public function make(
        ZatcaInvoiceData $original,
        array $lineIndexes,
        string $creditNoteNumber,
        string $billingReferenceId,
        string $reason,
        DateTimeImmutable $issueDateTime,
        array $creditInclVatOverrides = [],
        array $debitNoteLines = [],
    ): ZatcaInvoiceData {
        if ($lineIndexes === [] && $debitNoteLines === []) {
            throw new ZatcaException("[ZATCA] Credit Note [{$creditNoteNumber}] has no lines selected to credit.");
        }

        $lines = [];

        foreach ($lineIndexes as $index) {
            if (! array_key_exists($index, $original->lines)) {
                throw new ZatcaException(
                    "[ZATCA] Credit Note [{$creditNoteNumber}] references line index [{$index}], which does not "
                    ."exist on original document [{$original->sourceReference}]."
                );
            }

            // ZatcaInvoiceLine is immutable, so this is a true copy of the frozen values —
            // not a reference the caller could later mutate underneath this note.
            $lines[] = array_key_exists($index, $creditInclVatOverrides)
                ? $this->reprice($original->lines[$index], (float) $creditInclVatOverrides[$index], $creditNoteNumber)
                : $original->lines[$index];
        }

        foreach ($debitNoteLines as $debitNoteLine) {
            $lines[] = $debitNoteLine;
        }

        return $this->note($original, $lines, $creditNoteNumber, $billingReferenceId, $reason, $issueDateTime);
    }

    /**
     * Builds a price adjustment note: one line, carrying the difference being credited,
     * against the same original. Its buyer, currency and billing reference come from the
     * original exactly as make()'s do — only the line is new, because a price adjustment
     * credits an amount that is not any line of the original.
     *
     * @throws ZatcaException when the line is not a positive, non-negative amount.
     */
    /**
     * @param  ZatcaInvoiceLine[]  $reversedLines  Debit Note increases this adjustment had to
     *   reverse to reach its amount, credited in full and stated after the adjustment's own
     *   line. Empty for every adjustment that fits inside the item's own value, which is
     *   the ordinary one.
     */
    public function makeAdjustment(
        ZatcaInvoiceData $original,
        ZatcaInvoiceLine $line,
        string $creditNoteNumber,
        string $billingReferenceId,
        string $reason,
        DateTimeImmutable $issueDateTime,
        array $reversedLines = [],
    ): ZatcaInvoiceData {
        return $this->makeAdjustments(
            $original,
            [$line],
            $reversedLines,
            $creditNoteNumber,
            $billingReferenceId,
            $reason,
            $issueDateTime,
        );
    }

    /**
     * The same note for several items at once: one adjustment line per item, then
     * every Debit Note increase those adjustments had to reverse.
     *
     * The two groups are kept apart in the signature because only the first is checked
     * for being a positive correction — a reversed increase is credited at its own signed
     * value, whatever that is.
     *
     * @param  ZatcaInvoiceLine[]  $adjustmentLines  one per item, in the order submitted
     * @param  ZatcaInvoiceLine[]  $reversedLines  the increases given back, stated after them
     */
    public function makeAdjustments(
        ZatcaInvoiceData $original,
        array $adjustmentLines,
        array $reversedLines,
        string $creditNoteNumber,
        string $billingReferenceId,
        string $reason,
        DateTimeImmutable $issueDateTime,
    ): ZatcaInvoiceData {
        if ($adjustmentLines === []) {
            throw new ZatcaException(
                "[ZATCA] Credit Note [{$creditNoteNumber}] price adjustment must correct at least one item."
            );
        }

        foreach ($adjustmentLines as $line) {
            if ($line->lineTotalExclVat < 0 || $line->vatAmount < 0 || $line->lineTotalInclVat() <= 0) {
                throw new ZatcaException(
                    "[ZATCA] Credit Note [{$creditNoteNumber}] price adjustment must credit a positive amount."
                );
            }
        }

        return $this->note(
            $original,
            [...$adjustmentLines, ...$reversedLines],
            $creditNoteNumber,
            $billingReferenceId,
            $reason,
            $issueDateTime,
        );
    }

    /** @param  ZatcaInvoiceLine[]  $lines */
    private function note(
        ZatcaInvoiceData $original,
        array $lines,
        string $creditNoteNumber,
        string $billingReferenceId,
        string $reason,
        DateTimeImmutable $issueDateTime,
    ): ZatcaInvoiceData {
        $totalExclVat = round(array_sum(array_map(static fn (ZatcaInvoiceLine $l) => $l->lineTotalExclVat, $lines)), 2);
        $totalVat = round(array_sum(array_map(static fn (ZatcaInvoiceLine $l) => $l->vatAmount, $lines)), 2);
        $totalInclVat = round($totalExclVat + $totalVat, 2);

        return new ZatcaInvoiceData(
            egsIdentifier: $original->egsIdentifier,
            sourceReference: $creditNoteNumber,
            buyerName: $original->buyerName,
            buyerVatNumber: $original->buyerVatNumber,
            issueDateTime: $issueDateTime,
            currency: $original->currency,
            lines: $lines,
            totalExclVat: $totalExclVat,
            totalVat: $totalVat,
            totalInclVat: $totalInclVat,
            // Standard/Simplified is INHERITED from the original, never decided here — a
            // Credit Note against a Standard (B2B) invoice is itself a Standard Credit
            // Note, cleared like the invoice it adjusts; one against a Simplified invoice
            // stays Simplified/Reported exactly as before this field existed.
            documentType: $original->documentType->isStandard()
                ? ZatcaComplianceDocumentType::StandardCreditNote
                : ZatcaComplianceDocumentType::CreditNote,
            billingReferenceId: $billingReferenceId,
            adjustmentReason: $reason,
            // Standard only: the ORIGINAL invoice's own frozen buyer/supply date — never
            // re-resolved from the host's current company/customer state. For a Simplified
            // original both are already null, so this is a no-op for B2C.
            buyer: $original->buyer,
            supplyDate: $original->supplyDate,
        );
    }

    /**
     * Restates one line at a different gross amount, keeping the original's own tax
     * treatment.
     *
     * The rate is the line's FROZEN vatRate, never the tenant's current setting, and
     * VAT is derived by subtraction rather than rounded independently — so net + VAT
     * is exactly the credited amount and the note cannot drift a fil away from the
     * figure the allocator guaranteed.
     *
     * The line's split is reproduced rather than assumed: a line invoiced with VAT
     * carved out of its price is credited the same way, and one invoiced with VAT
     * added on top keeps that shape too.
     *
     * @throws ZatcaException when asked to credit a negative amount — a Credit Note
     *   line that owes the customer nothing and the seller something is not a rounding
     *   problem, it is a fault upstream, and it stops here rather than being signed.
     */
    public function reprice(ZatcaInvoiceLine $line, float $creditInclVat, string $creditNoteNumber): ZatcaInvoiceLine
    {
        $creditInclVat = round($creditInclVat, 2);

        if ($creditInclVat < 0) {
            throw new ZatcaException(
                "[ZATCA] Credit Note [{$creditNoteNumber}] would credit a negative amount ("
                .number_format($creditInclVat, 2, '.', '').") for line [{$line->description}]."
            );
        }

        $invoicedGross = $line->lineTotalInclVat();

        if ($invoicedGross === $creditInclVat) {
            return $line;
        }

        // Same proportion of net to VAT the original line was signed with. Deriving it
        // from the line itself rather than from the rate covers the exclusive policy
        // (where gross is net plus VAT) and the inclusive one (where it is not) with
        // one expression, and degrades to the plain rate formula on a zero-value line.
        $net = $invoicedGross > 0
            ? round($creditInclVat * ($line->lineTotalExclVat / $invoicedGross), 2)
            : round($creditInclVat / (1 + ($line->vatRate / 100)), 2);

        // Unit price restated per unit, so quantity × unit price still equals the line net's
        // (BR-KSA-EN16931-11) when the credited line carries more than one unit.
        return $line->withAmounts(
            quantity: $line->quantity,
            unitPrice: round($net / max(1, $line->quantity), 2),
            lineTotalExclVat: $net,
            vatAmount: round($creditInclVat - $net, 2),
        );
    }
}
