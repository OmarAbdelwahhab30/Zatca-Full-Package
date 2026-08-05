<?php

namespace Asas\Zatca\Console\Commands;

use Asas\Zatca\Enums\ZatcaInvoiceStatus;
use Asas\Zatca\Jobs\RetryZatcaInvoiceJob;
use Asas\Zatca\Models\ZatcaInvoice;
use Illuminate\Console\Command;

/**
 * Re-dispatches reporting for every invoice still pending, without
 * regenerating XML/UUID/ICV/PIH/Hash. Intended to run on the scheduler
 * (see docs/ZATCA_ACTIVATION_GUIDE.md).
 *
 * Only retries genuinely temporary states: `pending_reporting` (never
 * attempted, or paused while a certificate was expired) and `retrying`
 * (a transient network/5xx failure with attempts remaining).
 *
 * Deliberately excludes:
 *  - `manual_review` — validation errors, invalid signatures, contract/SDK
 *    output changes, authentication errors, or retries-exhausted transient
 *    failures. All of these need a human to look at `last_error` first.
 *  - `failed` — nothing in the codebase currently sets an invoice to this
 *    status (ReportsZatcaInvoice only ever produces Reported/
 *    ReportedWithWarnings/Retrying/ManualReview). It is intentionally left
 *    out of the safe-retry list rather than given an assumed meaning.
 */
class ReportPendingInvoices extends Command
{
    protected $signature = 'zatca:report-pending';

    protected $description = 'Re-dispatch ZATCA reporting for invoices still Pending Reporting/Retrying (never regenerates them, never touches manual_review).';

    public function handle(): int
    {
        $invoices = ZatcaInvoice::query()
            ->whereIn('status', [
                ZatcaInvoiceStatus::PendingReporting->value,
                ZatcaInvoiceStatus::Retrying->value,
            ])
            ->get(['id']);

        foreach ($invoices as $invoice) {
            RetryZatcaInvoiceJob::dispatch($invoice->id);
        }

        $this->info("Dispatched {$invoices->count()} pending invoice(s) for reporting.");

        return self::SUCCESS;
    }
}
