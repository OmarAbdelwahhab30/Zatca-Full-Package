<?php

namespace Zatca\Services\DebitNote;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;

/**
 * Decides whether a Debit Note may be issued against one ZATCA invoice document.
 * The single place this is computed — asked by the options endpoint, the issue
 * endpoint and the list/details resources alike, exactly like
 * CreditNoteEligibilityPolicy.
 *
 * Deliberately simpler than the Credit Note policy, and that is the whole point:
 * it runs the same five document-level checks (enabled, exists, is an invoice, was
 * accepted by ZATCA, has a usable snapshot, live certificate) and then STOPS.
 * There is no ledger consultation, because "eligible for a debit note" is not a
 * question of what is left to consume. A debit note adds a charge; an invoice can
 * always have another charge added, however many credit or debit notes already
 * exist against it. Copying CreditNoteLedger here would have invented a limit the
 * business does not have.
 *
 * Deliberately does NOT check the host's ZATCA_EFFECTIVE_DATE cutoff or its
 * demo-seed marker — both are host conventions this package has no business
 * knowing about. See AdminDebitNoteService.
 *
 * $invoice must already be the AUTHORITATIVE document (caller's responsibility),
 * for the same reason as the Credit Note policy: under a row lock, the caller
 * controls exactly which row's eligibility is being decided.
 */
class DebitNoteEligibilityPolicy
{
    public function __construct(
        private readonly ZatcaGate $gate = new ZatcaGate,
    ) {}

    public function verdict(?ZatcaInvoice $invoice): DebitNoteEligibilityVerdict
    {
        if ($this->gate->disabled()) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::ZATCA_DISABLED);
        }

        if (! $invoice || ! $invoice->status) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::NO_ZATCA_DOCUMENT);
        }

        if ($invoice->document_type?->isInvoiceKind() !== true) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::NOT_AN_INVOICE);
        }

        // Covers Draft, Generated, PendingReporting, Retrying, Failed and ManualReview in
        // one check: a Debit Note references a sale ZATCA has actually accepted, never one
        // still in flight or one that never made it.
        if (! $invoice->status->isTerminalSuccess()) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::NOT_REPORTED);
        }

        if (! InvoiceDataSnapshot::isUsable($invoice->invoice_data_snapshot)) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::NO_SNAPSHOT);
        }

        if ($this->certificateIsExpired($invoice)) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::CERTIFICATE_EXPIRED);
        }

        // One adjustment at a time per invoice, of either kind — see
        // ZatcaInvoice::hasAdjustmentInProgress(). The issuer re-reads this under its lock.
        if ($invoice->hasAdjustmentInProgress()) {
            return DebitNoteEligibilityVerdict::blocked(DebitNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS);
        }

        return DebitNoteEligibilityVerdict::eligible();
    }

    public function allows(?ZatcaInvoice $invoice): bool
    {
        return $this->verdict($invoice)->eligible;
    }

    /** Same check ReportsZatcaInvoice/RegenerationPolicy run before spending an ICV or submitting. */
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
