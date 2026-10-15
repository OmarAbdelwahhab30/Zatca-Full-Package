<?php

namespace Zatca\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\InvoiceSourceReference;
use Zatca\DTOs\Payloads\ClearancePayload;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\Feature\Characterization\CreatesInvoiceRows;
use Zatca\Tests\TestCase;

/**
 * zatca:recover moves one sale's ZATCA document exactly one safe step forward through the
 * normal pipeline, and refuses everything that would need a human decision: resending a
 * rejected document, resending an in-doubt clearance, regenerating, or guessing an EGS.
 */
class RecoverZatcaInvoiceCommandTest extends TestCase
{
    use BuildsProductionInvoices;
    use CreatesInvoiceRows;

    /** Console output of the last recover() call. */
    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Real console output, so each test can read what an operator would see.
        $this->withoutMockingConsoleOutput();
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
        $this->app->bind(InvoiceSourceContract::class, fn () => $this->fakeInvoiceSource());
        $this->app->instance(InvoiceSourceResolverContract::class, new class implements InvoiceSourceResolverContract
        {
            public function resolve(InvoiceSourceReference $reference): mixed
            {
                return $reference->sourceType === 'order' && $reference->sourceId !== '404'
                    ? (object) ['id' => (int) $reference->sourceId]
                    : null;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidOptions
     */
    #[DataProvider('invalidOptions')]
    public function test_it_refuses_ambiguous_or_incomplete_input(array $options): void
    {
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover($options));
        $this->assertSame(0, ZatcaInvoice::query()->count());
    }

    public static function invalidOptions(): array
    {
        return [
            'nothing' => [[]],
            'type without id' => [['--source-type' => 'order']],
            'id without type' => [['--source-id' => '1']],
            'source and document id' => [['--source-type' => 'order', '--source-id' => '1', '--id' => '1']],
        ];
    }

    public function test_an_unknown_source_changes_nothing(): void
    {
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '404']));
        $this->assertStringContainsString('was not found', $this->output);
        $this->assertSame(0, ZatcaInvoice::query()->count());
    }

    public function test_an_unknown_document_id_changes_nothing(): void
    {
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--id' => '999']));
        $this->assertStringContainsString('not found', $this->output);
    }

    public function test_recovering_by_source_needs_a_bound_invoice_source(): void
    {
        $this->app->offsetUnset(InvoiceSourceContract::class);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '1']));
        $this->assertStringContainsString('No InvoiceSourceContract is bound', $this->output);
    }

    // -------------------------------------------------------------------------
    // Happy paths, one per pipeline position
    // -------------------------------------------------------------------------

    public function test_a_sale_with_no_document_is_generated_signed_and_reported(): void
    {
        $this->connectorReporting($this->accepted());

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '1']));

        $invoice = ZatcaInvoice::query()->sole();
        $this->assertSame('order:1', $invoice->source_reference);
        $this->assertSame('default', $invoice->egs_identifier);
        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->status);
        $this->assertStringContainsString('ZATCA accepted the document', $this->output);
    }

    public function test_a_stranded_draft_is_signed_with_its_own_icv_and_uuid_then_reported(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));
        $draft = $this->strandedDraft(sourceId: 7);
        $this->connectorReporting($this->accepted());

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '7']));

        $invoice = ZatcaInvoice::query()->sole();
        $this->assertSame($draft->id, $invoice->id);
        $this->assertSame($draft->icv, $invoice->icv);
        $this->assertSame($draft->uuid, $invoice->uuid);
        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->status);
        $this->assertNull($invoice->generation_claimed_at, 'The generation claim must be released.');
    }

    public function test_a_failed_draft_completion_records_the_reason_and_releases_the_claim(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1, 2]));
        $draft = $this->strandedDraft(sourceId: 8);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '8']));

        $invoice = $draft->fresh();
        $this->assertTrue($invoice->isUnsignedDraft());
        $this->assertSame($draft->icv, $invoice->icv);
        $this->assertNull($invoice->generation_claimed_at);
        $this->assertStringContainsString('Draft completion failed', (string) $invoice->last_error);
        $this->assertStringContainsString('Recovery FAILED at: draft_completion', $this->output);
        $this->assertSame(1, ZatcaInvoice::query()->count(), 'A failure must never reserve a second document.');
    }

    public function test_a_signed_document_waiting_to_be_reported_is_sent_without_re_signing(): void
    {
        $invoice = app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 3], 'default');
        $hash = $invoice->invoice_hash;
        $this->app->instance(ZatcaSdkInterface::class, $sdk = Mockery::mock(ZatcaSdkInterface::class));
        $sdk->shouldNotReceive('signInvoice');
        $this->connectorReporting($this->accepted());

        $this->assertSame(0, $this->recover(['--id' => (string) $invoice->id]));

        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->fresh()->status);
        $this->assertSame($hash, $invoice->fresh()->invoice_hash);
    }

    public function test_an_accepted_document_is_never_sent_again(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::Reported, ['source_reference' => 'order:4']);
        $this->connectorExpectingNoCalls();

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '4']));
        $this->assertStringContainsString('already reported', $this->output);
        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->fresh()->status);
    }

    public function test_a_transient_reporting_failure_is_reported_as_not_done(): void
    {
        $invoice = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['source_reference' => 'order:5']);
        $this->connectorReporting(new ZatcaApiResult(false, ZatcaResponseStatus::Error, 503, null, [], errors: ['down']));

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '5']));

        $this->assertSame(ZatcaInvoiceStatus::Retrying, $invoice->fresh()->status);
        $this->assertStringContainsString('report-pending sweep will retry', $this->output);
    }

    // -------------------------------------------------------------------------
    // Rejections: never resent, never regenerated
    // -------------------------------------------------------------------------

    public function test_a_document_zatca_rejected_is_neither_resent_nor_regenerated(): void
    {
        $invoice = $this->rejectedInvoice(sourceId: 10, httpStatus: 400);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '10']));

        $output = $this->output;
        $this->assertStringContainsString('ZATCA rejected document', $output);
        $this->assertStringContainsString('never regenerates', $output);
        $this->assertSame(1, ZatcaInvoice::query()->count(), 'No replacement document, no new ICV.');
        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $invoice->fresh()->status);
    }

    public function test_a_document_stuck_in_manual_review_after_an_outage_is_resent(): void
    {
        $invoice = $this->rejectedInvoice(sourceId: 11, httpStatus: 503);
        $this->connectorReporting($this->accepted());

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '11']));

        $this->assertSame(ZatcaInvoiceStatus::Reported, $invoice->fresh()->status);
        $this->assertSame(1, ZatcaInvoice::query()->count());
    }

    // -------------------------------------------------------------------------
    // B2B / Clearance
    // -------------------------------------------------------------------------

    public function test_a_standard_document_is_cleared_never_reported(): void
    {
        $invoice = $this->standardRow(ZatcaInvoiceStatus::PendingClearance, 'order:20');
        $this->connectorClearing($this->cleared());

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '20']));

        $this->assertSame(ZatcaInvoiceStatus::Cleared, $invoice->fresh()->status);
        $this->assertStringContainsString('Sending for clearance', $this->output);
    }

    public function test_a_clearance_rejected_document_is_never_resent(): void
    {
        $invoice = $this->standardRow(ZatcaInvoiceStatus::ClearanceRejected, 'order:21');
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '21']));
        $this->assertSame(ZatcaInvoiceStatus::ClearanceRejected, $invoice->fresh()->status);
        $this->assertSame(1, ZatcaInvoice::query()->count());
    }

    public function test_an_in_doubt_clearance_needs_explicit_permission_to_resend(): void
    {
        $invoice = $this->standardRow(ZatcaInvoiceStatus::ClearanceInDoubt, 'order:22');
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '22']));
        $this->assertStringContainsString('--resend-in-doubt', $this->output);
        $this->assertSame(ZatcaInvoiceStatus::ClearanceInDoubt, $invoice->fresh()->status);
    }

    public function test_an_in_doubt_clearance_is_resent_with_explicit_permission(): void
    {
        $invoice = $this->standardRow(ZatcaInvoiceStatus::ClearanceInDoubt, 'order:23');
        $this->connectorClearing($this->cleared());

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '23', '--resend-in-doubt' => true]));
        $this->assertSame(ZatcaInvoiceStatus::Cleared, $invoice->fresh()->status);
    }

    public function test_with_a_b2b_egs_configured_a_new_document_needs_an_explicit_egs(): void
    {
        $this->configureB2bEgs();
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '30']));
        $this->assertStringContainsString('--egs=', $this->output);
        $this->assertSame(0, ZatcaInvoice::query()->count(), 'No ICV may be spent on a guessed EGS.');
    }

    public function test_a_new_document_is_never_created_on_the_b2b_egs_while_b2b_is_off(): void
    {
        $this->configureB2bEgs();
        config()->set('zatca.b2b.enabled', false);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '31', '--egs' => 'b2b']));
        $this->assertSame(0, ZatcaInvoice::query()->count());
    }

    /**
     * "A B2B EGS is configured" and "B2B invoicing is switched on" are different
     * questions: the EGS being configured is what creates the ambiguity (this sale
     * could belong to either EGS), regardless of whether the switch is currently on.
     * The ambiguity refusal must still fire with the switch off and no --egs given —
     * guessing the primary (B2C) EGS here would be exactly as wrong as with it on.
     */
    public function test_with_a_b2b_egs_configured_but_switched_off_a_new_document_still_needs_an_explicit_egs(): void
    {
        $this->configureB2bEgs();
        config()->set('zatca.b2b.enabled', false);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '33']));
        $this->assertStringContainsString('--egs=', $this->output);
        $this->assertSame(0, ZatcaInvoice::query()->count(), 'No ICV may be spent on a guessed EGS.');
    }

    public function test_an_existing_document_always_stays_on_its_own_egs(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['source_reference' => 'order:32']);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '32', '--egs' => 'b2b']));
        $this->assertStringContainsString('stays on its own EGS', $this->output);
    }

    // -------------------------------------------------------------------------
    // Chains, duplicates, locks, switches
    // -------------------------------------------------------------------------

    public function test_a_regenerated_sale_recovers_the_current_document_not_the_rejected_one(): void
    {
        $original = $this->invoiceRow(ZatcaInvoiceStatus::ManualReview, ['source_reference' => 'order:40']);
        $replacement = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, [
            'source_reference' => 'order:40#r1',
            'regenerated_from_id' => $original->id,
        ]);
        $original->update(['superseded_by_id' => $replacement->id]);
        $this->connectorReporting($this->accepted());

        // Asking by the original's id lands on the same sale and the same current document.
        $this->assertSame(0, $this->recover(['--id' => (string) $original->id]));

        $this->assertSame(ZatcaInvoiceStatus::Reported, $replacement->fresh()->status);
        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $original->fresh()->status);
    }

    public function test_more_than_one_root_document_stops_without_guessing(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['source_reference' => 'order:50']);
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, [
            'source_reference' => 'order:50',
            'environment' => ZatcaEnvironment::Production->value,
        ]);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '50']));
        $this->assertStringContainsString('More than one ZATCA document', $this->output);
    }

    public function test_an_expired_certificate_stops_before_anything_is_sent(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['source_reference' => 'order:60']);
        ZatcaEgsCredential::query()
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->update(['certificate_state' => ZatcaCertificateState::Expired->value]);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '60']));
        $this->assertStringContainsString('has expired', $this->output);
    }

    public function test_nothing_happens_while_zatca_is_switched_off(): void
    {
        config()->set('zatca.enabled', false);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '70']));
        $this->assertStringContainsString('disabled', $this->output);

        // A dry run has no safe plan to offer either, so it does not report success.
        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '70', '--dry-run' => true]));
        $this->assertSame(0, ZatcaInvoice::query()->count());
    }

    public function test_a_second_recovery_of_the_same_sale_waits_its_turn(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting, ['source_reference' => 'order:80']);
        $held = Cache::lock('zatca-recover:order:80', 60);
        $held->get();
        $this->connectorExpectingNoCalls();

        try {
            $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '80']));
            $this->assertStringContainsString('Another recovery is already running', $this->output);
        } finally {
            $held->release();
        }
    }

    // -------------------------------------------------------------------------
    // Dry run
    // -------------------------------------------------------------------------

    public function test_a_dry_run_writes_signs_and_sends_nothing(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $sdk = Mockery::mock(ZatcaSdkInterface::class));
        $sdk->shouldNotReceive('signInvoice');
        $this->connectorExpectingNoCalls();

        $this->assertSame(0, $this->recover(['--source-type' => 'order', '--source-id' => '90', '--dry-run' => true]));

        $output = $this->output;
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('reserve a new ICV', $output);
        $this->assertSame(0, ZatcaInvoice::query()->count());
    }

    public function test_a_dry_run_of_a_blocked_recovery_says_why_and_fails(): void
    {
        $this->rejectedInvoice(sourceId: 91, httpStatus: 400);
        $this->connectorExpectingNoCalls();

        $this->assertSame(1, $this->recover(['--source-type' => 'order', '--source-id' => '91', '--dry-run' => true]));
        $this->assertStringContainsString('Recovery would stop', $this->output);
        $this->assertSame(1, ZatcaSubmissionAttempt::query()->count(), 'No new attempt may be recorded.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function recover(array $options): int
    {
        // An explicit buffer rather than Artisan::output(), which reads back empty under
        // some Testbench versions.
        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('zatca:recover', $options, $buffer);
        $this->output = $buffer->fetch();

        return $exitCode;
    }

    private function standardRow(ZatcaInvoiceStatus $status, string $sourceReference): ZatcaInvoice
    {
        return $this->invoiceRow($status, [
            'source_reference' => $sourceReference,
            'submission_model' => 'clearance',
            'document_type' => 'standard_invoice',
        ]);
    }

    private function configureB2bEgs(): void
    {
        config()->set('zatca.egs.b2b', array_merge(config('zatca.egs.default'), ['common_name' => 'TST-EGS-B2B']));
        config()->set('zatca.b2b.egs_identifier', 'b2b');
        config()->set('zatca.b2b.enabled', true);
    }

    private function accepted(): ZatcaApiResult
    {
        return new ZatcaApiResult(true, ZatcaResponseStatus::Pass, 200, null, ['reportingStatus' => 'REPORTED']);
    }

    private function cleared(): ZatcaApiResult
    {
        return new ZatcaApiResult(
            successful: true,
            status: ZatcaResponseStatus::Pass,
            httpStatus: 200,
            requestId: null,
            sanitizedResponse: ['clearanceStatus' => 'CLEARED'],
            payload: new ClearancePayload(clearanceStatus: 'CLEARED', clearedInvoiceBase64: base64_encode('cleared-xml')),
        );
    }

    private function connectorReporting(ZatcaApiResult $result): void
    {
        $connector = Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldReceive('reportInvoice')->once()->andReturn($result);
        $connector->shouldNotReceive('clearInvoice');
        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));
    }

    private function connectorClearing(ZatcaApiResult $result): void
    {
        $connector = Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldReceive('clearInvoice')->once()->andReturn($result);
        $connector->shouldNotReceive('reportInvoice');
        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));
    }

    private function connectorExpectingNoCalls(): void
    {
        $connector = Mockery::mock(ZatcaConnectorInterface::class);
        $connector->shouldNotReceive('reportInvoice');
        $connector->shouldNotReceive('clearInvoice');
        $this->mock(ConnectorFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($connector));
    }
}
