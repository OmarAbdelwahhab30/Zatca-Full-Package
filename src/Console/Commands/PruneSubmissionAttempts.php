<?php

namespace Asas\Zatca\Console\Commands;

use Asas\Zatca\Enums\ZatcaInvoiceStatus;
use Asas\Zatca\Models\ZatcaSubmissionAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Deletes submission_attempts rows older than the configured retention
 * window for invoices that reached a terminal success — the attempt
 * history itself is operational noise once reporting succeeded; the
 * invoice row (never pruned) keeps the final outcome.
 */
class PruneSubmissionAttempts extends Command
{
    protected $signature = 'zatca:prune-submission-attempts';

    protected $description = 'Prune old zatca_submission_attempts rows for successfully reported invoices.';

    public function handle(): int
    {
        $days = Config::get('zatca.submission_attempts.prune_after_days', 180);

        $deleted = ZatcaSubmissionAttempt::query()
            ->whereIn('outcome', [ZatcaInvoiceStatus::Reported->value, ZatcaInvoiceStatus::ReportedWithWarnings->value])
            ->where('attempted_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} submission attempt(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
