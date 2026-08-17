<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Regeneration: building a replacement for a document ZATCA read and refused.
 *
 * The two properties that matter most are opposites, and both are asserted here. The
 * original must survive completely untouched — it is a fiscal record of what was sent, and
 * a rejected document is still a record. The replacement must be genuinely new where ZATCA
 * requires newness (UUID, ICV, PIH, hash, signature, QR) and genuinely identical where the
 * sale requires it (every business value).
 */
class ProductionInvoiceOrchestratorRegenerationTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();
    }

    /**
     * Every invoice the normal flow produces must carry a usable snapshot, because that is
     * the only thing that makes it regenerable later.
     *
     * Asserted against reserveDraft() rather than against a specific caller, since that is
     * where the write happens: both production entry points — the payment listener and the
     * admin/bank-transfer assignment in TransactionService — dispatch the same
     * GenerateZatcaInvoiceJob, which resolves one InvoiceSourceContract and calls generate().
     * Whatever mapper the router picks, the snapshot is taken from the ZatcaInvoiceData it
     * returns, so covering the orchestrator covers both lanes.
     */
    public function test_normal_generation_records_a_usable_business_snapshot(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 9001], self::EGS);

        $this->assertNotNull($invoice->invoice_data_snapshot);
        $this->assertTrue(
            \Zatca\Support\InvoiceDataSnapshot::isUsable($invoice->invoice_data_snapshot),
            'A snapshot that cannot be hydrated is the same as no snapshot at all.'
        );

        // The snapshot must agree with the columns beside it, or a regeneration would produce
        // a document whose amounts differ from the invoice it replaces.
        $restored = \Zatca\Support\InvoiceDataSnapshot::fromArray($invoice->invoice_data_snapshot);

        $this->assertSame((float) $invoice->total_excl_vat, $restored->totalExclVat);
        $this->assertSame((float) $invoice->total_vat, $restored->totalVat);
        $this->assertSame((float) $invoice->total_incl_vat, $restored->totalInclVat);
        $this->assertSame($invoice->currency, $restored->currency);
        $this->assertSame($invoice->source_reference, $restored->sourceReference);
        $this->assertNotEmpty($restored->lines);
    }

    /**
     * The snapshot is written when the slot is reserved, not when signing succeeds, so an
     * invoice whose signing failed is still regenerable once the cause is fixed.
     */
    public function test_the_snapshot_is_recorded_even_when_signing_fails(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));

        try {
            app(ProductionInvoiceOrchestrator::class)
                ->generate($this->fakeInvoiceSource(), (object) ['id' => 9002], self::EGS);
        } catch (\Zatca\Exceptions\ZatcaSdkProcessException) {
            // expected
        }

        $draft = ZatcaInvoice::where('source_reference', 'order:9002')->firstOrFail();

        $this->assertSame(ZatcaInvoiceStatus::Draft, $draft->status);
        $this->assertTrue(\Zatca\Support\InvoiceDataSnapshot::isUsable($draft->invoice_data_snapshot));
    }

    public function test_it_creates_a_linked_replacement_and_leaves_the_original_untouched(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9101);

        $before = $original->only([
            'uuid', 'icv', 'previous_invoice_hash', 'unsigned_xml',
            'signed_xml_base64', 'invoice_hash', 'qr_code',
        ]);

        $replacement = $orchestrator->regenerate($original);

        // A separate document, not a rewrite.
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame($original->id, (int) $replacement->regenerated_from_id);
        $this->assertSame($replacement->id, (int) $original->fresh()->superseded_by_id);

        // Everything ZATCA requires to be new, is new.
        $this->assertNotSame($original->uuid, $replacement->uuid);
        $this->assertSame($original->icv + 1, $replacement->icv);
        $this->assertNotSame($original->invoice_hash, $replacement->invoice_hash);
        $this->assertNotSame($original->signed_xml_base64, $replacement->signed_xml_base64);
        $this->assertNotSame($original->qr_code, $replacement->qr_code);
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $replacement->status);

        // The rejected document is a record of what was sent. Nothing about it may move.
        $after = $original->fresh()->only(array_keys($before));
        $this->assertSame($before, $after, 'The original document must be byte-identical after a regeneration.');
    }

    public function test_the_originals_attempt_history_survives_regeneration(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9102);
        $attemptsBefore = $original->submissionAttempts()->get()->toArray();

        app(ProductionInvoiceOrchestrator::class)->regenerate($original);

        $this->assertSame(
            $attemptsBefore,
            $original->fresh()->submissionAttempts()->get()->toArray(),
            'Submission attempts are append-only and must not be touched by a regeneration.'
        );
    }

    public function test_business_values_come_from_the_snapshot_and_the_host_record_is_never_read(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9103);

        // regenerate() takes no invoice source at all, so there is no route back to the
        // host record even in principle. This asserts the consequence: every business value
        // on the replacement equals the original's.
        $replacement = app(ProductionInvoiceOrchestrator::class)->regenerate($original);

        $this->assertSame($original->total_excl_vat, $replacement->total_excl_vat);
        $this->assertSame($original->total_vat, $replacement->total_vat);
        $this->assertSame($original->total_incl_vat, $replacement->total_incl_vat);
        $this->assertSame($original->currency, $replacement->currency);
        $this->assertSame($original->invoice_data_snapshot, $replacement->invoice_data_snapshot);

        // And the buyer/date/line values reach the new XML, not just the columns.
        $this->assertStringContainsString('Original Buyer', $replacement->unsigned_xml);
        $this->assertStringContainsString('2026-08-17', $replacement->unsigned_xml);
    }

    public function test_the_replacement_takes_a_suffixed_source_reference(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9104);
        $replacement = app(ProductionInvoiceOrchestrator::class)->regenerate($original);

        // The original still owns the plain reference, so the purchase flow's idempotency
        // key — and every lookup built on it — keeps resolving to the same place.
        $this->assertSame('order:9104', $original->fresh()->source_reference);
        $this->assertSame('order:9104#r1', $replacement->source_reference);
    }

    public function test_a_replacement_can_itself_be_regenerated_and_the_chain_stays_navigable(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $original = $this->rejectedInvoice(9105);
        $first = $orchestrator->regenerate($original);
        $second = $orchestrator->regenerate($this->markRejected($first));

        $this->assertSame('order:9105#r2', $second->source_reference);
        $this->assertSame($first->id, (int) $second->regenerated_from_id);
        $this->assertSame($second->id, (int) $first->fresh()->superseded_by_id);

        // Both directions resolve: the newest document is authoritative, and depth counts
        // back to the document the purchase flow created.
        $this->assertSame($second->id, $original->fresh()->authoritative()->id);
        $this->assertSame(0, $original->fresh()->regenerationDepth());
        $this->assertSame(1, $first->fresh()->regenerationDepth());
        $this->assertSame(2, $second->fresh()->regenerationDepth());
    }

    public function test_it_refuses_a_document_with_no_business_snapshot(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9106);

        // Exactly the state of every invoice generated before snapshots were recorded.
        $original->update(['invoice_data_snapshot' => null]);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/no business snapshot/i');

        app(ProductionInvoiceOrchestrator::class)->regenerate($original->fresh());
    }

    public function test_it_refuses_a_document_zatca_already_accepted(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9107);
        $original->update(['status' => ZatcaInvoiceStatus::Reported]);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/already been accepted/i');

        app(ProductionInvoiceOrchestrator::class)->regenerate($original->fresh());
    }

    public function test_it_refuses_a_document_that_has_already_been_superseded(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9108);

        $orchestrator->regenerate($original);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/already been superseded/i');

        // Without this guard the same rejected document would spawn two replacements and
        // permanently consume two ICV slots for one sale.
        $orchestrator->regenerate($original->fresh());
    }

    public function test_it_stops_once_the_chain_depth_limit_is_reached(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $document = $this->rejectedInvoice(9109);

        for ($i = 0; $i < ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH; $i++) {
            $document = $this->markRejected($orchestrator->regenerate($document));
        }

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/regenerations deep/i');

        $orchestrator->regenerate($document);
    }

    public function test_regeneration_extends_the_hash_chain_rather_than_rewinding_it(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $original = $this->rejectedInvoice(9110);
        $replacement = app(ProductionInvoiceOrchestrator::class)->regenerate($original);

        // The chain follows ICV order regardless of acceptance, so the replacement links to
        // the rejected document's own hash. The chain is never rewound or skipped.
        $this->assertSame($original->invoice_hash, $replacement->previous_invoice_hash);
    }
}
