<?php

namespace Zatca\Jobs;

use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Exceptions\CrossEgsDuplicateException;
use Zatca\Exceptions\ZatcaCredentialsMissingException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched by CreditNoteIssuer right after a Credit Note's business record commits.
 * Generates and signs the technical document from the record's OWN frozen
 * document_snapshot — never re-reads the original invoice or the host — so this is
 * naturally idempotent: two runs for the same Credit Note reach the same
 * source_reference (the Credit Note number) and ProductionInvoiceOrchestrator's
 * existing-row lookup returns the same document rather than reserving a second ICV.
 *
 * Scenario 14: on final failure (retry budget exhausted, or a permanently unrecoverable
 * error), generation_failed_at/last_error are written so the record is not silently
 * stuck — see failed(). The reserved lines stay reserved (BR-11) until an admin retries
 * generation or cancels the note (CreditNoteCanceller).
 */
class GenerateZatcaCreditNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(private readonly int $creditNoteId)
    {
        $this->tries = (int) Config::get('zatca.retries.generation.tries', 5);
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function backoff(): array
    {
        return Config::get('zatca.retries.generation.backoff_seconds', [5, 30, 120, 600]);
    }

    public function handle(ProductionInvoiceOrchestrator $orchestrator, ZatcaGate $gate): void
    {
        if ($gate->disabled()) {
            $gate->skip("credit note generation for #{$this->creditNoteId}");

            return;
        }

        $creditNote = ZatcaCreditNote::query()->find($this->creditNoteId);

        if (! $creditNote) {
            Log::warning("[ZATCA] Credit Note generation skipped: record #{$this->creditNoteId} no longer exists.");
            $this->fail(new \RuntimeException("Credit Note #{$this->creditNoteId} not found."));

            return;
        }

        if ($creditNote->isCancelled()) {
            return; // Cancelled before this ran — nothing to generate.
        }

        try {
            $invoiceData = InvoiceDataSnapshot::fromArray($creditNote->document_snapshot);
            $document = $orchestrator->generateCreditNote($invoiceData, $creditNote->egs_identifier, $creditNote->getKey());
        } catch (CrossEgsDuplicateException $e) {
            // The sale already has a document on another EGS. Retrying cannot change that.
            Log::error('[ZATCA] Document generation refused: '.$e->getMessage());
            $this->fail($e);

            return;
        } catch (ZatcaCredentialsMissingException $e) {
            // Production onboarding hasn't completed for this EGS — retrying will not help.
            Log::warning('[ZATCA] Credit Note generation skipped: '.$e->getMessage());
            $this->fail($e);

            return;
        }

        // A prior attempt's failure marker no longer applies once generation succeeds.
        if ($creditNote->generation_failed_at !== null) {
            $creditNote->update(['generation_failed_at' => null, 'last_error' => null]);
        }

        if ($document->submission_model === ZatcaSubmissionModel::Clearance) {
            ClearZatcaInvoiceJob::dispatch($document->id)
                ->onConnection(Config::get('zatca.queue.connection'))
                ->onQueue(Config::get('zatca.queue.queue'));

            return;
        }

        ReportZatcaInvoiceJob::dispatch($document->id)
            ->onConnection(Config::get('zatca.queue.connection'))
            ->onQueue(Config::get('zatca.queue.queue'));
    }

    public function failed(?Throwable $e): void
    {
        ZatcaCreditNote::query()
            ->whereKey($this->creditNoteId)
            ->whereNull('accepted_at')
            ->update([
                'generation_failed_at' => now(),
                'last_error' => $e?->getMessage() ?? 'Credit note generation failed.',
            ]);
    }
}
