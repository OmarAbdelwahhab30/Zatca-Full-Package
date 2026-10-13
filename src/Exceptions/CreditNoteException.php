<?php

namespace Zatca\Exceptions;

/**
 * Raised by CreditNoteIssuer/CreditNoteCanceller for a Credit Note request that
 * cannot proceed. `reason` is a stable code (mirrors CreditNoteEligibilityVerdict's
 * codes plus a few request-shape ones below) — the host layer maps it to whatever
 * message and HTTP status its API contract wants; this class carries no wording.
 */
class CreditNoteException extends ZatcaException
{
    /** The line selection, once resolved against what is actually available, is empty. */
    public const EXCEEDS_REMAINING = 'exceeds_remaining';

    /** A requested line index does not exist on the original, or was already selected twice. */
    public const INVALID_LINE_SELECTION = 'invalid_line_selection';

    /** Cancel was requested for a note that is not in a cancellable state. */
    public const NOT_CANCELLABLE = 'not_cancellable';

    /**
     * The selection credits nothing: a discount (bundle or coupon) covers the selected
     * courses in full, so there is no money to credit back. Not a ZATCA restriction —
     * a business one: we do not file documents a discount made worth nothing. A course
     * that was free, with no discount at all, is not refused for this.
     */
    public const ZERO_VALUE = 'zero_value_credit_note';

    /**
     * The original carries a document-level discount but does not record which lines
     * it covers, so no note can be issued against it without inventing the mapping.
     */
    public const DISCOUNT_POOL_UNRESOLVED = 'discount_pool_unresolved';

    /** The amount the admin confirmed is no longer the amount this request would credit. */
    public const AMOUNT_CHANGED = 'amount_changed';

    /**
     * A bundle discount pool credits nothing for this note even though the note as a
     * whole has positive value — meaning a course worth less than its bundle discount
     * is being drained for free behind a course from outside that bundle.
     */
    public const WORTHLESS_BUNDLE_LINE = 'worthless_bundle_line';

    /** A VAT Adjustment debit note line was selected together with other lines. */
    public const MUST_BE_CREDITED_ALONE = 'source_must_be_credited_alone';

    /**
     * A price adjustment named something that cannot be adjusted right now: not a course
     * of this invoice, already reserved or credited, or a debit note line that is not an
     * added course.
     */
    public const NOT_ADJUSTABLE = 'price_adjustment_not_adjustable';

    /** A price adjustment is not strictly below what is left of the course it adjusts. */
    public const ADJUSTMENT_EXCEEDS_VALUE = 'price_adjustment_exceeds_value';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEligible(string $reason): self
    {
        return new self("[ZATCA] Credit Note request refused: original document is not eligible ({$reason}).", $reason);
    }

    public static function exceedsRemaining(): self
    {
        return new self(
            '[ZATCA] The credit amount cannot exceed the remaining eligible amount.',
            self::EXCEEDS_REMAINING
        );
    }

    public static function invalidLineSelection(): self
    {
        return new self('[ZATCA] Credit Note line selection is invalid.', self::INVALID_LINE_SELECTION);
    }

    public static function notCancellable(): self
    {
        return new self('[ZATCA] This Credit Note cannot be cancelled.', self::NOT_CANCELLABLE);
    }

    public static function zeroValue(): self
    {
        return new self(
            '[ZATCA] The selected courses credit back nothing once the discount is applied, '
            .'so there is no Credit Note to issue.',
            self::ZERO_VALUE
        );
    }

    public static function discountPoolUnresolved(): self
    {
        return new self(
            '[ZATCA] The original invoice carries a discount that does not record which lines it covers, '
            .'so a Credit Note cannot be calculated for it.',
            self::DISCOUNT_POOL_UNRESOLVED
        );
    }

    public static function mustBeCreditedAlone(): self
    {
        return new self(
            '[ZATCA] A VAT adjustment carries VAT with no taxable amount, so it can only be credited '
            .'in a Credit Note of its own.',
            self::MUST_BE_CREDITED_ALONE
        );
    }

    public static function notAdjustable(): self
    {
        return new self('[ZATCA] The selected item cannot be price-adjusted.', self::NOT_ADJUSTABLE);
    }

    public static function adjustmentExceedsValue(): self
    {
        return new self(
            '[ZATCA] A price adjustment must be less than what is left of the course it adjusts. '
            .'Reducing a course to nothing is a course cancellation.',
            self::ADJUSTMENT_EXCEEDS_VALUE
        );
    }

    public static function amountChanged(): self
    {
        return new self(
            '[ZATCA] The credit amount changed since it was confirmed — another Credit Note was issued in '
            .'the meantime. Refresh and confirm the new amount.',
            self::AMOUNT_CHANGED
        );
    }

    public static function worthlessBundleLine(): self
    {
        return new self(
            '[ZATCA] One of the selected courses belongs to a bundle whose discount covers its full value, '
            .'so it would be credited for nothing. Select the rest of that bundle instead of pairing it with '
            .'an unrelated course.',
            self::WORTHLESS_BUNDLE_LINE
        );
    }
}
