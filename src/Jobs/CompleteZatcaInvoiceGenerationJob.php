<?php

namespace Zatca\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;

/**
 * Finishes signing a Draft whose generation stopped part-way, then hands it to the normal
 * reporting queue.
 *
 * The recovery path GenerateZatcaInvoiceJob owns until its retry budget runs out. After
 * that the Draft is stranded, and this is how an administrator picks it up again.
 *
 * Queued rather than run in the request. Signing spawns the ZATCA SDK as a subprocess and
 * holds the EGS chain lock for its duration, and neither belongs inside an HTTP request:
 * a slow SDK would hold a fiscal lock open for as long as the web server tolerates it.
 * The administrator gets an answer as soon as the work is accepted, exactly as they do for
 * a retry or a regeneration.
 *
 * tries = 1. Unlike a regeneration this consumes nothing that cannot be reclaimed — the
 * ICV was reserved when the Draft was written — so an automatic retry would be harmless.
 * It is still one attempt, because the failures that strand a Draft in the first place
 * (missing SDK jar, expired credentials, a signing process that will not start) are not
 * the kind that clear in thirty seconds, and the administrator who triggered this is owed
 * a definite answer rather than a job that quietly rattles for ten minutes.
 *
 * On failure the reason is written to last_error, because a Draft that stays a Draft after
 * an explicit request looks identical to one nobody has touched. Recording it is what
 * turns "nothing happened" into something an administrator can act on.
 */
class CompleteZatcaInvoiceGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array{type: string, id: int|string}|null  $triggeredBy  the admin who asked for
     *   this. Scalars only — never a model — so the queued payload cannot go stale, matching
     *   how the other jobs in this package carry their actor.
     */
    public function __construct(
        private readonly int $zatcaInvoiceId,
        private readonly ?array $triggeredBy = null,
    ) {
        $this->onConnection(Config::get('zatca.queue.connection'));
        $this->onQueue(Config::get('zatca.queue.queue'));
    }

    public function handle(ProductionInvoiceOrchestrator $orchestrator): void
    {
        $draft = ZatcaInvoice::query()->find($this->zatcaInvoiceId);

        if (! $draft) {
            return;
        }

        // Re-checked here, not just at claim time: the claim may have been made minutes
        // ago, and GenerateZatcaInvoiceJob's own retry could have signed the Draft in
        // between. Signing a second time would commit a second hash for one ICV.
        if (! $draft->isUnsignedDraft()) {
            $this->releaseClaim();

            Log::info(
                "[ZATCA] Skipped completing generation for document #{$draft->id}: it was already signed "
                .'before this job ran.',
                ['status' => $draft->status?->value]
            );

            return;
        }

        try {
            $completed = $orchestrator->completeGeneration($draft);

            if ($completed->status === ZatcaInvoiceStatus::Draft) {
                // sign() returned without signing — another worker held the row and had
                // already moved it on, or the row vanished underneath us. Nothing to
                // report and nothing lost; the Draft is exactly as it was.
                Log::warning(
                    "[ZATCA] Completing generation for document #{$draft->id} did not sign it. "
                    .'Leaving it as a draft.'
                );

                return;
            }

            Log::info(
                "[ZATCA] Completed generation for document #{$draft->id}; queueing it for reporting.",
                ['icv' => $completed->icv, 'status' => $completed->status->value]
            );

            // The same reporting path the purchase flow uses, so the invoice rejoins the
            // normal pipeline with its full retry budget and its attempt history intact.
            // The actor travels with it so the first attempt is attributed to the admin
            // who asked for it.
            ReportZatcaInvoiceJob::dispatch($completed->id, $this->triggeredBy);
        } catch (Throwable $e) {
            $this->recordFailure($e);

            Log::error(
                "[ZATCA] Completing generation for document #{$draft->id} failed: ".$e->getMessage()
            );

            throw $e;
        } finally {
            $this->releaseClaim();
        }
    }

    /**
     * Runs even when the job failed outright, so a failure never leaves the Draft looking
     * like work is still in progress. Without this a single failure would make the
     * document permanently un-completable from the dashboard.
     */
    public function failed(?Throwable $e): void
    {
        if ($e) {
            $this->recordFailure($e);
        }

        $this->releaseClaim();
    }

    /**
     * Writes why signing failed, without touching anything fiscal.
     *
     * Guarded on the row still being an unsigned draft so a message about a failed signing
     * can never land on a document that has since been signed successfully. last_error is
     * the same column the reporting pipeline uses for its own failures, so the dashboard
     * already surfaces it.
     */
    private function recordFailure(Throwable $e): void
    {
        ZatcaInvoice::query()
            ->whereKey($this->zatcaInvoiceId)
            ->where('status', ZatcaInvoiceStatus::Draft->value)
            ->whereNull('signed_xml_base64')
            ->update(['last_error' => Str::limit('فشل إكمال التوقيع: '.$e->getMessage(), 500)]);
    }

    private function releaseClaim(): void
    {
        ZatcaInvoice::query()
            ->whereKey($this->zatcaInvoiceId)
            ->update(['generation_claimed_at' => null]);
    }
}
