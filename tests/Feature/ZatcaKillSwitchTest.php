<?php

namespace Zatca\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaDisabledException;
use Zatca\Jobs\CompleteZatcaInvoiceGenerationJob;
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Jobs\RegenerateZatcaInvoiceJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\State\ProductionPreviousInvoiceHashManager;
use Zatca\Support\ZatcaGate;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Proves the global kill switch (config zatca.enabled) actually stops everything, and that
 * turning it off costs nothing that cannot be undone by turning it back on.
 *
 * The fixtures are built with ZATCA enabled and the switch is thrown afterwards, because
 * that is the real scenario: a running system with signed invoices, queued jobs and claimed
 * documents, switched off underneath them. A test that started from an empty database would
 * prove only that nothing happens when there is nothing to act on.
 */
class ZatcaKillSwitchTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        // Any real outbound call is a failure of the thing under test, not a network
        // problem: preventing every request means "no HTTP reached ZATCA" is asserted by
        // construction and not merely unobserved.
        Http::preventStrayRequests();
    }

    private function disableZatca(): void
    {
        config()->set('zatca.enabled', false);
    }

    /**
     * An SDK that fails the test if it is invoked at all.
     *
     * Signing is the expensive, irreversible half of ZATCA — it spawns a subprocess and
     * commits a hash to the EGS chain — so "the SDK was never called" is asserted by making
     * any call explode rather than by counting afterwards.
     */
    private function forbiddenSdk(): ZatcaSdkInterface
    {
        return new class implements ZatcaSdkInterface
        {
            private function forbidden(): never
            {
                throw new \RuntimeException('The ZATCA SDK was invoked while ZATCA was disabled.');
            }

            public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
            {
                $this->forbidden();
            }

            public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
            {
                $this->forbidden();
            }

            public function generateHash(string $xmlPath): string
            {
                $this->forbidden();
            }

            public function validateInvoice(string $signedXmlPath, string $certificatePath): array
            {
                $this->forbidden();
            }

            public function version(): string
            {
                $this->forbidden();
            }

            public function executablePath(): string
            {
                $this->forbidden();
            }
        };
    }

    /** ICV and PIH together — the two pieces of fiscal state that can never be un-consumed. */
    private function chainState(): array
    {
        return [
            'icv' => app(ProductionInvoiceCounterManager::class)->current(self::EGS, ZatcaEnvironment::Sandbox),
            'pih' => app(ProductionPreviousInvoiceHashManager::class)->current(self::EGS, ZatcaEnvironment::Sandbox),
        ];
    }

    // ---------------------------------------------------------------------
    // The gate itself
    // ---------------------------------------------------------------------

    /**
     * The one default that must never drift: an existing deployment that has never heard of
     * ZATCA_ENABLED keeps invoicing exactly as it did, and a malformed setting fails towards
     * "still invoicing" rather than silently stopping a live system.
     *
     * The null and empty-string cases are the realistic accidents — a published config that
     * omits the key, or an `.env` line reading `ZATCA_ENABLED=` with nothing after it. Both
     * cast to false under a plain (bool), which is precisely the mistake this guards.
     */
    public function test_only_an_explicit_false_disables_zatca(): void
    {
        foreach ([null, '', 'yes', 'on', '1', 1, true, 'true', 'anything unparseable'] as $value) {
            config()->set('zatca.enabled', $value);

            $this->assertTrue(
                app(ZatcaGate::class)->enabled(),
                'Value '.var_export($value, true).' must leave ZATCA enabled.'
            );
        }

        foreach ([false, 'false', '0', 0, 'off', 'no'] as $value) {
            config()->set('zatca.enabled', $value);

            $this->assertTrue(
                app(ZatcaGate::class)->disabled(),
                'Value '.var_export($value, true).' must disable ZATCA.'
            );
        }
    }

    public function test_the_gate_reflects_config_changes_immediately(): void
    {
        $gate = app(ZatcaGate::class);

        $this->assertTrue($gate->enabled());

        $this->disableZatca();

        // Read per call, not captured at construction — otherwise the singleton would hand
        // every caller in the process a stale answer for the rest of the request.
        $this->assertTrue($gate->disabled());
    }

    // ---------------------------------------------------------------------
    // Generation
    // ---------------------------------------------------------------------

    public function test_disabled_generation_creates_no_invoice_and_consumes_no_icv_or_pih(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->forbiddenSdk());

        $before = $this->chainState();

        $this->disableZatca();

        $job = new GenerateZatcaInvoiceJob('fake-order-type', '7001', self::EGS);
        $job->handle(
            app(ProductionInvoiceOrchestrator::class),
            $this->fakeInvoiceSource(),
            new class implements \Zatca\Contracts\InvoiceSourceResolverContract
            {
                public function resolve(\Zatca\DTOs\InvoiceSourceReference $reference): mixed
                {
                    return (object) ['id' => $reference->sourceId];
                }
            },
            app(ZatcaGate::class)
        );

        $this->assertSame(0, ZatcaInvoice::query()->count(), 'No ZATCA invoice row may be written while disabled.');
        $this->assertSame($before, $this->chainState(), 'Neither ICV nor PIH may move while disabled.');
    }

    public function test_disabled_generation_job_does_not_queue_reporting(): void
    {
        Bus::fake([ReportZatcaInvoiceJob::class]);

        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->forbiddenSdk());
        $this->disableZatca();

        $job = new GenerateZatcaInvoiceJob('fake-order-type', '7002', self::EGS);
        $job->handle(
            app(ProductionInvoiceOrchestrator::class),
            $this->fakeInvoiceSource(),
            new class implements \Zatca\Contracts\InvoiceSourceResolverContract
            {
                public function resolve(\Zatca\DTOs\InvoiceSourceReference $reference): mixed
                {
                    return (object) ['id' => $reference->sourceId];
                }
            },
            app(ZatcaGate::class)
        );

        Bus::assertNotDispatched(ReportZatcaInvoiceJob::class);
    }

    // ---------------------------------------------------------------------
    // Reporting — the invoice must be left byte-identical
    // ---------------------------------------------------------------------

    public function test_disabled_reporting_leaves_status_and_attempt_count_untouched(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7101], self::EGS);

        $before = $invoice->fresh()->only(['status', 'attempt_count', 'last_error', 'reported_at', 'reporting_status']);
        $attemptsBefore = ZatcaSubmissionAttempt::query()->count();

        $this->disableZatca();

        (new ReportZatcaInvoiceJob($invoice->id))->handle(app(\Zatca\Services\Connectors\ConnectorFactory::class));
        (new RetryZatcaInvoiceJob($invoice->id))->handle(app(\Zatca\Services\Connectors\ConnectorFactory::class));

        $this->assertSame($before, $invoice->fresh()->only(['status', 'attempt_count', 'last_error', 'reported_at', 'reporting_status']));
        $this->assertSame($attemptsBefore, ZatcaSubmissionAttempt::query()->count(), 'No submission attempt may be recorded while disabled.');
    }

    // ---------------------------------------------------------------------
    // The claim-release regression — the failure mode a job-only guard creates
    // ---------------------------------------------------------------------

    public function test_disabled_regeneration_job_releases_the_claim_it_was_dispatched_with(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $rejected = $this->rejectedInvoice(7201);

        // Exactly what AdminZatcaInvoiceService does before dispatching: claim, then queue.
        // The switch is thrown between the two, which is the race this test exists for.
        $rejected->update(['regeneration_claimed_at' => now()]);

        $this->disableZatca();
        $this->app->instance(ZatcaSdkInterface::class, $this->forbiddenSdk());

        (new RegenerateZatcaInvoiceJob($rejected->id))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(ZatcaGate::class)
        );

        $this->assertNull(
            $rejected->fresh()->regeneration_claimed_at,
            'A disabled regeneration must release its claim, or the document is permanently un-regenerable and un-retryable.'
        );
        $this->assertNull($rejected->fresh()->superseded_by_id, 'No replacement may be created while disabled.');
        $this->assertSame(1, ZatcaInvoice::query()->count(), 'No second document may be written while disabled.');
    }

    public function test_disabled_completion_job_releases_the_claim_and_records_no_failure(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk([1]));

        $draft = $this->strandedDraft(7301);

        $draft->update(['generation_claimed_at' => now(), 'last_error' => null]);

        $this->disableZatca();
        $this->app->instance(ZatcaSdkInterface::class, $this->forbiddenSdk());

        (new CompleteZatcaInvoiceGenerationJob($draft->id))->handle(
            app(ProductionInvoiceOrchestrator::class),
            app(ZatcaGate::class)
        );

        $fresh = $draft->fresh();

        $this->assertNull($fresh->generation_claimed_at, 'A disabled completion must release its claim.');
        $this->assertNull($fresh->last_error, 'Nothing failed — the work was cancelled deliberately, so no error may be recorded.');
        $this->assertSame(ZatcaInvoiceStatus::Draft, $fresh->status, 'The draft must be left exactly as it was.');
        $this->assertNull($fresh->signed_xml_base64);
    }

    // ---------------------------------------------------------------------
    // The orchestrator backstop, for callers that bypass the jobs entirely
    // ---------------------------------------------------------------------

    public function test_disabled_orchestrator_refuses_every_public_entry_point(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $rejected = $this->rejectedInvoice(7401);
        $draft = $this->strandedDraft(7402);

        $this->disableZatca();
        $this->app->instance(ZatcaSdkInterface::class, $this->forbiddenSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $before = $this->chainState();

        foreach ([
            fn () => $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 7403], self::EGS),
            fn () => $orchestrator->regenerate($rejected),
            fn () => $orchestrator->completeGeneration($draft),
        ] as $index => $call) {
            try {
                $call();
                $this->fail("Orchestrator entry point #{$index} did not refuse while ZATCA was disabled.");
            } catch (ZatcaDisabledException) {
                // Expected.
            }
        }

        $this->assertSame($before, $this->chainState(), 'A refused call must not move the ICV or PIH.');
    }

    // ---------------------------------------------------------------------
    // Scheduled commands
    // ---------------------------------------------------------------------

    public function test_disabled_scheduled_commands_dispatch_nothing_and_succeed(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7501], self::EGS);
        $this->markRejected($invoice, 500);

        $attemptCountBefore = $invoice->fresh()->attempt_count;

        $this->disableZatca();
        Bus::fake([RetryZatcaInvoiceJob::class]);

        // SUCCESS, not FAILURE: these run on the scheduler every five minutes and hourly,
        // and a deliberately disabled system must not manufacture a failing cron job.
        $this->artisan('zatca:report-pending')->assertSuccessful();
        $this->artisan('zatca:requeue')->assertSuccessful();

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);

        // zatca:requeue resets attempt_count on the rows it takes; it must not have
        // touched this one.
        $this->assertSame($attemptCountBefore, $invoice->fresh()->attempt_count);
        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $invoice->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Enabled — the switch changes nothing when it is on
    // ---------------------------------------------------------------------

    public function test_enabled_generation_still_signs_reserves_icv_and_advances_pih(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        // Explicitly on rather than relying on the default, so this keeps proving the
        // enabled path even if the default is ever reconsidered.
        config()->set('zatca.enabled', true);

        $before = $this->chainState();

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7601], self::EGS);

        $after = $this->chainState();

        $this->assertNotSame(ZatcaInvoiceStatus::Draft, $invoice->status, 'The invoice must be signed when ZATCA is enabled.');
        $this->assertNotNull($invoice->signed_xml_base64);
        $this->assertNotNull($invoice->invoice_hash);
        $this->assertSame($before['icv'] + 1, $after['icv'], 'Signing must consume exactly one ICV.');
        $this->assertSame($invoice->invoice_hash, $after['pih'], 'The PIH must advance to this invoice hash.');
    }

    public function test_enabled_scheduled_command_still_dispatches_pending_invoices(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        config()->set('zatca.enabled', true);

        app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 7701], self::EGS);

        Bus::fake([RetryZatcaInvoiceJob::class]);

        $this->artisan('zatca:report-pending')->assertSuccessful();

        Bus::assertDispatched(RetryZatcaInvoiceJob::class);
    }
}
