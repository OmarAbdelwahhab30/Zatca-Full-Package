<?php

namespace Zatca\Services\DebitNote;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\Support\Money;
use Zatca\Support\VatSettings;
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
 * Line prices are calculated by InvoiceCalculator, exactly like invoice items, in the
 * configured price mode. A line that adjusts an item keeps that item's frozen VAT
 * category and rate unless it states its own. InvoiceDataValidator then checks the
 * result before an ICV is spent.
 *
 * Host-agnostic and pure: given the same inputs it always produces the same
 * output, no clock or host lookup beyond the issueDateTime the caller passes in.
 */
class DebitNoteDataFactory
{
    public function __construct(
        private readonly InvoiceCalculator $calculator = new InvoiceCalculator,
    ) {}

    /**
     * @param  ZatcaInvoiceData  $original  the authoritative original's snapshot, restored via
     *   InvoiceDataSnapshot::fromArray() — used for buyer/currency/EGS, and for the frozen tax
     *   treatment of a line that adjusts one of its items. Never re-priced.
     * @param  DebitNoteLineInput[]  $lines  the NEW charges this note adds.
     * @param  string  $debitNoteNumber  becomes both the row's source_reference and the XML cbc:ID.
     * @param  string  $billingReferenceId  the original document's OWN cbc:ID (its source_reference
     *   at signing time) — stable across regeneration of the original.
     * @param  string  $reason  KSA-10 / BR-KSA-17 reason text. Length is enforced by
     *   InvoiceDataValidator, not here — this class only shapes data.
     * @param  array<int, ZatcaInvoiceLine>  $referencedDebitLines  debit note line id => its signed
     *   line, for lines that adjust an item an earlier Debit Note added, so they keep its tax treatment.
     * @param  VatPriceMode|null  $priceMode  what the line prices mean; null = ZATCA_VAT_MODE.
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
        array $referencedDebitLines = [],
        ?VatPriceMode $priceMode = null,
    ): ZatcaInvoiceData {
        if ($lines === []) {
            throw DebitNoteException::noLines();
        }

        $settings = $this->calculator->settings();
        $mode = $priceMode ?? $settings->mode;
        $invoiceLines = [];

        foreach (array_values($lines) as $position => $line) {
            // A back-reference to a line that does not exist would put a meaningless
            // original_line_index into the audit trail, so it is refused here rather
            // than stored. The original line is only ever READ (for its tax treatment);
            // it is never copied into this note or modified.
            if ($line->originalLineIndex !== null && ! array_key_exists($line->originalLineIndex, $original->lines)) {
                throw DebitNoteException::invalidLineReference($line->originalLineIndex);
            }

            $adjusted = $line->originalLineIndex !== null
                ? $original->lines[$line->originalLineIndex]
                : ($line->sourceDebitNoteLineId !== null ? ($referencedDebitLines[$line->sourceDebitNoteLineId] ?? null) : null);

            [$category, $rate, $code, $exemptionReason] = match (true) {
                $line->vatCategory !== null => [$line->vatCategory, $line->vatRate, $line->vatExemptionReasonCode, $line->vatExemptionReason],
                $line->vatRate !== null => [ZatcaVatCategory::Standard, $line->vatRate, null, null],
                $adjusted !== null => [$adjusted->vatCategory, $adjusted->vatRate, $adjusted->vatExemptionReasonCode, $adjusted->vatExemptionReason],
                default => [ZatcaVatCategory::Standard, null, null, null],
            };

            $where = "Debit Note [{$debitNoteNumber}] line ".($position + 1);

            $invoiceLines[] = $line->vatOnly
                ? $this->vatOnlyLine($line, $category, $rate, $code, $exemptionReason, $settings, $where)
                : $this->calculator->line(
                    new ZatcaInvoiceItem($line->description, $line->quantity, $line->price, $rate, $category, $code, $exemptionReason),
                    $mode,
                    $settings,
                    $where,
                );
        }

        [$net, $vat] = InvoiceCalculator::totals($invoiceLines);
        $totalExclVat = Money::amount($net);
        $totalVat = Money::amount($vat);
        $totalInclVat = Money::amount($net + $vat);

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

    /**
     * A VAT-only correction: no net amount, the supplied price IS the VAT charged. Its rate
     * is stated (the adjusted item's, by default) but not applied — InvoiceDataValidator
     * recognises a zero-net, positive-VAT line as a VAT Adjustment.
     */
    private function vatOnlyLine(
        DebitNoteLineInput $line,
        ZatcaVatCategory $category,
        int|float|string|null $rate,
        ?string $code,
        ?string $exemptionReason,
        VatSettings $settings,
        string $where,
    ): ZatcaInvoiceLine {
        $vat = Money::halalas($line->price, "{$where} VAT amount");

        return new ZatcaInvoiceLine(
            description: $line->description,
            quantity: 1,
            netUnitPrice: 0.0,
            vatRate: Money::toFloat($this->calculator->rateFor($category, $rate, $settings, $where), 2),
            lineTotalExclVat: 0.0,
            vatAmount: Money::amount($vat),
            vatCategory: $category,
            vatExemptionReasonCode: $code,
            vatExemptionReason: $exemptionReason,
        );
    }
}
