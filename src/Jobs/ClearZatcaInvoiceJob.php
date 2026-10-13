<?php

namespace Zatca\Jobs;

use Zatca\Jobs\Concerns\ClearsZatcaInvoice;
use Zatca\Services\Connectors\ConnectorFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

/**
 * Standard (B2B) counterpart of ReportZatcaInvoiceJob — P25. Dispatched
 * immediately after Generate{Invoice,CreditNote,DebitNote}Job signs and
 * stores a Standard document (invoice->submission_model === Clearance).
 */
class ClearZatcaInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ClearsZatcaInvoice, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $zatcaInvoiceId,
        private readonly ?array $triggeredBy = null,
    ) {
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function handle(ConnectorFactory $connectorFactory): void
    {
        $this->clear($this->zatcaInvoiceId, $connectorFactory, $this->triggeredBy);
    }
}
