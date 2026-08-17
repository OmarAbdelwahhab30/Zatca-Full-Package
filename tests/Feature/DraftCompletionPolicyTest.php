<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Generation\DraftCompletionPolicy;
use Zatca\Services\Generation\DraftCompletionVerdict;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * One case per reason code.
 *
 * The reason matters as much as the verdict: each one points at a different corrective
 * action, so a policy that blocked correctly but reported the wrong reason would send an
 * administrator to fix the wrong thing. Every code below is asserted by identity, not by
 * "not eligible".
 */
class DraftCompletionPolicyTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk(failOnAttempts: [1]));
    }

    private function policy(): DraftCompletionPolicy
    {
        return new DraftCompletionPolicy;
    }

    public function test_an_unsigned_draft_is_eligible(): void
    {
        $verdict = $this->policy()->verdict($this->strandedDraft(9301));

        $this->assertTrue($verdict->eligible);
        $this->assertNull($verdict->reason);
    }

    public function test_a_missing_document_is_refused(): void
    {
        $this->assertSame(
            DraftCompletionVerdict::NO_ZATCA_DOCUMENT,
            $this->policy()->verdict(null)->reason
        );
    }

    public function test_a_signed_document_is_refused(): void
    {
        // A fresh SDK that succeeds, so this invoice actually gets signed.
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $signed = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 9302], self::EGS);

        $this->assertSame(
            DraftCompletionVerdict::ALREADY_SIGNED,
            $this->policy()->verdict($signed)->reason
        );
    }

    public function test_an_abandoned_draft_is_refused(): void
    {
        $draft = $this->strandedDraft(9303);
        $draft->update(['abandoned_at' => now()]);

        $this->assertSame(
            DraftCompletionVerdict::ABANDONED,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }

    public function test_a_claimed_draft_is_refused(): void
    {
        $draft = $this->strandedDraft(9304);
        $draft->update(['generation_claimed_at' => now()]);

        $this->assertSame(
            DraftCompletionVerdict::IN_FLIGHT,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }

    /** A regeneration in flight is building a replacement; signing this one would race it. */
    public function test_a_draft_with_a_regeneration_in_flight_is_refused(): void
    {
        $draft = $this->strandedDraft(9305);
        $draft->update(['regeneration_claimed_at' => now()]);

        $this->assertSame(
            DraftCompletionVerdict::IN_FLIGHT,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }

    public function test_a_draft_with_no_unsigned_xml_is_refused(): void
    {
        $draft = $this->strandedDraft(9306);
        $draft->update(['unsigned_xml' => '']);

        $this->assertSame(
            DraftCompletionVerdict::NOTHING_TO_SIGN,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }

    public function test_a_draft_on_an_egs_without_production_credentials_is_refused(): void
    {
        $draft = $this->strandedDraft(9307);

        ZatcaEgsCredential::query()
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->delete();

        $this->assertSame(
            DraftCompletionVerdict::CREDENTIALS_MISSING,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }

    public function test_a_draft_on_an_egs_with_an_expired_certificate_is_refused(): void
    {
        $draft = $this->strandedDraft(9308);

        ZatcaEgsCredential::query()
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->update(['certificate_state' => ZatcaCertificateState::Expired->value]);

        $this->assertSame(
            DraftCompletionVerdict::CERTIFICATE_EXPIRED,
            $this->policy()->verdict($draft->fresh())->reason
        );
    }
}
