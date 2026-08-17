<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Regeneration\RegenerationPolicy;
use Zatca\Services\Regeneration\RegenerationVerdict;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * One case per reason a document may or may not be regenerated.
 *
 * These are the rules that decide whether an ICV slot gets spent, so each is asserted
 * individually rather than through a single "is eligible" happy path. The reason code is
 * asserted too, not just the verdict: a refusal that reports the wrong reason sends the
 * administrator to fix the wrong thing.
 */
class RegenerationPolicyTest extends TestCase
{
    use BuildsProductionInvoices;

    private const EGS = 'default';

    private RegenerationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());

        $this->policy = app(RegenerationPolicy::class);
    }

    public function test_a_validation_rejection_is_regenerable(): void
    {
        // The case the whole feature exists for: ZATCA read the document and refused it.
        $verdict = $this->policy->verdict($this->rejectedInvoice(1001, httpStatus: 400));

        $this->assertTrue($verdict->eligible);
        $this->assertNull($verdict->reason);
    }

    /** @dataProvider clientRejectionStatuses */
    public function test_every_document_rejection_status_is_regenerable(int $httpStatus): void
    {
        $this->assertTrue($this->policy->verdict($this->rejectedInvoice(1100 + $httpStatus, $httpStatus))->eligible);
    }

    public static function clientRejectionStatuses(): array
    {
        return [[400], [404], [406], [422], [409]];
    }

    public function test_no_document_is_refused(): void
    {
        $verdict = $this->policy->verdict(null);

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::NO_ZATCA_DOCUMENT, $verdict->reason);
    }

    /**
     * @dataProvider terminalSuccessStatuses
     *
     * Covers "accepted with warnings → do nothing" as well as a plain acceptance: both are
     * final, and reporting a second document for either would duplicate the sale at ZATCA.
     */
    public function test_an_accepted_document_is_never_regenerable(string $status): void
    {
        $invoice = $this->rejectedInvoice(2001);
        $invoice->update(['status' => $status]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::ALREADY_REPORTED, $verdict->reason);
    }

    public static function terminalSuccessStatuses(): array
    {
        return [
            'reported' => [ZatcaInvoiceStatus::Reported->value],
            'reported with warnings' => [ZatcaInvoiceStatus::ReportedWithWarnings->value],
        ];
    }

    /**
     * @dataProvider transientFailureStatuses
     *
     * Timeout/network (HTTP 0) and ZATCA server errors (5xx). The document was never
     * judged, so the fix is resending the same signed bytes — regenerating would spend a
     * slot to send an identical document.
     */
    public function test_a_transient_failure_says_retry_instead(int $httpStatus): void
    {
        $verdict = $this->policy->verdict($this->rejectedInvoice(3000 + $httpStatus, $httpStatus));

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::TRANSIENT_FAILURE_RETRY_INSTEAD, $verdict->reason);
    }

    public static function transientFailureStatuses(): array
    {
        return [
            'network error' => [0],
            'internal server error' => [500],
            'bad gateway' => [502],
            'service unavailable' => [503],
        ];
    }

    /**
     * @dataProvider authFailureStatuses
     *
     * 401/403 are 4xx but are not rejections of the document. A perfect replacement would
     * earn exactly the same refusal, so the credentials are what must change.
     */
    public function test_an_authentication_failure_says_fix_credentials(int $httpStatus): void
    {
        $verdict = $this->policy->verdict($this->rejectedInvoice(4000 + $httpStatus, $httpStatus));

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::CREDENTIALS_ERROR, $verdict->reason);
    }

    public static function authFailureStatuses(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
        ];
    }

    public function test_an_expired_certificate_says_fix_the_certificate_first(): void
    {
        $invoice = $this->rejectedInvoice(5001);

        ZatcaEgsCredential::query()
            ->forEgs(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production)
            ->update(['certificate_state' => ZatcaCertificateState::Expired->value]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::CERTIFICATE_EXPIRED, $verdict->reason);
    }

    public function test_a_document_with_no_business_snapshot_is_refused_with_its_own_reason(): void
    {
        $invoice = $this->rejectedInvoice(6001);

        // Every invoice generated before snapshots were recorded looks like this.
        $invoice->update(['invoice_data_snapshot' => null]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(
            RegenerationVerdict::NO_SNAPSHOT,
            $verdict->reason,
            'Missing snapshot must be distinguishable from a non-regenerable failure type.'
        );
    }

    public function test_a_superseded_document_is_refused(): void
    {
        $original = $this->rejectedInvoice(7001);
        app(ProductionInvoiceOrchestrator::class)->regenerate($original);

        $verdict = $this->policy->verdict($original->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::SUPERSEDED, $verdict->reason);
    }

    public function test_a_document_with_a_regeneration_in_flight_is_refused(): void
    {
        $invoice = $this->rejectedInvoice(8001);
        $invoice->update(['regeneration_claimed_at' => now()]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::IN_FLIGHT, $verdict->reason);
    }

    public function test_a_document_being_retried_is_refused(): void
    {
        $invoice = $this->rejectedInvoice(8002);
        $invoice->update(['status' => ZatcaInvoiceStatus::Retrying]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::IN_FLIGHT, $verdict->reason);
    }

    public function test_a_document_that_was_never_submitted_is_refused(): void
    {
        // Signed and pending, but ZATCA has never seen it — nothing has been rejected.
        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 9001], self::EGS);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::NO_ATTEMPT_RECORDED, $verdict->reason);
    }

    public function test_a_document_in_manual_review_with_no_recorded_attempt_is_refused(): void
    {
        $invoice = app(ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => 9002], self::EGS);

        // Status says a human must look, but no attempt was recorded, so nothing proves
        // ZATCA refused the document. Guessing would be the mistake this policy prevents.
        $invoice->update(['status' => ZatcaInvoiceStatus::ManualReview]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::NO_ATTEMPT_RECORDED, $verdict->reason);
    }

    public function test_an_unsigned_document_is_refused(): void
    {
        $invoice = $this->rejectedInvoice(9003);
        $invoice->update(['signed_xml_base64' => null]);

        $verdict = $this->policy->verdict($invoice->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::NOT_SUBMITTABLE, $verdict->reason);
    }

    public function test_the_chain_depth_limit_is_enforced(): void
    {
        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $document = $this->rejectedInvoice(9004);

        for ($i = 0; $i < ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH; $i++) {
            $document = $this->markRejected($orchestrator->regenerate($document));
        }

        $verdict = $this->policy->verdict($document->fresh());

        $this->assertFalse($verdict->eligible);
        $this->assertSame(RegenerationVerdict::CHAIN_DEPTH_EXCEEDED, $verdict->reason);
    }

    /**
     * Retry and regeneration must never both be on offer: a failure the same signed bytes
     * can survive is not a failure a new document fixes.
     */
    public function test_retryable_and_regenerable_never_overlap(): void
    {
        $transient = $this->rejectedInvoice(9101, httpStatus: 0);
        $rejected = $this->rejectedInvoice(9102, httpStatus: 400);

        $this->assertFalse($this->policy->allows($transient));
        $this->assertTrue($this->policy->allows($rejected));
    }
}
