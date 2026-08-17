<?php

namespace Zatca\Services\Generation;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;

/**
 * Decides whether an unsigned Draft may have its signing completed.
 *
 * A Draft is a document whose scarce, irreversible parts already exist — its ICV is
 * reserved, its UUID is assigned, its unsigned XML is built and its business snapshot is
 * frozen. What is missing is only what the SDK produces: invoice_hash,
 * signed_xml_base64 and qr_code.
 *
 * Normally GenerateZatcaInvoiceJob finishes this by itself: it retries five times with a
 * backoff reaching ten minutes, and each attempt re-enters generate(), which finds the
 * existing Draft and signs it. Nothing else in the system does. Once that budget is spent
 * the Draft is stranded — `zatca:report-pending` only looks at pending_reporting and
 * retrying, `zatca:requeue` excludes draft, retry refuses it for having nothing to send,
 * and regeneration refuses it because nothing was ever submitted, so nothing was
 * rejected. Recovery meant `queue:retry` on the console. This action is the same recovery,
 * available to an administrator.
 *
 * This is the third action on this screen and the distinctions between them are the whole
 * point:
 *
 *  - Retry resends a document that exists. The fix when the submission failed in transit.
 *  - Regenerate builds a NEW document. The fix when ZATCA read one and refused it.
 *  - Complete finishes a document that was never finished. It creates nothing, replaces
 *    nothing, and consumes nothing new — the ICV it uses was reserved when the Draft was
 *    written.
 *
 * That last property is why this is the safest of the three, and why its eligibility rules
 * are about whether signing is *possible*, not about whether ZATCA said anything.
 */
class DraftCompletionPolicy
{
    /**
     * Checks are ordered so the reason reported is the most actionable one.
     */
    public function verdict(?ZatcaInvoice $invoice): DraftCompletionVerdict
    {
        if (! $invoice || ! $invoice->status) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::NO_ZATCA_DOCUMENT);
        }

        // Hard guard, first and independent of everything else. A document that has been
        // signed is fiscally final: its hash is committed to the EGS chain and every
        // document after it links to that hash. Signing it again would produce a second
        // hash for one ICV and fork the chain — the failure mode this module's ordering
        // fix exists to prevent. isUnsignedDraft() requires both the status and the
        // absence of the signed document, so neither column alone can authorize a re-sign.
        if (! $invoice->isUnsignedDraft()) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::ALREADY_SIGNED);
        }

        // A replacement Draft whose XML the current generator no longer reproduces. Its
        // ICV stays consumed, but signing it would emit exactly the rejected content the
        // regeneration that abandoned it was meant to replace.
        if ($invoice->isAbandoned()) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::ABANDONED);
        }

        if ($invoice->isGenerationInFlight() || $invoice->isRegenerationInFlight()) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::IN_FLIGHT);
        }

        // Nothing to hand the SDK. A Draft is always written with its XML, so this means
        // the row was created by something outside the normal pipeline.
        if (blank($invoice->unsigned_xml)) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::NOTHING_TO_SIGN);
        }

        $certificate = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->first(['certificate_state']);

        // Signing needs the production certificate and its private key. Without the
        // credential row, production onboarding has not completed for this EGS.
        if (! $certificate) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::CREDENTIALS_MISSING);
        }

        // An expired certificate would sign a document the reporting pipeline then refuses
        // to send, and the signature would move the hash chain on the way. Renewal first.
        if ($this->isExpired($certificate->certificate_state)) {
            return DraftCompletionVerdict::blocked(DraftCompletionVerdict::CERTIFICATE_EXPIRED);
        }

        return DraftCompletionVerdict::eligible();
    }

    /** Convenience for callers that only need the verdict. */
    public function allows(?ZatcaInvoice $invoice): bool
    {
        return $this->verdict($invoice)->eligible;
    }

    private function isExpired(mixed $state): bool
    {
        $value = $state instanceof ZatcaCertificateState ? $state->value : $state;

        return $value === ZatcaCertificateState::Expired->value;
    }
}
