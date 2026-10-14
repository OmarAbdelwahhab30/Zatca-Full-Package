<?php

namespace Zatca\Exceptions;

/**
 * Raised by DebitNoteIssuer/DebitNoteCanceller for a Debit Note request that
 * cannot proceed. `reason` is a stable code (mirrors DebitNoteEligibilityVerdict's
 * codes plus the request-shape ones below) — the host layer maps it to whatever
 * message and HTTP status its API contract wants; this class carries no wording.
 * Exact counterpart of CreditNoteException.
 */
class DebitNoteException extends ZatcaException
{
    /** No lines were supplied, or every supplied line was empty/zero. */
    public const NO_LINES = 'no_lines';

    /** A line is malformed: negative amount, zero quantity, or arithmetic that does not reconcile. */
    public const INVALID_LINE = 'invalid_line';

    /** A line references an original_line_index that does not exist on the original document. */
    public const INVALID_LINE_REFERENCE = 'invalid_line_reference';

    /**
     * A line references a debit note line that is not an Additional Item line of an
     * accepted debit note against the same original, or references both that and an
     * original line.
     */
    public const INVALID_DEBIT_NOTE_LINE_REFERENCE = 'invalid_debit_note_line_reference';

    /**
     * A line adjusts an item (an original line, or an item a debit note added) that is no
     * longer open to adjustment: credited back by an accepted Credit Note, or held by one
     * still in flight.
     */
    public const LINE_NOT_ADJUSTABLE = 'line_not_adjustable';

    /** Cancel was requested for a note that is not in a cancellable state. */
    public const NOT_CANCELLABLE = 'not_cancellable';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEligible(string $reason): self
    {
        return new self("[ZATCA] Debit Note request refused: original document is not eligible ({$reason}).", $reason);
    }

    public static function noLines(): self
    {
        return new self('[ZATCA] A Debit Note must add at least one line.', self::NO_LINES);
    }

    public static function invalidLine(string $detail = ''): self
    {
        return new self(
            trim('[ZATCA] Debit Note line is invalid. '.$detail),
            self::INVALID_LINE
        );
    }

    public static function invalidLineReference(int $index): self
    {
        return new self(
            "[ZATCA] Debit Note references original line index [{$index}], which does not exist on the original document.",
            self::INVALID_LINE_REFERENCE
        );
    }

    public static function invalidDebitNoteLineReference(int $debitNoteLineId): self
    {
        return new self(
            "[ZATCA] Debit Note references debit note line [{$debitNoteLineId}], which is not an item added by an accepted Debit Note on this original.",
            self::INVALID_DEBIT_NOTE_LINE_REFERENCE
        );
    }

    /** @param  string  $sourceKey  CreditSource::invoiceLineKey() / debitNoteLineKey() of the item */
    public static function lineNotAdjustable(string $sourceKey): self
    {
        return new self(
            "[ZATCA] Debit Note adjusts [{$sourceKey}], which has been credited or is held by a Credit Note in flight.",
            self::LINE_NOT_ADJUSTABLE
        );
    }

    public static function notCancellable(): self
    {
        return new self('[ZATCA] This Debit Note cannot be cancelled.', self::NOT_CANCELLABLE);
    }
}
