<?php

namespace Zatca\Services\Generation;

/**
 * Whether one unsigned Draft may have its signing completed, and if not, precisely why.
 *
 * Same contract as Zatca\Services\Regeneration\RegenerationVerdict, and deliberately a
 * separate type rather than a shared one with extra codes. The two actions answer
 * different questions — "can this rejected document be replaced?" versus "can this
 * unfinished document be finished?" — and their reason sets barely overlap. One enum
 * carrying both would let a caller hand a regeneration reason to a completion message
 * and get something that reads as sensible while being wrong.
 *
 * Reason codes are stable identifiers, not messages. Wording lives in the consuming
 * application.
 */
class DraftCompletionVerdict
{
    /** Nothing links this invoice to a ZATCA document. */
    public const NO_ZATCA_DOCUMENT = 'no_zatca_document';

    /**
     * Signing already completed. Covers every status past Draft, including the terminal
     * successes — there is nothing to finish, and re-signing is never correct.
     */
    public const ALREADY_SIGNED = 'already_signed';

    /** A completion is running for this Draft right now. */
    public const IN_FLIGHT = 'in_flight';

    /**
     * A replacement Draft the current generator no longer reproduces. Its ICV stays
     * consumed and it must never be signed.
     */
    public const ABANDONED = 'abandoned';

    /** No unsigned XML stored, so there is no document to sign. */
    public const NOTHING_TO_SIGN = 'nothing_to_sign';

    /** Production onboarding has not completed for this EGS, so signing is impossible. */
    public const CREDENTIALS_MISSING = 'credentials_missing';

    /** The EGS production certificate has expired. Renew it first. */
    public const CERTIFICATE_EXPIRED = 'certificate_expired';

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
