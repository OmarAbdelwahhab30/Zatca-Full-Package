<?php

namespace Asas\Zatca\Jobs;

use Asas\Zatca\Contracts\InvoiceSourceContract;
use Asas\Zatca\Contracts\InvoiceSourceResolverContract;
use Asas\Zatca\DTOs\InvoiceSourceReference;
use Asas\Zatca\Exceptions\ZatcaCredentialsMissingException;
use Asas\Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched after a host order is confirmed Paid. Never runs inline in a
 * payment callback/webhook — ZATCA signing/API calls always happen off the
 * request cycle. Never blocks enrollment: enrollment has already succeeded
 * by the time this job runs; a failure here only affects invoice/reporting
 * state, never course access.
 *
 * Carries ONLY scalar reference data (source_type/source_id/egs_identifier)
 * — never the host's model/object itself. The actual record is reloaded
 * fresh at execution time via InvoiceSourceResolverContract, so the queued
 * payload can never go stale between dispatch and execution and can never
 * fail to serialize (unlike passing an arbitrary `mixed` object through
 * SerializesModels).
 */
class GenerateZatcaInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        private readonly string $egsIdentifier = 'default',
    ) {
        $this->tries = (int) Config::get('zatca.retries.generation.tries', 5);
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    /** Convenience constructor for the common case: an Eloquent model as the source. */
    public static function forModel(Model $model, string $egsIdentifier = 'default'): self
    {
        return new self(get_class($model), (string) $model->getKey(), $egsIdentifier);
    }

    public function backoff(): array
    {
        return Config::get('zatca.retries.generation.backoff_seconds', [5, 30, 120, 600]);
    }

    public function handle(
        ProductionInvoiceOrchestrator $orchestrator,
        InvoiceSourceContract $invoiceSource,
        InvoiceSourceResolverContract $resolver
    ): void {
        $reference = new InvoiceSourceReference($this->sourceType, $this->sourceId, $this->egsIdentifier);
        $sourceRecord = $resolver->resolve($reference);

        if ($sourceRecord === null) {
            // The source record no longer exists (deleted) or the resolver doesn't recognize
            // this source_type. Retrying will not help — fail without spamming retries.
            Log::warning("[ZATCA] Invoice generation skipped: source [{$this->sourceType}#{$this->sourceId}] could not be resolved.");
            $this->fail(new \RuntimeException("Unable to resolve invoice source [{$this->sourceType}#{$this->sourceId}]."));

            return;
        }

        try {
            $invoice = $orchestrator->generate($invoiceSource, $sourceRecord, $this->egsIdentifier);
        } catch (ZatcaCredentialsMissingException $e) {
            // Production onboarding hasn't completed for this EGS yet. Retrying won't
            // help until an admin finishes onboarding — fail quietly, don't spam retries.
            Log::warning('[ZATCA] Invoice generation skipped: '.$e->getMessage());
            $this->fail($e);

            return;
        }

        ReportZatcaInvoiceJob::dispatch($invoice->id)
            ->onConnection(Config::get('zatca.queue.connection'))
            ->onQueue(Config::get('zatca.queue.queue'));
    }
}
