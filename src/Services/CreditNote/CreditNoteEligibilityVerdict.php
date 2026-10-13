<?php

namespace Zatca\Services\CreditNote;

/**
 * Whether a Credit Note may be issued against one original document, and if not, why.
 * Same shape and purpose as Zatca\Services\Regeneration\RegenerationVerdict — see that
 * class's docblock. Reason codes are stable identifiers; wording lives in the host.
 */
class CreditNoteEligibilityVerdict
{
    public const ZATCA_DISABLED = 'zatca_disabled';

    /** Nothing links this record to a ZATCA document at all. */
    public const NO_ZATCA_DOCUMENT = 'no_zatca_document';

    /** The document is itself a Credit Note — a note may not be issued against a note. */
    public const NOT_AN_INVOICE = 'not_an_invoice';

    /** Not yet accepted by ZATCA (draft, pending, retrying, failed, manual review, abandoned). */
    public const NOT_REPORTED = 'not_reported';

    /** No usable business snapshot — pre-dates snapshot recording, nothing to credit from. */
    public const NO_SNAPSHOT = 'no_snapshot';

    /** The EGS production certificate has expired. */
    public const CERTIFICATE_EXPIRED = 'certificate_expired';

    /**
     * Every source — each original line and each accepted debit note line — is already
     * credited or reserved by another in-flight/accepted Credit Note.
     */
    public const FULLY_CREDITED = 'fully_credited';

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
