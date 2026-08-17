<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\SDK\SignedInvoiceXmlReader;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Guards the hash chain against being forked by a delayed signing.
 *
 * The failure this exists for: reserving an ICV slot captures the chain head as it stands,
 * but signing can happen much later — a draft survives a signing failure and is retried
 * with a backoff reaching ten minutes. If another invoice is signed in that window, the
 * captured head is stale. Two invoices then carry the same previous_invoice_hash and the
 * next one chains to the wrong predecessor.
 *
 * ZATCA validates that chain, so the consequence is not one rejected invoice but every
 * invoice after it. ProductionInvoiceOrchestratorIcvSafetyTest covers the ICV half of this
 * problem; this covers the PIH half, which its happy-path sequence never exercises.
 */
class ProductionInvoiceOrchestratorPihOrderingTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    /**
     * The original bug, reproduced end to end.
     *
     * Invoice A fails to sign, invoice B is created and signed while A is still a draft,
     * then A is retried. Before the fix, A signed with the genesis hash it captured at
     * reservation — the same hash B carried — and then rolled the chain head backwards to
     * its own hash, so the next invoice chained to A instead of B.
     */
    public function test_a_draft_signed_after_another_invoice_is_rechained_to_the_current_head(): void
    {
        $this->seedFakeProductionCredentials();

        $sdk = $this->scriptedSdk(failOnAttempts: [1]);
        $this->app->instance(ZatcaSdkInterface::class, $sdk);

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $source = $this->fakeInvoiceSource();

        $genesis = $this->chainHead();

        // A reserves ICV 1 against the genesis hash, then fails to sign.
        try {
            $orchestrator->generate($source, (object) ['id' => 5001], self::EGS);
            $this->fail('Expected the first signing attempt to throw.');
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $draftA = ZatcaInvoice::where('source_reference', 'order:5001')->firstOrFail();
        $this->assertSame(ZatcaInvoiceStatus::Draft, $draftA->status);
        $this->assertSame($genesis, $draftA->previous_invoice_hash);

        // B reserves ICV 2 and signs successfully, moving the chain head.
        $invoiceB = $orchestrator->generate($source, (object) ['id' => 5002], self::EGS);
        $this->assertSame('HASH-2', $invoiceB->invoice_hash);
        $this->assertSame('HASH-2', $this->chainHead());

        // A is retried, exactly as GenerateZatcaInvoiceJob would retry it.
        $invoiceA = $orchestrator->generate($source, (object) ['id' => 5001], self::EGS);

        // A must now chain to B — the invoice actually signed before it — not to the
        // genesis hash it captured when it reserved its slot.
        $this->assertSame('HASH-2', $invoiceA->previous_invoice_hash);
        $this->assertNotSame(
            $invoiceA->previous_invoice_hash,
            $invoiceB->previous_invoice_hash,
            'Two invoices sharing a previous_invoice_hash is a forked chain.'
        );

        // The re-chaining must be in the bytes that were signed, not only in the row: the
        // PIH is part of the signed document, so a row-only fix would be a lie.
        $signedXml = end($sdk->signedXmlSeen);
        $this->assertSame('HASH-2', app(SignedInvoiceXmlReader::class)->extractPih($signedXml));

        // A signed last, so it is now the head.
        $this->assertSame($invoiceA->invoice_hash, $this->chainHead());
    }

    /** The invariant, stated directly: one hash cannot be the predecessor of two invoices. */
    public function test_no_two_invoices_ever_share_a_previous_invoice_hash(): void
    {
        $this->seedFakeProductionCredentials();

        // Fail the first two signing attempts, so two drafts sit unsigned while later
        // invoices are created and signed around them.
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1, 2]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $source = $this->fakeInvoiceSource();

        foreach ([6001, 6002] as $id) {
            try {
                $orchestrator->generate($source, (object) ['id' => $id], self::EGS);
            } catch (ZatcaSdkProcessException) {
                // expected
            }
        }

        // These succeed, then the two stalled drafts are retried.
        $orchestrator->generate($source, (object) ['id' => 6003], self::EGS);
        $orchestrator->generate($source, (object) ['id' => 6001], self::EGS);
        $orchestrator->generate($source, (object) ['id' => 6002], self::EGS);

        $signed = ZatcaInvoice::whereNotNull('invoice_hash')
            ->where('invoice_hash', '!=', '')
            ->get(['previous_invoice_hash', 'invoice_hash']);

        $this->assertCount(3, $signed);

        $previousHashes = $signed->pluck('previous_invoice_hash');
        $this->assertCount(
            3,
            $previousHashes->unique(),
            'Every signed invoice must chain to a distinct predecessor.'
        );
    }

    /** Each invoice chains to whichever invoice was signed immediately before it. */
    public function test_each_invoice_chains_to_the_hash_of_the_one_signed_before_it(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $source = $this->fakeInvoiceSource();

        $genesis = $this->chainHead();

        $first = $orchestrator->generate($source, (object) ['id' => 7201], self::EGS);
        $second = $orchestrator->generate($source, (object) ['id' => 7202], self::EGS);
        $third = $orchestrator->generate($source, (object) ['id' => 7203], self::EGS);

        $this->assertSame($genesis, $first->previous_invoice_hash);
        $this->assertSame($first->invoice_hash, $second->previous_invoice_hash);
        $this->assertSame($second->invoice_hash, $third->previous_invoice_hash);
        $this->assertSame($third->invoice_hash, $this->chainHead());
    }

    /** An unchanged chain head must leave the document's bytes completely alone. */
    public function test_a_draft_signed_with_an_unchanged_head_is_not_rewritten(): void
    {
        $this->seedFakeProductionCredentials();

        $sdk = $this->scriptedSdk(failOnAttempts: [1]);
        $this->app->instance(ZatcaSdkInterface::class, $sdk);

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $source = $this->fakeInvoiceSource();

        try {
            $orchestrator->generate($source, (object) ['id' => 8001], self::EGS);
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $draft = ZatcaInvoice::where('source_reference', 'order:8001')->firstOrFail();
        $xmlBefore = $draft->unsigned_xml;
        $pihBefore = $draft->previous_invoice_hash;

        // Nothing else signed in between, so there is nothing to re-chain.
        $signed = $orchestrator->generate($source, (object) ['id' => 8001], self::EGS);

        $this->assertSame($xmlBefore, $signed->unsigned_xml);
        $this->assertSame($pihBefore, $signed->previous_invoice_hash);
    }

    private function chainHead(): ?string
    {
        return ZatcaEgsState::where('egs_identifier', self::EGS)
            ->where('state_type', 'production')
            ->value('previous_invoice_hash')
            ?? base64_encode(hash('sha256', '0'));
    }
}
