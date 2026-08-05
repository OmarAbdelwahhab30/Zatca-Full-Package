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
 * First reporting attempt, dispatched immediately after
 * GenerateZatcaInvoiceJob signs and stores the invoice. If ZATCA is
 * unavailable or the attempt fails transiently, the invoice is left in
 * Retrying/Pending Reporting for `zatca:report-pending` (RetryZatcaInvoiceJob)
 * to pick up later — course access and payment are already final by this point.
 */
class ReportZatcaInvoiceJob implements ShouldQueue
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
