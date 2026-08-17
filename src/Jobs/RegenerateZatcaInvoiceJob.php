<?php

namespace Zatca\Jobs;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a replacement for a ZATCA document that was rejected on validation grounds,
 * then hands it to the normal reporting queue.
 *
 * Queued rather than run in the request, because signing spawns the ZATCA SDK as a
 * subprocess and the bulk action can ask for hundreds of these. The administrator gets
 * an answer as soon as the work is accepted; the outcome lands on the document the same
 * way a retry's outcome does.
 *
 * tries = 1, deliberately. Every other job in this package is safe to retry because it
 * resends something that already exists. This one produces a new fiscal document and
 * consumes an ICV slot that can never be reclaimed, so an automatic second attempt is
 * not a recovery — it is a second invoice. When this job fails, a human decides what
 * happens next.
 *
 * The regeneration claim on the document is released in a finally block, so a failure
 * here leaves the document exactly as regenerable as it was before, rather than stuck
 * looking like work is still in progress.
 */
class RegenerateZatcaInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array{type: string, id: int|string}|null  $triggeredBy  the admin who asked for
     *   this. Scalars only — never a model — so the queued payload cannot go stale, matching
     *   how RetryZatcaInvoiceJob and GenerateZatcaInvoiceJob carry their actor.
     */
    public function __construct(
        private readonly int $originalZatcaInvoiceId,
        private readonly ?array $triggeredBy = null,
    ) {
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function handle(ProductionInvoiceOrchestrator $orchestrator): void
    {
        $original = ZatcaInvoice::query()->find($this->originalZatcaInvoiceId);

        if (! $original) {
            return;
        }

        // Re-checked here, not just at claim time: the claim may have been made minutes
        // ago, and ZATCA could have accepted the document in between via a scheduled
        // retry. Regenerating an accepted invoice would duplicate it in ZATCA's records.
        if ($original->status?->isTerminalSuccess() || $original->superseded_by_id !== null) {
            $this->releaseClaim();

            Log::info(
                "[ZATCA] Skipped regeneration of document #{$original->id}: it was already "
                .'finalized before this job ran.',
                ['status' => $original->status?->value, 'superseded_by_id' => $original->superseded_by_id]
            );

            return;
        }

        try {
            $replacement = $orchestrator->regenerate($original, $this->triggeredBy);

            if ($replacement->status === ZatcaInvoiceStatus::Draft) {
                // Signing did not complete. The draft keeps its reserved ICV and will be
                // resumed by the next regeneration rather than replaced, so nothing is
                // reported here and nothing is lost.
                Log::warning(
                    "[ZATCA] Regeneration of document #{$original->id} produced replacement "
                    ."#{$replacement->id} but signing did not complete. The original remains current."
                );

                return;
            }

            Log::info(
                "[ZATCA] Regenerated document #{$original->id} as #{$replacement->id}; queueing it for reporting.",
                ['icv' => $replacement->icv]
            );

            // The same reporting path the purchase flow uses. The actor travels with it so
            // the replacement's first attempt is attributed to the admin who asked for it.
            ReportZatcaInvoiceJob::dispatch($replacement->id, $this->triggeredBy);
        } catch (Throwable $e) {
            Log::error(
                "[ZATCA] Regeneration of document #{$original->id} failed: ".$e->getMessage()
            );

            throw $e;
        } finally {
            $this->releaseClaim();
        }
    }

    /**
     * Runs even when regeneration threw, so a failure never leaves the document looking
     * like a regeneration is still running. Without this, a single failure would make the
     * document permanently un-regenerable and un-retryable.
     */
    public function failed(?Throwable $e): void
    {
        $this->releaseClaim();
    }

    private function releaseClaim(): void
    {
        ZatcaInvoice::query()
            ->whereKey($this->originalZatcaInvoiceId)
            ->update(['regeneration_claimed_at' => null]);
    }
}
