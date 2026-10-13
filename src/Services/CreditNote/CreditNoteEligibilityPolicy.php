<?php

namespace Zatca\Services\CreditNote;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;

/**
 * Decides whether a Credit Note may be issued against one ZATCA invoice document.
 * Same role as Zatca\Services\Regeneration\RegenerationPolicy — the single place this
 * is computed, asked by the options endpoint, the issue endpoint and the list/details
 * resources alike.
 *
 * Deliberately does NOT check the host's ZATCA_EFFECTIVE_DATE cutoff or its demo-seed
 * marker — both are host conventions (config value, seeder marker string) that this
 * package has no business knowing about. The host layer runs those checks before or
 * after calling here; see AdminCreditNoteService.
 *
 * $invoice must already be the AUTHORITATIVE document (caller's responsibility — e.g.
 * $someInvoice->authoritative(), or the row CreditNoteIssuer already locked). This
 * class does not walk the supersede chain itself so that, under a row lock, the
 * caller controls exactly which row's eligibility is being decided.
 */
class CreditNoteEligibilityPolicy
{
    public function __construct(
        private readonly ZatcaGate $gate = new ZatcaGate,
        private readonly CreditNoteLedger $ledger = new CreditNoteLedger,
    ) {}

    public function verdict(?ZatcaInvoice $invoice): CreditNoteEligibilityVerdict
    {
        if ($this->gate->disabled()) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::ZATCA_DISABLED);
        }

        if (! $invoice || ! $invoice->status) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::NO_ZATCA_DOCUMENT);
        }

        if ($invoice->document_type?->isInvoiceKind() !== true) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::NOT_AN_INVOICE);
        }

        // Covers Draft, Generated, PendingReporting, Retrying, Failed and ManualReview in
        // one check: a Credit Note references a sale ZATCA has actually accepted, never
        // one still in flight or one that never made it.
        if (! $invoice->status->isTerminalSuccess()) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::NOT_REPORTED);
        }

        if (! InvoiceDataSnapshot::isUsable($invoice->invoice_data_snapshot)) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::NO_SNAPSHOT);
        }

        if ($this->certificateIsExpired($invoice)) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::CERTIFICATE_EXPIRED);
        }

        // One adjustment at a time per invoice, of either kind — see
        // ZatcaInvoice::hasAdjustmentInProgress(). The issuer re-reads this under its lock.
        if ($invoice->hasAdjustmentInProgress()) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::ADJUSTMENT_IN_PROGRESS);
        }

        // Original lines AND accepted debit note lines — recomputed every time, so a
        // debit note accepted after a full credit makes the invoice creditable again.
        if (! $this->ledger->forOriginal($invoice)->hasAvailableSources()) {
            return CreditNoteEligibilityVerdict::blocked(CreditNoteEligibilityVerdict::FULLY_CREDITED);
        }

        return CreditNoteEligibilityVerdict::eligible();
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
