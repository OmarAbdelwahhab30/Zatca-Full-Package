<?php

namespace Zatca\Services\Regeneration;

/**
 * Whether one ZATCA document may be regenerated, and if not, precisely why.
 *
 * The reason matters as much as the verdict. Every blocking condition below calls for a
 * different response — retry the same document, fix credentials, renew the certificate,
 * or do nothing because ZATCA already accepted it — so collapsing them into a single
 * "not eligible" would throw away the only part that says what to do next.
 *
 * Reason codes are stable identifiers, not messages. Wording lives in the consuming
 * application, which knows its own audience and language.
 */
class RegenerationVerdict
{
    /** Nothing links this invoice to a ZATCA document. */
    public const NO_ZATCA_DOCUMENT = 'no_zatca_document';

    /** ZATCA already accepted it. A reported invoice is never regenerated. */
    public const ALREADY_REPORTED = 'already_reported';

    /** A submission or a regeneration is running for it right now. */
    public const IN_FLIGHT = 'in_flight';

    /** A replacement has already taken over from this document. */
    public const SUPERSEDED = 'superseded';

    /** Generated before business snapshots were recorded, so there is nothing to rebuild from. */
    public const NO_SNAPSHOT = 'no_snapshot';

    /** The EGS production certificate has expired. Renew it first. */
    public const CERTIFICATE_EXPIRED = 'certificate_expired';

    /** ZATCA refused the credentials (401/403), not the document. Fix the credentials. */
    public const CREDENTIALS_ERROR = 'credentials_error';

    /** Network error or ZATCA server error. Retry the same signed document instead. */
    public const TRANSIENT_FAILURE_RETRY_INSTEAD = 'transient_failure_retry_instead';

    /** Nothing has been rejected, so there is nothing to fix. */
    public const NO_ATTEMPT_RECORDED = 'no_attempt_recorded';

    /** Regenerated as many times as allowed; each attempt consumes an ICV permanently. */
    public const CHAIN_DEPTH_EXCEEDED = 'chain_depth_exceeded';

    /** Never signed, so there is no rejected document to replace. */
    public const NOT_SUBMITTABLE = 'not_submittable';

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
