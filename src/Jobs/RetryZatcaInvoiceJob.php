<?php

namespace Zatca\Jobs;

use Zatca\Jobs\Concerns\ReportsZatcaInvoice;
use Zatca\Services\Connectors\ConnectorFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

/**
 * Dispatched by `zatca:report-pending` (scheduled) or by
 * CertificateLifecycleManager after a certificate renewal, for any invoice
 * still in Pending Reporting/Retrying/Failed. Reuses the exact same
 * invoice_hash/uuid/signed_xml_base64 stored at generation time — never
 * regenerates the invoice, per plan §15.
 */
class RetryZatcaInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ReportsZatcaInvoice, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $zatcaInvoiceId)
    {
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function handle(ConnectorFactory $connectorFactory): void
    {
        $this->report($this->zatcaInvoiceId, $connectorFactory);
    }
}
