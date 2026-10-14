<?php

namespace Zatca\Services\CreditNote;

use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaDebitNoteReason;

/**
 * One thing a Credit Note may credit that did NOT come from the original invoice's
 * own lines: a line of an ACCEPTED Debit Note raised against that invoice.
 *
 * Original invoice lines are deliberately not modelled here — they are still
 * addressed by index through CreditNoteLedger's existing lineStates, which the
 * bundle discount allocator depends on. This class only adds the second kind.
 *
 * Every amount is the debit note's own frozen, signed line, credited verbatim: a
 * debit note line never belongs to a bundle discount pool, so nothing re-prices it.
 */
class CreditSource
{
    public const TYPE_INVOICE_LINE = 'invoice_line';

    public const TYPE_DEBIT_NOTE_LINE = 'debit_note_line';

    /**
     * Not a source of its own: a Credit Note line that credits PART of one source's value
     * (a price adjustment). It records which source it adjusts in the same columns the
     * other two kinds use — original_line_index, or source_debit_note_line_id.
     */
    public const TYPE_PRICE_ADJUSTMENT = 'price_adjustment';

    /** The host's own product/service id carried by an Additional Item debit note line. */
    public readonly ?int $itemId;

    /** @deprecated Use $itemId. Always equal to it. */
    public readonly ?int $courseId;

    public function __construct(
        public readonly ZatcaInvoiceLine $line,
        public readonly int $debitNoteId,
        public readonly int $debitNoteLineId,
        public readonly string $debitNoteNumber,
        public readonly ZatcaDebitNoteReason $reasonType,
        public readonly ?int $referencedOriginalLineIndex = null,
        ?int $courseId = null,
        public readonly ?int $referencedDebitNoteLineId = null,
        ?int $itemId = null,
    ) {
        if ($itemId !== null && $courseId !== null && $itemId !== $courseId) {
            throw new \InvalidArgumentException(
                "Give itemId or its deprecated alias courseId, not two different values ({$itemId} vs {$courseId})."
            );
        }

        $this->itemId = $itemId ?? $courseId;
        $this->courseId = $this->itemId;
    }

    public static function invoiceLineKey(int $index): string
    {
        return 'line:'.$index;
    }

    public static function debitNoteLineKey(int $debitNoteLineId): string
    {
        return 'dn_line:'.$debitNoteLineId;
    }

    public function key(): string
    {
        return self::debitNoteLineKey($this->debitNoteLineId);
    }

    public function grossInclVat(): float
    {
        return $this->line->lineTotalInclVat();
    }

    /**
     * A VAT Adjustment line carries VAT and no net. Put in a note beside ordinary
     * lines, the note's VAT breakdown stops matching its taxable amount, so it is
     * only ever credited in a note of its own — and never swept into a Full credit.
     */
    public function mustBeCreditedAlone(): bool
    {
        return $this->reasonType === ZatcaDebitNoteReason::VatAdjustment;
    }

    /**
     * Only an item a debit note added has a price that can be wrong. A price or VAT
     * adjustment line is itself a correction, and `other` is unmodelled — reducing any
     * of those is simply crediting it.
     */
    public function canBePriceAdjusted(): bool
    {
        return $this->reasonType === ZatcaDebitNoteReason::AdditionalItem;
    }

    /**
     * The source this line rides on, or null when it stands on its own.
     *
     * A Debit Note price adjustment charged MORE for an item that is already on this
     * invoice; it is not a thing the customer holds, it is part of what that one item
     * cost. So crediting the item has to hand that money back too, and the key
     * returned here is what ties the two together (see CreditNoteLedger::resolveSources()).
     *
     * Only a price adjustment is attached. An Additional Item is an item of its own,
     * a VAT Adjustment is credited alone, and `other` names nothing to attach to.
     */
    public function attachedToKey(): ?string
    {
        if ($this->reasonType !== ZatcaDebitNoteReason::PriceAdjustment) {
            return null;
        }

        return match (true) {
            $this->referencedDebitNoteLineId !== null => self::debitNoteLineKey($this->referencedDebitNoteLineId),
            $this->referencedOriginalLineIndex !== null => self::invoiceLineKey($this->referencedOriginalLineIndex),
            default => null,
        };
    }

    /**
     * Parses a source key into [kind, id]: ['line', index] or ['dn_line', debit note line id].
     *
     * @return array{0: string, 1: int}|null  null for anything that is not a source key
     */
    public static function parseKey(string $key): ?array
    {
        if (preg_match('/^(line|dn_line):(\d+)$/', $key, $match) !== 1) {
            return null;
        }

        return [$match[1], (int) $match[2]];
    }
}
