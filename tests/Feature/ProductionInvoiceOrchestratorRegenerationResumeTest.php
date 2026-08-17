<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * ICV safety for the regeneration path.
 *
 * An ICV slot is spent the moment it is reserved and can never be reclaimed, so a
 * regeneration that fails while signing must not be answered by reserving another one. The
 * draft it left behind is resumed instead — same UUID, same ICV, same document.
 *
 * The exception is a draft the current generator would no longer produce. Re-signing that
 * would re-emit the very content ZATCA refused, which is worse than spending a slot, so it
 * is abandoned and replaced. Whether a draft still matches is decided by comparing bytes,
 * not by guessing from which exception was thrown.
 *
 * This is the regeneration counterpart of ProductionInvoiceOrchestratorIcvSafetyTest.
 */
class ProductionInvoiceOrchestratorRegenerationResumeTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();
    }

    public function test_a_failed_regeneration_leaves_exactly_one_draft_and_spends_one_icv(): void
    {
        // Attempt 1 signs the original; attempt 2 is the regeneration, which fails.
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2]));

        $original = $this->rejectedInvoice(9201);
        $icvBefore = $this->currentIcv();

        try {
            app(ProductionInvoiceOrchestrator::class)->regenerate($original);
            $this->fail('Expected the regeneration signing attempt to throw.');
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $drafts = ZatcaInvoice::where('regenerated_from_id', $original->id)->get();

        $this->assertCount(1, $drafts);
        $this->assertSame(ZatcaInvoiceStatus::Draft, $drafts->first()->status);
        $this->assertSame($icvBefore + 1, $this->currentIcv());

        // The original is still the invoice's document: nothing submittable was produced.
        $this->assertNull($original->fresh()->superseded_by_id);
    }

    public function test_regenerating_again_resumes_the_same_draft_without_spending_another_icv(): void
    {
        $sdk = $this->scriptedSdk(failOnAttempts: [2]);
        $this->app->instance(ZatcaSdkInterface::class, $sdk);

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9202);

        try {
            $orchestrator->regenerate($original);
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $draft = ZatcaInvoice::where('regenerated_from_id', $original->id)->firstOrFail();
        $icvAfterFailure = $this->currentIcv();

        // Second attempt: the generator is unchanged, so the draft is still exactly what
        // this build would produce and must be signed as-is.
        $replacement = $orchestrator->regenerate($original->fresh());

        $this->assertSame($draft->id, $replacement->id, 'The existing draft must be resumed, not replaced.');
        $this->assertSame($draft->uuid, $replacement->uuid);
        $this->assertSame($draft->icv, $replacement->icv);
        $this->assertSame($draft->previous_invoice_hash, $replacement->previous_invoice_hash);
        $this->assertSame($draft->unsigned_xml, $replacement->unsigned_xml);
        $this->assertSame(ZatcaInvoiceStatus::PendingReporting, $replacement->status);

        // The whole point: no second slot was consumed, and no second replacement exists.
        $this->assertSame($icvAfterFailure, $this->currentIcv());
        $this->assertSame(1, ZatcaInvoice::where('regenerated_from_id', $original->id)->count());

        // Only now is the original superseded.
        $this->assertSame($replacement->id, (int) $original->fresh()->superseded_by_id);
    }

    public function test_repeated_failures_never_accumulate_more_than_one_live_draft(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2, 3, 4]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9203);

        foreach (range(1, 3) as $ignored) {
            try {
                $orchestrator->regenerate($original->fresh());
            } catch (ZatcaSdkProcessException) {
                // expected
            }
        }

        $drafts = ZatcaInvoice::where('regenerated_from_id', $original->id)->get();

        $this->assertCount(1, $drafts, 'Three failed attempts must not produce three drafts.');
        $this->assertNull($drafts->first()->abandoned_at);
    }

    public function test_a_draft_the_generator_no_longer_produces_is_abandoned_and_replaced(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9204);

        try {
            $orchestrator->regenerate($original);
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $stale = ZatcaInvoice::where('regenerated_from_id', $original->id)->firstOrFail();

        // Stands in for the case this rule exists for: the XML generator was fixed after
        // this draft was built, so the draft's document is no longer what the current code
        // produces. Re-signing it would re-emit the content ZATCA refused.
        $stale->update(['unsigned_xml' => str_replace('Original Buyer', 'Stale Buyer', $stale->unsigned_xml)]);

        $icvBefore = $this->currentIcv();
        $replacement = $orchestrator->regenerate($original->fresh());

        $this->assertNotSame($stale->id, $replacement->id, 'A stale draft must not be resumed.');
        $this->assertNotNull($stale->fresh()->abandoned_at);
        $this->assertSame(ZatcaInvoiceStatus::Draft, $stale->fresh()->status);

        // A fresh slot is spent — deliberately, because correctness outweighs the sequence.
        $this->assertSame($icvBefore + 1, $this->currentIcv());
        $this->assertSame($replacement->icv, $stale->icv + 1);

        // Only the signed replacement is linked; the abandoned draft never becomes the
        // invoice's document.
        $this->assertSame($replacement->id, (int) $original->fresh()->superseded_by_id);
        $this->assertSame($replacement->id, $original->fresh()->authoritative()->id);
    }

    public function test_an_abandoned_draft_is_never_reconsidered(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2, 3]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9205);

        try {
            $orchestrator->regenerate($original);
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $abandoned = ZatcaInvoice::where('regenerated_from_id', $original->id)->firstOrFail();
        $abandoned->update([
            'unsigned_xml' => str_replace('Original Buyer', 'Stale Buyer', $abandoned->unsigned_xml),
        ]);

        // Abandons the stale draft, builds a second one, and fails to sign it too.
        try {
            $orchestrator->regenerate($original->fresh());
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $this->assertNotNull($abandoned->fresh()->abandoned_at);

        $live = ZatcaInvoice::where('regenerated_from_id', $original->id)
            ->whereNull('abandoned_at')
            ->get();

        // The third attempt must consider only the live draft, never the abandoned one.
        $this->assertCount(1, $live);
        $this->assertNotSame($abandoned->id, $live->first()->id);

        $replacement = $orchestrator->regenerate($original->fresh());

        $this->assertSame($live->first()->id, $replacement->id);
        $this->assertNotSame($abandoned->id, $replacement->id);
    }

    public function test_a_resumed_draft_is_rechained_if_other_invoices_were_signed_meanwhile(): void
    {
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [2]));

        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $original = $this->rejectedInvoice(9206);

        try {
            $orchestrator->regenerate($original);
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $draft = ZatcaInvoice::where('regenerated_from_id', $original->id)->firstOrFail();
        $stalePih = $draft->previous_invoice_hash;

        // An unrelated sale is invoiced and signed while the replacement sits unsigned.
        $other = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 9299], self::EGS);

        $replacement = $orchestrator->regenerate($original->fresh());

        // Resumed — same slot, same identity …
        $this->assertSame($draft->id, $replacement->id);
        $this->assertSame($draft->uuid, $replacement->uuid);
        $this->assertSame($draft->icv, $replacement->icv);

        // … but chained to what was actually signed before it, not to the stale head it
        // captured when it was built.
        $this->assertSame($other->invoice_hash, $replacement->previous_invoice_hash);
        $this->assertNotSame($stalePih, $replacement->previous_invoice_hash);
    }

    private function currentIcv(): int
    {
        return (int) ZatcaEgsState::where('egs_identifier', self::EGS)
            ->where('state_type', 'production')
            ->value('icv');
    }
}
