<?php

namespace Zatca\Services\DebitNote;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\DebitNoteException;
use DateTimeImmutable;

/**
 * Builds the ZatcaInvoiceData for a Debit Note.
 *
 * The counterpart of CreditNoteDataFactory, and it differs in exactly one
 * structural way, which is worth being explicit about: the Credit Note factory
 * COPIES lines out of the original's frozen snapshot and can therefore never
 * re-price anything. A Debit Note's lines describe a charge that is not on the
 * original at all, so they must come from the caller.
 *
 * What is still inherited from the original, and never re-read from the host:
 * buyer identity (name/VAT, and for a Standard original the full structured
 * BuyerParty + supply date), currency, EGS and Standard/Simplified subtype. Those
 * belong to the sale, and a debit note against a sale is issued to the same buyer,
 * in the same currency, under the same submission model, by definition.
 *
 * Amounts are taken exactly as supplied and never recomputed here — the same rule
 * the plain-invoice path follows. InvoiceDataValidator decides whether they
 * reconcile, before an ICV is spent.
 *
 * Host-agnostic and pure: given the same inputs it always produces the same
 * output, no clock or host lookup beyond the issueDateTime the caller passes in.
 */
class DebitNoteDataFactory
{
    /**
     * @param  ZatcaInvoiceData  $original  the authoritative original's snapshot, restored via
     *   InvoiceDataSnapshot::fromArray() — used for buyer/currency/EGS only, never re-priced.
     * @param  DebitNoteLineInput[]  $lines  the NEW charges this note adds.
     * @param  string  $debitNoteNumber  becomes both the row's source_reference and the XML cbc:ID.
     * @param  string  $billingReferenceId  the original document's OWN cbc:ID (its source_reference
     *   at signing time) — stable across regeneration of the original.
     * @param  string  $reason  KSA-10 / BR-KSA-17 reason text. Length is enforced by
     *   InvoiceDataValidator, not here — this class only shapes data.
     *
     * @throws DebitNoteException when no lines are given, or a line references an original
     *   line index that does not exist.
     */
    public function make(
        ZatcaInvoiceData $original,
        array $lines,
        string $debitNoteNumber,
        string $billingReferenceId,
        string $reason,
        DateTimeImmutable $issueDateTime,
    ): ZatcaInvoiceData {
        if ($lines === []) {
            throw DebitNoteException::noLines();
        }

        $invoiceLines = [];

        foreach ($lines as $line) {
            // A back-reference to a line that does not exist would put a meaningless
            // original_line_index into the audit trail, so it is refused here rather
            // than stored. Note this validates the REFERENCE only: the original line
            // itself is never read, copied or modified.
            if ($line->originalLineIndex !== null && ! array_key_exists($line->originalLineIndex, $original->lines)) {
                throw DebitNoteException::invalidLineReference($line->originalLineIndex);
            }

            $invoiceLines[] = new ZatcaInvoiceLine(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                vatRate: $line->vatRate,
                lineTotalExclVat: $line->lineTotalExclVat,
                vatAmount: $line->vatAmount,
                vatCategory: $line->vatCategory,
                vatExemptionReasonCode: $line->vatExemptionReasonCode,
                vatExemptionReason: $line->vatExemptionReason,
            );
        }

        $totalExclVat = round(array_sum(array_map(static fn (ZatcaInvoiceLine $l) => $l->lineTotalExclVat, $invoiceLines)), 2);
        $totalVat = round(array_sum(array_map(static fn (ZatcaInvoiceLine $l) => $l->vatAmount, $invoiceLines)), 2);
        $totalInclVat = round($totalExclVat + $totalVat, 2);

        return new ZatcaInvoiceData(
            egsIdentifier: $original->egsIdentifier,
            sourceReference: $debitNoteNumber,
            buyerName: $original->buyerName,
            buyerVatNumber: $original->buyerVatNumber,
            issueDateTime: $issueDateTime,
            currency: $original->currency,
            lines: $invoiceLines,
            totalExclVat: $totalExclVat,
            totalVat: $totalVat,
            totalInclVat: $totalInclVat,
            // Standard/Simplified is INHERITED from the original, never decided here — a
            // Debit Note against a Standard (B2B) invoice is itself a Standard Debit Note,
            // cleared like the invoice it adjusts; one against a Simplified invoice stays
            // Simplified/Reported exactly as before this field existed.
            documentType: $original->documentType->isStandard()
                ? ZatcaComplianceDocumentType::StandardDebitNote
                : ZatcaComplianceDocumentType::DebitNote,
            billingReferenceId: $billingReferenceId,
            adjustmentReason: $reason,
            // Standard only: the ORIGINAL invoice's own frozen buyer/supply date — never
            // re-resolved from the host's current company/customer state. For a Simplified
            // original both are already null, so this is a no-op for B2C.
            buyer: $original->buyer,
            supplyDate: $original->supplyDate,
        );
    }
}
