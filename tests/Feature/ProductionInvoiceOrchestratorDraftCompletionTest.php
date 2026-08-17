<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Draft completion: finishing a document whose signing never finished.
 *
 * The property that makes this action safe to expose to an administrator is a negative one
 * — it must create nothing. No second invoice, no second ICV, no re-read of the host
 * record. Most of what follows asserts that absence, because the presence (a hash, a
 * signature, a QR code) is the easy half.
 *
 * The other half is chain safety. A stranded Draft is by definition old, so by the time
 * anyone completes it the chain has very likely moved on. Signing it with the hash it
 * reserved would fork the chain, which is the failure this module's signing order was
 * restructured to prevent, and this is the path most likely to hit it.
 */
class ProductionInvoiceOrchestratorDraftCompletionTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();
    }

    public function test_it_signs_the_existing_draft_without_creating_another_document(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));

        $draft = $this->strandedDraft(9201);

        $this->assertSame(ZatcaInvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->signed_xml_base64);

        $completed = app(ProductionInvoiceOrchestrator::class)->completeGeneration($draft);

        // The same row, not a new one. This is the whole guarantee: an administrator
        // finishing a document must never end up with two documents for one sale.
        $this->assertSame($draft->id, $completed->id);
        $this->assertSame(1, ZatcaInvoice::query()->count());

        // The scarce values are carried, not re-issued.
        $this->assertSame($draft->uuid, $completed->uuid);
        $this->assertSame($draft->icv, $completed->icv);
        $this->assertSame($draft->source_reference, $completed->source_reference);

        // And the missing fields are now present.
        $this->assertNotEmpty($completed->invoice_hash);
        $this->assertNotEmpty($completed->signed_xml_base64);
        $this->assertNotEmpty($completed->qr_code);
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $completed->status);
    }

    /**
     * The ICV was consumed when the Draft was written. Consuming another one here would
     * leave a permanent gap in the sequence, which ZATCA treats as a broken chain.
     */
    public function test_it_does_not_consume_another_icv(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));

        $draft = $this->strandedDraft(9202);
        $before = $this->currentIcv();

        app(ProductionInvoiceOrchestrator::class)->completeGeneration($draft);

        $this->assertSame($before, $this->currentIcv(), 'Completing a draft must not advance the ICV counter.');
    }

    public function test_it_preserves_every_business_value(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));

        $draft = $this->strandedDraft(9203);

        $completed = app(ProductionInvoiceOrchestrator::class)->completeGeneration($draft);

        $this->assertSame($draft->total_excl_vat, $completed->total_excl_vat);
        $this->assertSame($draft->total_vat, $completed->total_vat);
        $this->assertSame($draft->total_incl_vat, $completed->total_incl_vat);
        $this->assertSame($draft->currency, $completed->currency);
        $this->assertSame($draft->invoice_data_snapshot, $completed->invoice_data_snapshot);
    }

    /**
     * The reason a Draft's own unsigned XML cannot simply be signed as-is.
     *
     * A stranded Draft is old by definition. If anything was signed while it sat there, its
     * reserved previous-invoice-hash is stale, and signing it unchanged would give two
     * documents the same predecessor and fork the chain ZATCA validates.
     */
    public function test_it_rechains_a_draft_whose_chain_head_moved_while_it_waited(): void
    {
        $sdk = $this->scriptedSdk(failOnAttempts: [1]);
        $this->app->instance(ZatcaSdkInterface::class, $sdk);

        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        // A is stranded holding the genesis hash.
        $stranded = $this->strandedDraft(9204);
        $reservedPih = $stranded->previous_invoice_hash;

        // B is generated and signed in the meantime, moving the chain head.
        $later = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 9205], self::EGS);

        $this->assertSame($reservedPih, $later->previous_invoice_hash, 'B should link to the head A also saw.');

        $completed = $orchestrator->completeGeneration($stranded->fresh());

        // A now links to B, not to the head it reserved.
        $this->assertSame($later->invoice_hash, $completed->previous_invoice_hash);
        $this->assertNotSame($reservedPih, $completed->previous_invoice_hash);

        // And the re-chained hash is in the bytes that were actually signed, not just in the
        // row — a document whose stored PIH and embedded PIH disagree is worse than either.
        // The hash is embedded verbatim in the PIH node (it is already base64 in production;
        // the fixture's hashes are plain strings), so the raw value is what to look for.
        $this->assertStringContainsString($later->invoice_hash, end($sdk->signedXmlSeen));

        // No two documents share a predecessor.
        $this->assertCount(
            2,
            ZatcaInvoice::query()->pluck('previous_invoice_hash')->unique(),
            'Every invoice must carry a distinct previous_invoice_hash.'
        );
    }

    public function test_it_refuses_a_document_that_has_already_been_signed(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $signed = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 9206], self::EGS);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/not an unsigned draft/i');

        app(ProductionInvoiceOrchestrator::class)->completeGeneration($signed);
    }

    /**
     * Signing an abandoned replacement would re-emit the exact content a regeneration had
     * already decided to replace, and would move the chain head to accommodate it.
     */
    public function test_it_refuses_an_abandoned_replacement_draft(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));

        $draft = $this->strandedDraft(9207);
        $draft->update(['abandoned_at' => now()]);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/abandoned/i');

        app(ProductionInvoiceOrchestrator::class)->completeGeneration($draft->fresh());
    }

    /**
     * A Draft can also be an unsigned replacement from a regeneration that failed while
     * signing. Completing it has to close the same loop the regeneration would have closed,
     * or the rejected original stays unsuperseded and both documents read as current.
     */
    public function test_completing_a_replacement_draft_supersedes_its_original(): void
    {
        // Signs the original (attempt 1), then fails the replacement's signing (attempt 2).
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9208);

        try {
            $orchestrator->regenerate($original);
        } catch (\Zatca\Exceptions\ZatcaSdkProcessException) {
            // The replacement Draft survives, exactly as designed.
        }

        $replacement = ZatcaInvoice::query()->where('regenerated_from_id', $original->id)->firstOrFail();

        $this->assertSame(ZatcaInvoiceStatus::Draft, $replacement->status);
        $this->assertNull($original->fresh()->superseded_by_id, 'Nothing is superseded until a replacement is signed.');

        $completed = $orchestrator->completeGeneration($replacement);

        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $completed->status);
        $this->assertSame($completed->id, (int) $original->fresh()->superseded_by_id);
        $this->assertSame($completed->id, $original->fresh()->authoritative()->id);
    }

    /**
     * Two callers racing — an administrator clicking twice, or a click landing while
     * GenerateZatcaInvoiceJob's own retry is running — must produce one signature. sign()
     * takes the row lock and refuses anything that is no longer a Draft, so the loser is a
     * no-op rather than a second hash for one ICV.
     */
    public function test_a_second_completion_of_the_same_draft_signs_nothing(): void
    {
        $sdk = $this->scriptedSdk(failOnAttempts: [1]);
        $this->app->instance(ZatcaSdkInterface::class, $sdk);

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $draft = $this->strandedDraft(9209);

        $first = $orchestrator->completeGeneration($draft);
        $signAttemptsAfterFirst = $sdk->signAttempts;

        // Deliberately the stale instance the first call started from, which is what a second
        // request holding a stale dashboard row would have.
        $second = $orchestrator->completeGeneration($draft);

        $this->assertSame($signAttemptsAfterFirst, $sdk->signAttempts, 'The second call must not sign again.');
        $this->assertSame($first->invoice_hash, $second->invoice_hash);
        $this->assertSame(1, ZatcaInvoice::query()->count());
    }

    private function currentIcv(): int
    {
        return app(ProductionInvoiceCounterManager::class)->current(self::EGS, ZatcaEnvironment::Sandbox);
    }
}
