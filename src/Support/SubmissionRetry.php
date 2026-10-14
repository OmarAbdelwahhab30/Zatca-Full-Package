<?php

namespace Zatca\Support;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Jobs\RetryZatcaClearanceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;

/**
 * The one place that decides which job re-sends a signed document.
 *
 * A Simplified document is reported and a Standard one is cleared; the two use different
 * ZATCA endpoints. The decision is made from the document's own submission_model, never
 * from its status, so a Standard document can never reach the Reporting endpoint because
 * of the status it happens to be in.
 */
class SubmissionRetry
{
    /** @param  array{type: string, id: int|string}|null  $triggeredBy */
    public static function dispatch(ZatcaInvoice $invoice, ?array $triggeredBy = null): void
    {
        if (self::isClearance($invoice)) {
            RetryZatcaClearanceJob::dispatch($invoice->id, $triggeredBy);

            return;
        }

        RetryZatcaInvoiceJob::dispatch($invoice->id, $triggeredBy);
    }

    public static function isClearance(ZatcaInvoice $invoice): bool
    {
        return $invoice->submission_model === ZatcaSubmissionModel::Clearance;
    }

    /** The "retrying" status that matches the document's own submission model. */
    public static function retryingStatus(ZatcaInvoice $invoice): ZatcaInvoiceStatus
    {
        return self::isClearance($invoice) ? ZatcaInvoiceStatus::ClearanceRetrying : ZatcaInvoiceStatus::Retrying;
    }
}
