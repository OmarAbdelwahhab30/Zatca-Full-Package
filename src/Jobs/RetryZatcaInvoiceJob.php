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

    /**
     * @param  array{type: string, id: int|string}|null  $triggeredBy  Set only when a human
     *   asked for this retry. Scalars only — never a model — so the queued payload can
     *   never go stale, matching how GenerateZatcaInvoiceJob carries its source. Defaults
     *   to null, so `zatca:report-pending`, `zatca:requeue` and CertificateLifecycleManager
     *   dispatch exactly as before.
     */
    public function __construct(
        private readonly int $zatcaInvoiceId,
        private readonly ?array $triggeredBy = null,
    ) {
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function handle(ConnectorFactory $connectorFactory): void
    {
        $this->report($this->zatcaInvoiceId, $connectorFactory, $this->triggeredBy);
    }
}
