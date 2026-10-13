<?php

namespace Zatca\Tests\Feature\Characterization;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\State\EgsRegistry;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\State\ProductionPreviousInvoiceHashManager;
use Zatca\Tests\TestCase;

/**
 * Small shared contracts B2B builds on: the status vocabulary and its whitelists,
 * EGS resolution, per-EGS chains, and the list of places that put a reporting job on
 * the queue.
 *
 * @group characterization
 */
class ContractInventoryCharacterizationTest extends TestCase
{
    /**
     * The eight reporting statuses are unchanged, in order. P16 added the clearance
     * statuses after them; they are listed here so that a rename is a conscious change
     * (renaming after any row exists is forbidden).
     */
    public function test_status_vocabulary_is_unchanged(): void
    {
        $this->assertSame(
            [
                'draft', 'generated', 'pending_reporting', 'reported', 'reported_with_warnings', 'failed', 'retrying', 'manual_review',
                'pending_clearance', 'clearance_retrying', 'clearance_in_doubt', 'cleared', 'cleared_with_warnings', 'clearance_rejected', 'clearance_manual_review',
            ],
            array_map(fn (ZatcaInvoiceStatus $s) => $s->value, ZatcaInvoiceStatus::cases())
        );
    }

    public function test_status_whitelists_are_unchanged(): void
    {
        $pick = fn (callable $predicate) => array_values(array_map(
            fn (ZatcaInvoiceStatus $s) => $s->value,
            array_filter(ZatcaInvoiceStatus::cases(), $predicate)
        ));

        // Reporting members unchanged; P16 added only the two cleared states to terminal success.
        $this->assertSame(['reported', 'reported_with_warnings'], $pick(fn ($s) => ! $s->isClearanceState() && $s->isTerminalSuccess()));
        $this->assertSame(['reported', 'reported_with_warnings', 'cleared', 'cleared_with_warnings'], $pick(fn ($s) => $s->isTerminalSuccess()));
        // No clearance state is retryable by the reporting paths.
        $this->assertSame(['pending_reporting', 'failed', 'retrying'], $pick(fn ($s) => $s->isRetryable()));
    }

    /** default() is simply the first configured key — B2B must not become the default by config order. */
    public function test_the_default_egs_is_the_first_configured_one(): void
    {
        Config::set('zatca.egs', ['second' => Config::get('zatca.egs.default')] + Config::get('zatca.egs'));

        $registry = new EgsRegistry;

        $this->assertSame(['second', 'default'], $registry->identifiers());
        $this->assertSame('second', $registry->default()->egsIdentifier);
    }

    public function test_an_unknown_egs_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EgsRegistry)->for('b2b');
    }

    /** Each EGS keeps its own ICV counter and PIH chain; one never advances the other. */
    public function test_counters_and_hash_chains_are_isolated_per_egs(): void
    {
        Config::set('zatca.egs.other', Config::get('zatca.egs.default'));
        $counter = app(ProductionInvoiceCounterManager::class);
        $pih = app(ProductionPreviousInvoiceHashManager::class);

        $counter->reserve('default', ZatcaEnvironment::Sandbox);
        $pih->commit('default', ZatcaEnvironment::Sandbox, 'HASH-DEFAULT-1');
        $counter->reserve('default', ZatcaEnvironment::Sandbox);

        $first = $counter->reserve('other', ZatcaEnvironment::Sandbox);

        $this->assertSame(1, $first->icv);
        $this->assertSame(base64_encode(hash('sha256', '0')), $first->previousInvoiceHash);
        $this->assertSame(2, $counter->current('default', ZatcaEnvironment::Sandbox));
        $this->assertSame('HASH-DEFAULT-1', $pih->current('default', ZatcaEnvironment::Sandbox));
    }

    public function test_reporting_jobs_take_connection_and_queue_from_config(): void
    {
        Config::set('zatca.queue.connection', 'char-connection');
        Config::set('zatca.queue.queue', 'char-queue');

        foreach ([new ReportZatcaInvoiceJob(1), new RetryZatcaInvoiceJob(1)] as $job) {
            $this->assertSame('char-connection', $job->connection, $job::class);
            $this->assertSame('char-queue', $job->queue, $job::class);
            $this->assertSame(1, $job->tries, $job::class);
        }
    }

    /**
     * Every place that queues a ZATCA submission or generation today. B2B must route
     * Standard documents to clearance at each of them (blueprint ADR-26), so a new or
     * removed site has to be a conscious change: update this list and the touchpoint
     * checklist together.
     */
    public function test_the_dispatch_site_inventory_is_unchanged(): void
    {
        $this->assertSame([
            // P25: these five now branch between Report.../Clear... at dispatch time,
            // so both class-name tokens legitimately appear once each in the source.
            'Jobs/CompleteZatcaInvoiceGenerationJob.php' => ['ClearZatcaInvoiceJob' => 1, 'ReportZatcaInvoiceJob' => 1],
            // Each send path hands a document of the other model to the right one (hard guard).
            'Jobs/Concerns/ClearsZatcaInvoice.php' => ['RetryZatcaInvoiceJob' => 1],
            'Jobs/Concerns/ReportsZatcaInvoice.php' => ['RetryZatcaClearanceJob' => 1],
            'Jobs/GenerateZatcaCreditNoteJob.php' => ['ClearZatcaInvoiceJob' => 1, 'ReportZatcaInvoiceJob' => 1],
            'Jobs/GenerateZatcaDebitNoteJob.php' => ['ClearZatcaInvoiceJob' => 1, 'ReportZatcaInvoiceJob' => 1],
            'Jobs/GenerateZatcaInvoiceJob.php' => ['ClearZatcaInvoiceJob' => 1, 'ReportZatcaInvoiceJob' => 1],
            'Jobs/RegenerateZatcaInvoiceJob.php' => ['ClearZatcaInvoiceJob' => 1, 'ReportZatcaInvoiceJob' => 1],
            'Services/CreditNote/CreditNoteIssuer.php' => ['GenerateZatcaCreditNoteJob' => 1],
            'Services/DebitNote/DebitNoteIssuer.php' => ['GenerateZatcaDebitNoteJob' => 1],
            // report-pending, requeue and certificate-renewal resume all re-send through
            // this one router, which picks the job from the submission model.
            'Support/SubmissionRetry.php' => ['RetryZatcaClearanceJob' => 1, 'RetryZatcaInvoiceJob' => 1],
        ], $this->dispatchSites());
    }

    /** @return array<string, array<string, int>> */
    private function dispatchSites(): array
    {
        $src = realpath(__DIR__.'/../../../src');
        $sites = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/\b(\w*Zatca\w*Job)::dispatch(?:Sync|Now)?\s*\(/', (string) file_get_contents($file->getPathname()), $m);
            if ($m[1] === []) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            $counts = array_count_values($m[1]);
            ksort($counts);
            $sites[$relative] = $counts;
        }

        ksort($sites);

        return $sites;
    }
}
