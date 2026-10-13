<?php

namespace Zatca\Tests\Feature\Characterization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Mockery;
use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\DTOs\Payloads\ProductionCsidPayload;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Jobs\RetryZatcaClearanceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;

/**
 * Which rows each B2C recovery path picks up today.
 *
 * B2B adds clearance statuses and Standard documents to the same table. Each path
 * below must keep selecting exactly what it selects now for Simplified documents,
 * and — once B2B exists — nothing else. These tests pin the "now".
 *
 * Existing coverage not repeated here: requeue of manual review / dry run / terminal
 * success / --only-transient (OperationalVisibilityTest), kill switch on every path
 * (ZatcaKillSwitchTest), RegenerationPolicy and DraftCompletionPolicy verdicts
 * (their own tests).
 *
 * @group characterization
 */
class SelectionCharacterizationTest extends TestCase
{
    use CreatesInvoiceRows;

    /**
     * Unsigned rows break the package's own down-migration on SQLite (see
     * BuildsProductionInvoices::tearDown), so rows are cleared before rollback.
     */
    protected function tearDown(): void
    {
        \Zatca\Models\ZatcaSubmissionAttempt::query()->delete();
        \Zatca\Models\ZatcaInvoice::query()->delete();

        parent::tearDown();
    }

    /**
     * `zatca:report-pending` selects Pending Reporting and Retrying for reporting (the
     * clearance equivalents go to clearance, see the next test). Failed is NOT selected
     * although ZatcaInvoiceStatus::isRetryable() includes it, and nothing else
     * (supersession, regeneration claim, missing signature) is checked.
     */
    public function test_report_pending_selects_pending_and_retrying_by_status_only(): void
    {
        Bus::fake();

        $expected = [];
        foreach (ZatcaInvoiceStatus::cases() as $status) {
            $row = $this->realisticRow($status);
            if (in_array($status, [ZatcaInvoiceStatus::PendingReporting, ZatcaInvoiceStatus::Retrying], true)) {
                $expected[] = $row->id;
            }
        }
        $superseded = $this->invoiceRow(ZatcaInvoiceStatus::Retrying);
        $superseded->update(['superseded_by_id' => $expected[0]]);
        $claimed = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['regeneration_claimed_at' => now()]);
        $unsigned = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['signed_xml_base64' => null]);
        $expected = [...$expected, $superseded->id, $claimed->id, $unsigned->id];

        $this->artisan('zatca:report-pending')->assertSuccessful();

        $dispatched = $this->dispatchedRetryIds();
        sort($dispatched);
        $this->assertSame($expected, $dispatched, 'The command does not order its selection; only the set is pinned.');
    }

    /** Clearance documents are re-sent through clearance, never reporting, whatever their status. */
    public function test_report_pending_sends_clearance_documents_to_clearance(): void
    {
        Bus::fake();

        $expected = [];
        foreach ([ZatcaInvoiceStatus::PendingClearance, ZatcaInvoiceStatus::ClearanceRetrying, ZatcaInvoiceStatus::ClearanceInDoubt] as $status) {
            $expected[] = $this->realisticRow($status)->id;
        }
        // A Standard document signed before pending_clearance existed sits in pending_reporting.
        $expected[] = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['submission_model' => 'clearance', 'document_type' => 'standard_invoice'])->id;

        $this->artisan('zatca:report-pending')->assertSuccessful();

        $this->assertSame([], $this->dispatchedRetryIds());
        $this->assertSame($expected, $this->dispatchedClearanceIds());
    }

    /** The retry job carries the configured connection and queue, set in its constructor. */
    public function test_report_pending_jobs_use_the_configured_connection_and_queue(): void
    {
        Bus::fake();
        Config::set('zatca.queue.connection', 'char-connection');
        Config::set('zatca.queue.queue', 'char-queue');
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);

        $this->artisan('zatca:report-pending')->assertSuccessful();

        Bus::assertDispatched(RetryZatcaInvoiceJob::class, fn ($job) => $job->connection === 'char-connection' && $job->queue === 'char-queue');
    }

    /** @dataProvider requeueExclusions */
    public function test_requeue_by_id_still_refuses(string $case, array $overrides): void
    {
        Bus::fake();
        $row = $this->invoiceRow(ZatcaInvoiceStatus::ManualReview, $overrides);
        if ($case === 'superseded') {
            $replacement = $this->invoiceRow(ZatcaInvoiceStatus::Reported);
            $row->update(['superseded_by_id' => $replacement->id]);
        }

        $this->artisan('zatca:requeue', ['--id' => [$row->id]])->assertSuccessful();

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $row->refresh()->status);
    }

    public static function requeueExclusions(): array
    {
        return [
            'superseded' => ['superseded', []],
            'regeneration in flight' => ['claimed', ['regeneration_claimed_at' => '2026-01-01 00:00:00']],
            'never signed' => ['unsigned', ['signed_xml_base64' => null]],
        ];
    }

    /** `--id` bypasses the status filter: a Failed or Draft-with-signature row is requeued. */
    public function test_requeue_by_id_ignores_the_status_filter_for_non_terminal_rows(): void
    {
        Bus::fake();
        $failed = $this->invoiceRow(ZatcaInvoiceStatus::Failed);
        $generated = $this->invoiceRow(ZatcaInvoiceStatus::Generated);

        $this->artisan('zatca:requeue', ['--id' => [$failed->id, $generated->id]])->assertSuccessful();

        $this->assertSame([$failed->id, $generated->id], $this->dispatchedRetryIds());
        $this->assertSame(ZatcaInvoiceStatus::Retrying, $failed->refresh()->status);
        $this->assertSame(0, $failed->attempt_count);
    }

    /**
     * After a production certificate renewal, only the same EGS and environment's
     * Pending Reporting and Retrying rows are resumed.
     */
    public function test_certificate_renewal_resumes_only_that_egs_pending_and_retrying_rows(): void
    {
        Bus::fake();
        Config::set('zatca.egs.other', Config::get('zatca.egs.default'));

        $expected = [];
        $expectedClearance = [];
        foreach (ZatcaInvoiceStatus::cases() as $status) {
            $row = $this->realisticRow($status);
            if (in_array($status, [ZatcaInvoiceStatus::PendingReporting, ZatcaInvoiceStatus::Retrying], true)) {
                $expected[] = $row->id;
            }
            if ($status->isClearanceRetryable()) {
                $expectedClearance[] = $row->id;
            }
        }
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['egs_identifier' => 'other']);
        $this->invoiceRow(ZatcaInvoiceStatus::Retrying, ['environment' => ZatcaEnvironment::Simulation->value]);

        $this->renewDefaultProductionCertificate();

        $this->assertSame($expected, $this->dispatchedRetryIds());
        $this->assertSame($expectedClearance, $this->dispatchedClearanceIds());
    }

    private function renewDefaultProductionCertificate(): void
    {
        $store = app(ZatcaCredentialStore::class);
        $store->store('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'OLD',
            'secret' => 'OLD',
            'certificate' => 'CERT',
            'certificate_expires_at' => CarbonImmutable::now()->subDay(),
            'certificate_state' => ZatcaCertificateState::Expired,
        ]);
        $store->store('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'request_id' => 'req-char',
            'private_key' => 'FAKE-COMPLIANCE-PRIVATE-KEY',
        ]);
        Config::set('zatca.certificate.auto_renewal_enabled', true);

        $this->mock(ConnectorFactory::class, function ($mock) {
            $connector = Mockery::mock(ZatcaConnectorInterface::class);
            $connector->shouldReceive('renewProductionCsid')->once()->andReturn(new ZatcaApiResult(
                successful: true,
                status: ZatcaResponseStatus::Pass,
                httpStatus: 200,
                requestId: null,
                sanitizedResponse: [],
                payload: new ProductionCsidPayload('NEW_TOKEN', 'NEW_SECRET'),
            ));
            $mock->shouldReceive('make')->andReturn($connector);
        });

        $this->assertSame(
            ZatcaCertificateState::Valid,
            app(CertificateLifecycleManager::class)->renew('default', ZatcaCredentialType::Production)
        );
    }

    /** A row whose submission model matches its status, as the package itself writes them. */
    private function realisticRow(ZatcaInvoiceStatus $status): \Zatca\Models\ZatcaInvoice
    {
        return $this->invoiceRow($status, $status->isClearanceState()
            ? ['submission_model' => 'clearance', 'document_type' => 'standard_invoice']
            : []);
    }

    /** @return array<int, int> ids of dispatched RetryZatcaClearanceJob, ascending */
    private function dispatchedClearanceIds(): array
    {
        return Bus::dispatched(RetryZatcaClearanceJob::class)
            ->map(fn (RetryZatcaClearanceJob $job) => (fn () => $this->zatcaInvoiceId)->call($job))
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, int> ids of dispatched RetryZatcaInvoiceJob, in dispatch order */
    private function dispatchedRetryIds(): array
    {
        return Bus::dispatched(RetryZatcaInvoiceJob::class)
            ->map(fn (RetryZatcaInvoiceJob $job) => (fn () => $this->zatcaInvoiceId)->call($job))
            ->values()
            ->all();
    }
}
