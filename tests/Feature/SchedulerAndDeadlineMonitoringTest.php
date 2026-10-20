<?php

namespace Zatca\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\SchedulerHeartbeat;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\Feature\Characterization\CreatesInvoiceRows;
use Zatca\Tests\TestCase;

/**
 * zatca:status and zatca:health-check must notice (a) a scheduler that stopped running the
 * retry sweep and (b) Simplified documents closing in on ZATCA's 24-hour reporting deadline.
 */
class SchedulerAndDeadlineMonitoringTest extends TestCase
{
    use BuildsProductionInvoices;
    use CreatesInvoiceRows;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Without this, Artisan::call() output is swallowed by the console mock.
        $this->withoutMockingConsoleOutput();

        // The heartbeat needs a cache that persists between processes; the test default is `array`.
        $this->cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zatca-heartbeat-'.uniqid();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cachePath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);

        // Overrides the trait's tearDown: a clearance row would otherwise block the
        // down-migration (rollback floor for B2B columns).
        try {
            ZatcaInvoice::query()->delete();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------ scheduler

    public function test_a_report_pending_run_is_recorded_even_when_invoicing_is_disabled(): void
    {
        config(['zatca.enabled' => false]);

        $this->assertSame(0, Artisan::call('zatca:report-pending'));

        $this->assertSame(SchedulerHeartbeat::STATE_OK, app(SchedulerHeartbeat::class)->status()['state']);
    }

    public function test_a_scheduler_that_never_ran_is_a_warning_not_a_failure(): void
    {
        $this->assertSame(SchedulerHeartbeat::STATE_NEVER, app(SchedulerHeartbeat::class)->status()['state']);
        $this->assertSame('warn', $this->check('Scheduler')['level']);
        $this->assertSame(0, Artisan::call('zatca:status'));
    }

    public function test_a_stalled_scheduler_fails_status_and_health_check(): void
    {
        $this->recordRunMinutesAgo(45);

        $this->assertSame(SchedulerHeartbeat::STATE_STALE, app(SchedulerHeartbeat::class)->status()['state']);
        $this->assertSame('fail', $this->check('Scheduler')['level']);
        $this->assertSame(1, Artisan::call('zatca:status'));

        Artisan::call('zatca:health-check');
        $this->assertStringContainsString('[FAIL] Scheduler', Artisan::output());
    }

    public function test_a_recent_run_is_healthy(): void
    {
        $this->recordRunMinutesAgo(6);

        $this->assertSame('ok', $this->check('Scheduler')['level']);

        Artisan::call('zatca:health-check');
        $this->assertStringContainsString('[OK] Scheduler', Artisan::output());
    }

    public function test_the_stall_threshold_is_configurable(): void
    {
        $this->recordRunMinutesAgo(45);
        config(['zatca.scheduler.stale_after_minutes' => 120]);

        $this->assertSame('ok', $this->check('Scheduler')['level']);
    }

    public function test_a_cache_that_does_not_persist_cannot_be_verified_and_does_not_fail(): void
    {
        config(['cache.default' => 'array']);

        $this->assertSame(SchedulerHeartbeat::STATE_UNVERIFIABLE, app(SchedulerHeartbeat::class)->status()['state']);
        $this->assertSame('warn', $this->check('Scheduler')['level']);
        $this->assertSame(0, Artisan::call('zatca:status'));
    }

    // ------------------------------------------------------------- deadline

    public function test_a_fresh_unreported_document_is_not_flagged(): void
    {
        $this->unreported(ZatcaInvoiceStatus::PendingReporting, hoursOld: 10);

        $this->assertSame('ok', $this->check('Reporting deadline')['level']);
    }

    public function test_an_unreported_document_past_the_warning_threshold_is_a_warning(): void
    {
        $this->unreported(ZatcaInvoiceStatus::Retrying, hoursOld: 21);

        $this->assertSame('warn', $this->check('Reporting deadline')['level']);
    }

    public function test_a_document_in_manual_review_counts_towards_the_deadline(): void
    {
        $this->unreported(ZatcaInvoiceStatus::ManualReview, hoursOld: 21);

        $this->assertSame('warn', $this->check('Reporting deadline')['level']);
    }

    public function test_a_document_past_24_hours_fails(): void
    {
        $this->unreported(ZatcaInvoiceStatus::PendingReporting, hoursOld: 25);

        $this->assertSame('fail', $this->check('Reporting deadline')['level']);
    }

    public function test_the_warning_threshold_is_configurable(): void
    {
        config(['zatca.reporting_deadline.warn_after_hours' => 10]);
        $this->unreported(ZatcaInvoiceStatus::PendingReporting, hoursOld: 12);

        $this->assertSame('warn', $this->check('Reporting deadline')['level']);
    }

    /** Reported, superseded and B2B (clearance) documents are not on the 24-hour reporting clock. */
    public function test_documents_that_are_not_pending_b2c_reports_are_ignored(): void
    {
        $this->unreported(ZatcaInvoiceStatus::Reported, hoursOld: 30);
        $this->unreported(ZatcaInvoiceStatus::PendingClearance, hoursOld: 30, overrides: ['submission_model' => 'clearance']);

        $replacement = $this->unreported(ZatcaInvoiceStatus::PendingReporting, hoursOld: 1);
        $this->unreported(ZatcaInvoiceStatus::ManualReview, hoursOld: 30, overrides: ['superseded_by_id' => $replacement->id]);

        $this->assertSame('ok', $this->check('Reporting deadline')['level']);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{check: string, level: string, detail: string} */
    private function check(string $name): array
    {
        // A huge --stale-minutes keeps the older "Reporting queue" check out of the way.
        Artisan::call('zatca:status', ['--json' => true, '--stale-minutes' => 1000000]);
        $report = json_decode(Artisan::output(), true);

        foreach ($report['checks'] as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }

        $this->fail("zatca:status reported no [{$name}] check.");
    }

    private function recordRunMinutesAgo(int $minutes): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes($minutes));
        app(SchedulerHeartbeat::class)->record();
        CarbonImmutable::setTestNow();
    }

    private function unreported(ZatcaInvoiceStatus $status, int $hoursOld, array $overrides = []): ZatcaInvoice
    {
        $row = $this->invoiceRow($status, $overrides);
        $row->forceFill(['created_at' => CarbonImmutable::now()->subHours($hoursOld)])->save();

        return $row;
    }
}
