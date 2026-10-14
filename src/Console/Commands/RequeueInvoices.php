<?php

namespace Zatca\Console\Commands;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\SubmissionRetry;
use Zatca\Support\ZatcaGate;
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
        {--status=manual_review : Which status to requeue: manual_review, failed, retrying, pending_reporting, clearance_manual_review, clearance_retrying, pending_clearance, clearance_in_doubt}
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
        // Clearance (B2B). clearance_rejected is deliberately absent: ZATCA refused those
        // exact bytes, so only a regenerated document can succeed.
        ZatcaInvoiceStatus::ClearanceManualReview,
        ZatcaInvoiceStatus::ClearanceRetrying,
        ZatcaInvoiceStatus::PendingClearance,
        ZatcaInvoiceStatus::ClearanceInDoubt,
    ];

    public function handle(ZatcaGate $gate): int
    {
        // Checked before --dry-run is even read: this command resets attempt_count on the
        // rows it requeues, so it must not touch anything while ZATCA is off. Scheduled
        // hourly as `zatca:requeue --only-transient`, hence SUCCESS rather than FAILURE.
        if ($gate->disabled()) {
            $this->info('ZATCA invoicing is disabled (zatca.enabled=false). Nothing requeued.');

            return self::SUCCESS;
        }

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
            ZatcaInvoiceStatus::Cleared->value,
            ZatcaInvoiceStatus::ClearedWithWarnings->value,
            // ZATCA's definitive NOT_CLEARED: resending the same bytes can only fail again.
            ZatcaInvoiceStatus::ClearanceRejected->value,
        ]);

        // Same class of guard, for documents that have been replaced rather than accepted.
        // A superseded document was rejected by ZATCA and a regenerated document has taken
        // its place; requeueing it would report the rejected content alongside its
        // replacement. A document with a regeneration in flight is excluded for the same
        // reason — its replacement is being built right now. Both conditions are NULL for
        // every invoice that predates regeneration support, so nothing that used to be
        // requeueable stops being requeueable.
        $query->whereNull('superseded_by_id')->whereNull('regeneration_claimed_at');

        // Requeueing means resending the stored signed document, so a document that was
        // never signed has nothing to resend. The status filter above already excludes
        // draft, but --id bypasses it entirely, and an unsigned row pushed through this
        // path used to be flipped to `retrying` and then fail inside the reporting job on a
        // null signed XML — leaving the document stuck under a status that misdescribes it
        // and blocks every other recovery action. Excluded here rather than in the status
        // check so it holds however the row was selected.
        $query->whereNotNull('signed_xml_base64');

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
            $this->warnAboutUnsignedDrafts($ids);

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
            // Routed by submission model: a Standard (B2B) document is cleared, never reported.
            $invoice->update([
                'status' => SubmissionRetry::retryingStatus($invoice),
                'attempt_count' => $resetAttempts ? 0 : $invoice->attempt_count,
            ]);

            SubmissionRetry::dispatch($invoice);
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
     * Explains the one silent exclusion an operator is likely to hit.
     *
     * Every other filter here follows from the status the operator asked for, but an
     * explicitly named ID that turns out to be unsigned just disappears. Naming it — and
     * naming the action that does apply — is the difference between "the command did
     * nothing" and knowing what to run instead.
     *
     * @param  array<int, mixed>  $ids
     */
    private function warnAboutUnsignedDrafts(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $unsigned = ZatcaInvoice::query()
            ->whereIn('id', $ids)
            ->whereNull('signed_xml_base64')
            ->pluck('id');

        if ($unsigned->isEmpty()) {
            return;
        }

        $this->warn(
            'Skipped invoice(s) '.$unsigned->implode(', ').': signing never completed, so there is no document '
            .'to resend. Requeueing sends the stored signed XML; these need their generation completed instead '
            .'(the E-Invoicing screen offers that, or re-run the generation job).'
        );
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
