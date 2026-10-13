<?php

namespace Zatca\Services\Regeneration;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\InvoiceDataSnapshot;

/**
 * Decides whether a ZATCA document may be regenerated.
 *
 * The single place that answer is computed. Every caller — the list screen, the details
 * screen, the per-document endpoint, the bulk action and the queued job — asks this
 * class. If any of them reimplemented the rule, the button and the endpoint could
 * disagree, and the one that decides is whichever runs last.
 *
 * The distinction the whole feature rests on: retry and regeneration fix different
 * failures and are never interchangeable.
 *
 *  - ZATCA was unreachable, or answered 5xx. The document is fine; the trip failed.
 *    Resending the same signed bytes is the fix. Regenerating would spend an ICV slot
 *    to send an identical document.
 *  - ZATCA read the document and refused it (4xx with validation errors). Those exact
 *    bytes will be refused again, forever. Only a new document can succeed.
 *
 * Both outcomes land in the same `manual_review` status, so status alone cannot separate
 * them — the HTTP status of the last attempt is what does. That is the same signal
 * Zatca\Jobs\Concerns\ReportsZatcaInvoice uses to classify a failure as transient. This
 * class is the inverse of that test, plus the guards that apply however the last attempt
 * went.
 *
 * Two 4xx responses are deliberately excluded: 401 and 403 mean ZATCA rejected the
 * credentials, not the invoice. Regenerating would produce a perfect document and earn
 * exactly the same refusal.
 */
class RegenerationPolicy
{
    /**
     * ZATCA states a document may be regenerated from.
     *
     * `manual_review` is where a validation rejection lands, and is the state this feature
     * exists for. `failed` is included because nothing in this package currently writes it,
     * so its presence means something outside the normal pipeline put it there; treating it
     * as recoverable matches how RequeueInvoices already treats it.
     *
     * `clearance_rejected` and `clearance_manual_review` are their Standard/Clearance
     * counterparts — without them a rejected Standard (B2B) document had no reissue path at
     * all, even though buyer inheritance and Clearance-vs-Reporting routing on regeneration
     * were already correct (ProductionInvoiceOrchestrator::regenerate() and
     * RegenerateZatcaInvoiceJob already handle a Standard replacement properly; only this
     * gate was missing them). The HTTP-status re-classification below already applies
     * correctly to both: ZATCA's Clearance and Reporting APIs share the same status
     * vocabulary (400 rejection, 401/403 credentials, 5xx/0 transient — Facts Register
     * F-CL-06), and `clearance_rejected` can only be reached on a non-transient 4xx in the
     * first place (Zatca\Jobs\Concerns\ClearsZatcaInvoice checks network/5xx before it).
     *
     * `retrying`, `clearance_retrying`, `clearance_in_doubt` and `pending_*` are excluded:
     * none of them has been refused by ZATCA, so there is nothing to fix — they retry
     * instead. Terminal successes are excluded and hard-guarded below.
     */
    private const REGENERABLE_STATUSES = [
        ZatcaInvoiceStatus::Failed,
        ZatcaInvoiceStatus::ManualReview,
        ZatcaInvoiceStatus::ClearanceRejected,
        ZatcaInvoiceStatus::ClearanceManualReview,
    ];

    /** @return array<int, string> */
    public static function regenerableStatusValues(): array
    {
        return array_map(fn (ZatcaInvoiceStatus $s) => $s->value, self::REGENERABLE_STATUSES);
    }

    /**
     * Checks are ordered so the reason reported is the most actionable one.
     *
     * A superseded document with an expired certificate reads as superseded: the
     * certificate is irrelevant to a document nothing will ever send again.
     */
    public function verdict(?ZatcaInvoice $invoice): RegenerationVerdict
    {
        if (! $invoice || ! $invoice->status) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NO_ZATCA_DOCUMENT);
        }

        // Hard guard, first and independent of everything else: an invoice ZATCA has
        // accepted is final. Reporting a second document for the same sale would duplicate
        // it in ZATCA's records, which no later check could undo.
        if ($invoice->status->isTerminalSuccess()) {
            return RegenerationVerdict::blocked(RegenerationVerdict::ALREADY_REPORTED);
        }

        if ($invoice->isSuperseded()) {
            return RegenerationVerdict::blocked(RegenerationVerdict::SUPERSEDED);
        }

        if ($invoice->isRegenerationInFlight() || $invoice->status === ZatcaInvoiceStatus::Retrying) {
            return RegenerationVerdict::blocked(RegenerationVerdict::IN_FLIGHT);
        }

        // Without a signed document nothing was ever submitted, so nothing was rejected.
        // Signing can fail after the row exists, so status alone is not proof of this.
        if (blank($invoice->signed_xml_base64)) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NOT_SUBMITTABLE);
        }

        if (! in_array($invoice->status, self::REGENERABLE_STATUSES, true)) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NO_ATTEMPT_RECORDED);
        }

        $attempt = $invoice->latestSubmissionAttempt;
        $httpStatus = $attempt?->http_status;

        // No recorded attempt means nothing proves ZATCA refused this document. Guessing
        // would repeat the mistake this whole policy exists to prevent: acting on an
        // assumption about a failure nobody observed.
        if ($attempt === null || $httpStatus === null) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NO_ATTEMPT_RECORDED);
        }

        $httpStatus = (int) $httpStatus;

        // HTTP 0 is a network failure and 5xx is a ZATCA server error. In both the document
        // was never judged, so resending the same signed bytes is the correct action.
        if ($httpStatus === 0 || $httpStatus >= 500) {
            return RegenerationVerdict::blocked(RegenerationVerdict::TRANSIENT_FAILURE_RETRY_INSTEAD);
        }

        // ZATCA refused who we are, not what we sent. A new document changes nothing.
        if ($httpStatus === 401 || $httpStatus === 403) {
            return RegenerationVerdict::blocked(RegenerationVerdict::CREDENTIALS_ERROR);
        }

        // Anything below 4xx is not a rejection of the document either.
        if ($httpStatus < 400) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NO_ATTEMPT_RECORDED);
        }

        if (! InvoiceDataSnapshot::isUsable($invoice->invoice_data_snapshot)) {
            return RegenerationVerdict::blocked(RegenerationVerdict::NO_SNAPSHOT);
        }

        // The reporting pipeline refuses to submit under an expired certificate, so building
        // a document now would consume an ICV slot and then stall.
        if ($this->certificateIsExpired($invoice)) {
            return RegenerationVerdict::blocked(RegenerationVerdict::CERTIFICATE_EXPIRED);
        }

        // Business values are frozen and the generator is whatever is deployed, so
        // regenerating repeatedly without a generator fix reproduces the same rejection
        // while consuming a fresh ICV slot each time. The chain stops rather than burning
        // the sequence.
        if ($invoice->regenerationDepth() >= ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH) {
            return RegenerationVerdict::blocked(RegenerationVerdict::CHAIN_DEPTH_EXCEEDED);
        }

        return RegenerationVerdict::eligible();
    }

    /** Convenience for callers that only need the verdict. */
    public function allows(?ZatcaInvoice $invoice): bool
    {
        return $this->verdict($invoice)->eligible;
    }

    /**
     * The same certificate check the reporting pipeline runs before every submission, so
     * the button and the pipeline cannot disagree about whether sending is possible.
     */
    private function certificateIsExpired(ZatcaInvoice $invoice): bool
    {
        $state = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->value('certificate_state');

        $value = $state instanceof ZatcaCertificateState ? $state->value : $state;

        return $value === ZatcaCertificateState::Expired->value;
    }
}
