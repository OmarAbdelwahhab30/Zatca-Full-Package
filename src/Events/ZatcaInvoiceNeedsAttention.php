<?php

namespace Zatca\Events;

use Zatca\Models\ZatcaInvoice;

/**
 * Fired when an invoice stops making progress on its own and a human has to
 * look at it (status became manual_review).
 *
 * The package writes this to the log too, but a log line is not an alerting
 * channel — nobody reads logs at 2am. Listen to this event in the host app to
 * send mail/Slack/PagerDuty. Without a listener, a stuck invoice is silent
 * until someone happens to run `zatca:status`.
 *
 * The invoice is NOT lost and NOT re-signed: its signed XML, UUID, ICV and PIH
 * are intact. After fixing the cause, `zatca:requeue` puts it back in the queue.
 */
class ZatcaInvoiceNeedsAttention
{
    public function __construct(
        public readonly ZatcaInvoice $invoice,
        /** Human-readable cause, already sanitized — safe to put in an alert. */
        public readonly string $reason,
    ) {}
}
