<?php

namespace Zatca\Jobs;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\DTOs\InvoiceSourceReference;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Exceptions\CrossEgsDuplicateException;
use Zatca\Exceptions\ZatcaCredentialsMissingException;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\ZatcaGate;
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
 * request cycle. Never blocks the sale: the sale has already succeeded
 * by the time this job runs; a failure here only affects invoice/reporting
 * state, never the customer's access.
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
        InvoiceSourceResolverContract $resolver,
        ZatcaGate $gate
    ): void {
        // Backstop for jobs queued before the switch was turned off. A plain return, not
        // fail() and not an exception: a failed job would be retried five times and would
        // land in failed_jobs for work that was cancelled on purpose. Nothing is written,
        // so the source record is left exactly as it was and will be invoiced normally if
        // ZATCA is switched back on and the job re-dispatched.
        if ($gate->disabled()) {
            $gate->skip("invoice generation for [{$this->sourceType}#{$this->sourceId}]");

            return;
        }

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
        } catch (CrossEgsDuplicateException $e) {
            // The sale already has a document on another EGS. Retrying cannot change that.
            Log::error('[ZATCA] Document generation refused: '.$e->getMessage());
            $this->fail($e);

            return;
        } catch (ZatcaCredentialsMissingException $e) {
            // Production onboarding hasn't completed for this EGS yet. Retrying won't
            // help until an admin finishes onboarding — fail quietly, don't spam retries.
            Log::warning('[ZATCA] Invoice generation skipped: '.$e->getMessage());
            $this->fail($e);

            return;
        }

        // P25: a Standard document is cleared, never reported — the two profiles use
        // different ZATCA endpoints and different status vocabularies.
        if ($invoice->submission_model === ZatcaSubmissionModel::Clearance) {
            ClearZatcaInvoiceJob::dispatch($invoice->id)
                ->onConnection(Config::get('zatca.queue.connection'))
                ->onQueue(Config::get('zatca.queue.queue'));

            return;
        }

        ReportZatcaInvoiceJob::dispatch($invoice->id)
            ->onConnection(Config::get('zatca.queue.connection'))
            ->onQueue(Config::get('zatca.queue.queue'));
    }
}
