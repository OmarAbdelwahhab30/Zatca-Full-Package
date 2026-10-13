<?php

namespace Zatca\Services\DebitNote;

/**
 * Whether a Debit Note may be issued against one original document, and if not, why.
 * Same shape and purpose as CreditNoteEligibilityVerdict / RegenerationVerdict.
 * Reason codes are stable identifiers; wording lives in the host.
 *
 * The list is deliberately SHORTER than the Credit Note's by exactly one code:
 * there is no FULLY_CREDITED equivalent. A Credit Note consumes the original's
 * lines and eventually runs out of them; a Debit Note adds a charge and consumes
 * nothing, so an invoice never becomes "fully debited" and any number of debit
 * notes may be issued against it.
 */
class DebitNoteEligibilityVerdict
{
    public const ZATCA_DISABLED = 'zatca_disabled';

    /** Nothing links this record to a ZATCA document at all. */
    public const NO_ZATCA_DOCUMENT = 'no_zatca_document';

    /** The document is itself a credit or debit note — a note may not be issued against a note. */
    public const NOT_AN_INVOICE = 'not_an_invoice';

    /** Not yet accepted by ZATCA (draft, pending, retrying, failed, manual review, abandoned). */
    public const NOT_REPORTED = 'not_reported';

    /** No usable business snapshot — pre-dates snapshot recording, nothing to reference from. */
    public const NO_SNAPSHOT = 'no_snapshot';

    /** The EGS production certificate has expired. */
    public const CERTIFICATE_EXPIRED = 'certificate_expired';

    /** A Credit Note or Debit Note against this invoice is neither accepted nor cancelled yet. */
    public const ADJUSTMENT_IN_PROGRESS = 'adjustment_in_progress';

    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason,
    ) {}

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }
}
