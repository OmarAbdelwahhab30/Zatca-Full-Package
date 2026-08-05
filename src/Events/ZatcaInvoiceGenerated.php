<?php

namespace Zatca\Events;

use Zatca\Models\ZatcaInvoice;

/**
 * Fired right after a production invoice is signed and stored, before
 * reporting is attempted. The package has zero PDF-rendering code
 * (plan §2.1) — the host app (or an optional publishable Blade view)
 * listens to this event to render/send the student-facing PDF using its
 * own branding. ZatcaInvoice already carries the QR code and all totals.
 */
class ZatcaInvoiceGenerated
{
    public function __construct(public readonly ZatcaInvoice $invoice) {}
}
