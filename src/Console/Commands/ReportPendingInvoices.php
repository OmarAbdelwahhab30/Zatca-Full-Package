<?php

namespace Zatca\Console\Commands;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\RetryZatcaClearanceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\ZatcaGate;
use Illuminate\Console\Command;

/**
 * Re-dispatches reporting/clearance for every invoice still pending, without
 * regenerating XML/UUID/ICV/PIH/Hash. Intended to run on the scheduler
 * (see docs/ZATCA_ACTIVATION_GUIDE.md).
 *
 * Reporting (Simplified/B2C): retries genuinely temporary states only —
 * `pending_reporting` (never attempted, or paused while a certificate was
 * expired) and `retrying` (a transient network/5xx failure with attempts
 * remaining).
 *
 * Clearance (Standard/B2B, P25): retries whatever
 * ZatcaInvoiceStatus::isClearanceRetryable() names — today `pending_clearance`,
 * `clearance_retrying`, and `clearance_in_doubt` (a network failure whose
 * outcome ZATCA never confirmed; still surfaced via ZatcaInvoiceNeedsAttention
 * on every attempt so a human sees it, per ClearsZatcaInvoice's docblock).
 *
 * Deliberately excludes:
 *  - `manual_review` / `clearance_manual_review` / `clearance_rejected` —
 *    validation errors, invalid signatures, contract/SDK output changes,
 *    authentication errors, ZATCA's own rejection (NOT_CLEARED), or
 *    retries-exhausted transient failures. All of these need a human to look
 *    at `last_error` first.
 *  - `failed` — nothing in the codebase currently sets an invoice to this
 *    status (ReportsZatcaInvoice only ever produces Reported/
 *    ReportedWithWarnings/Retrying/ManualReview). It is intentionally left
 *    out of the safe-retry list rather than given an assumed meaning.
 */
class ReportPendingInvoices extends Command
{
    protected $signature = 'zatca:report-pending';

    protected $description = 'Re-dispatch ZATCA reporting/clearance for invoices still pending (never regenerates them, never touches manual_review).';

    public function handle(ZatcaGate $gate): int
    {
        // Runs every five minutes on the scheduler. Exits SUCCESS rather than FAILURE so a
        // deliberately disabled system does not fill the scheduler's log with failures.
        if ($gate->disabled()) {
            $this->info('ZATCA invoicing is disabled (zatca.enabled=false). Nothing dispatched.');

            return self::SUCCESS;
        }

        $invoices = ZatcaInvoice::query()
            ->whereIn('status', [
                ZatcaInvoiceStatus::PendingReporting->value,
                ZatcaInvoiceStatus::Retrying->value,
                // P25: the Standard (Clearance) equivalents. isClearanceRetryable() is
                // read here (not re-listed) so the two never drift apart.
                ZatcaInvoiceStatus::PendingClearance->value,
                ZatcaInvoiceStatus::ClearanceRetrying->value,
                ZatcaInvoiceStatus::ClearanceInDoubt->value,
            ])
            ->get(['id', 'status']);

        foreach ($invoices as $invoice) {
            if ($invoice->status->isClearanceState()) {
                RetryZatcaClearanceJob::dispatch($invoice->id);
            } else {
                RetryZatcaInvoiceJob::dispatch($invoice->id);
            }
        }

        $this->info("Dispatched {$invoices->count()} pending invoice(s) for reporting/clearance.");

        return self::SUCCESS;
    }
}
