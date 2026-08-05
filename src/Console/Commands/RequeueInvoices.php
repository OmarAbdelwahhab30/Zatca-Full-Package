<?php

namespace Zatca\Console\Commands;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Puts invoices that stopped retrying on their own back into the queue.
 *
 * `zatca:report-pending` deliberately never touches manual_review, because
 * those invoices stopped for a reason a human must read first. That leaves a
 * gap: after the human fixes the cause (ZATCA outage over, certificate renewed,
 * data corrected) there was no way to restart them. This is that way — an
 * explicit, human-triggered action, never scheduled.
 *
 * Safety:
 *  - Never touches an invoice ZATCA already accepted (reported /
 *    reported_with_warnings) — those are final.
 *  - Never re-signs or regenerates anything. The stored signed XML, UUID, ICV
 *    and PIH are sent again exactly as they are.
 *  - Resets attempt_count by default, otherwise the invoice would be sent once
 *    and immediately fall back into manual_review for exceeding max_attempts.
 */
class RequeueInvoices extends Command
{
    protected $signature = 'zatca:requeue
        {--status=manual_review : Which status to requeue: manual_review, failed, retrying, pending_reporting}
        {--only-transient : Only invoices whose last failure was a ZATCA outage (HTTP 0/5xx). Safe to schedule.}
        {--max-total-attempts=50 : With --only-transient, give up on an invoice that has already been tried this many times}
        {--egs= : Limit to one EGS identifier}
        {--id=* : Requeue specific invoice IDs instead of a whole status}
        {--limit=0 : Maximum number of invoices to requeue (0 = no limit)}
        {--keep-attempts : Do not reset attempt_count (the invoice may bounce straight back to manual_review)}
        {--dry-run : List what would be requeued without dispatching anything}';

    protected $description = 'Put stuck invoices (manual_review by default) back into the reporting queue. Use --only-transient to schedule it safely.';

    /** Statuses this command may act on. Terminal successes are excluded on purpose. */
    private const REQUEUABLE = [
        ZatcaInvoiceStatus::ManualReview,
        ZatcaInvoiceStatus::Failed,
        ZatcaInvoiceStatus::Retrying,
        ZatcaInvoiceStatus::PendingReporting,
    ];

    public function handle(): int
    {
        $ids = (array) $this->option('id');
        $dryRun = (bool) $this->option('dry-run');

        $query = ZatcaInvoice::query();

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $status = $this->resolveStatus();

            if (! $status) {
                return self::FAILURE;
            }

            $query->where('status', $status->value);
        }

        if ($egs = $this->option('egs')) {
            $query->where('egs_identifier', $egs);
        }

        // Hard guard: an invoice ZATCA already accepted must never be sent twice,
        // even if its ID was passed explicitly.
        $query->whereNotIn('status', [
            ZatcaInvoiceStatus::Reported->value,
            ZatcaInvoiceStatus::ReportedWithWarnings->value,
        ]);

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $invoices = $query->orderBy('id')
            ->with('latestSubmissionAttempt')
            ->withCount('submissionAttempts')
            ->get();

        if ($this->option('only-transient')) {
            $invoices = $this->keepOnlyTransientFailures($invoices);
        }

        if ($invoices->isEmpty()) {
            $this->info('Nothing to requeue.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Attempts', 'Last HTTP', 'Last error'],
            $invoices->map(fn (ZatcaInvoice $invoice) => [
                $invoice->id,
                $invoice->status->value,
                $invoice->attempt_count,
                $invoice->latestSubmissionAttempt?->http_status ?? '-',
                str($invoice->last_error ?? '-')->limit(50),
            ])->all()
        );

        if ($dryRun) {
            $this->warn("DRY RUN — {$invoices->count()} invoice(s) would be requeued. Nothing was dispatched.");

            return self::SUCCESS;
        }

        $resetAttempts = ! $this->option('keep-attempts');

        foreach ($invoices as $invoice) {
            $invoice->update([
                'status' => ZatcaInvoiceStatus::Retrying,
                'attempt_count' => $resetAttempts ? 0 : $invoice->attempt_count,
            ]);

            RetryZatcaInvoiceJob::dispatch($invoice->id)
                ->onConnection(Config::get('zatca.queue.connection'))
                ->onQueue(Config::get('zatca.queue.queue'));
        }

        $this->info(sprintf(
            'Requeued %d invoice(s)%s. Make sure a worker is running on the "%s" queue.',
            $invoices->count(),
            $resetAttempts ? ' with attempt counters reset' : ' keeping attempt counters',
            Config::get('zatca.queue.queue', 'zatca')
        ));

        return self::SUCCESS;
    }

    /**
     * Keeps only invoices whose LAST attempt failed for a reason that retrying can
     * fix — a network error (HTTP 0) or a ZATCA server error (5xx). An invoice ZATCA
     * actively rejected (4xx, validation) would fail identically forever, so it is
     * left for a human no matter how often this runs.
     *
     * Also drops anything that has already been attempted an absurd number of times:
     * at that point "transient" is no longer a credible explanation.
     *
     * @param  \Illuminate\Support\Collection<int, ZatcaInvoice>  $invoices
     * @return \Illuminate\Support\Collection<int, ZatcaInvoice>
     */
    private function keepOnlyTransientFailures($invoices)
    {
        $maxTotalAttempts = max(1, (int) $this->option('max-total-attempts'));
        $skippedRejected = 0;
        $skippedExhausted = 0;

        $kept = $invoices->filter(function (ZatcaInvoice $invoice) use ($maxTotalAttempts, &$skippedRejected, &$skippedExhausted) {
            if ($invoice->submission_attempts_count > $maxTotalAttempts) {
                $skippedExhausted++;

                return false;
            }

            $httpStatus = $invoice->latestSubmissionAttempt?->http_status;

            // No attempt on record: nothing proves this was an outage, so don't guess.
            if ($httpStatus === null) {
                $skippedRejected++;

                return false;
            }

            $isTransient = (int) $httpStatus === 0 || (int) $httpStatus >= 500;

            if (! $isTransient) {
                $skippedRejected++;
            }

            return $isTransient;
        })->values();

        if ($skippedRejected > 0) {
            $this->line("Skipped {$skippedRejected} invoice(s) ZATCA actually rejected — those need a human, not a retry.");
        }

        if ($skippedExhausted > 0) {
            $this->warn("Skipped {$skippedExhausted} invoice(s) already attempted more than {$maxTotalAttempts} times — treat these as stuck.");
        }

        return $kept;
    }

    private function resolveStatus(): ?ZatcaInvoiceStatus
    {
        $requested = (string) $this->option('status');
        $status = ZatcaInvoiceStatus::tryFrom($requested);

        if (! $status || ! in_array($status, self::REQUEUABLE, true)) {
            $allowed = implode(', ', array_map(fn (ZatcaInvoiceStatus $s) => $s->value, self::REQUEUABLE));

            $this->error("Cannot requeue status [{$requested}]. Allowed: {$allowed}.");

            if ($status?->isTerminalSuccess()) {
                $this->line('That status means ZATCA already accepted the invoice — resending it is never correct.');
            }

            return null;
        }

        return $status;
    }
}
