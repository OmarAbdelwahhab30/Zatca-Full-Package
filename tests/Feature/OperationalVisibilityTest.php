<?php

namespace Zatca\Tests\Feature;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Events\ZatcaCertificateExpiring;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * Covers the "nobody has to be called at 3am" tooling: the alerting events,
 * zatca:status, and zatca:requeue.
 */
class OperationalVisibilityTest extends TestCase
{
    private const EGS = 'default';

    // ---------------------------------------------------------------- events

    public function test_it_fires_an_event_when_a_certificate_enters_the_warning_window(): void
    {
        Event::fake([ZatcaCertificateExpiring::class]);

        $this->seedProductionCredential(CarbonImmutable::now()->addDays(30));

        $state = app(CertificateLifecycleManager::class)->check(self::EGS, ZatcaCredentialType::Production);

        $this->assertSame(ZatcaCertificateState::ExpiringSoon, $state);

        Event::assertDispatched(ZatcaCertificateExpiring::class, function (ZatcaCertificateExpiring $event) {
            return $event->egsIdentifier === self::EGS
                && $event->state === ZatcaCertificateState::ExpiringSoon
                && ! $event->hasExpired()
                && $event->daysRemaining > 0;
        });
    }

    public function test_it_fires_an_event_when_a_certificate_expires(): void
    {
        Event::fake([ZatcaCertificateExpiring::class]);

        $this->seedProductionCredential(CarbonImmutable::now()->subDay());

        app(CertificateLifecycleManager::class)->check(self::EGS, ZatcaCredentialType::Production);

        Event::assertDispatched(ZatcaCertificateExpiring::class, fn (ZatcaCertificateExpiring $e) => $e->hasExpired());
    }

    /** One alert per transition — a daily scheduled check must not re-alert every day. */
    public function test_it_does_not_re_fire_while_the_state_is_unchanged(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(30));

        $manager = app(CertificateLifecycleManager::class);
        $manager->check(self::EGS, ZatcaCredentialType::Production);

        Event::fake([ZatcaCertificateExpiring::class]);

        $manager->check(self::EGS, ZatcaCredentialType::Production);

        Event::assertNotDispatched(ZatcaCertificateExpiring::class);
    }

    public function test_a_healthy_certificate_fires_nothing(): void
    {
        Event::fake([ZatcaCertificateExpiring::class]);

        $this->seedProductionCredential(CarbonImmutable::now()->addDays(300));

        app(CertificateLifecycleManager::class)->check(self::EGS, ZatcaCredentialType::Production);

        Event::assertNotDispatched(ZatcaCertificateExpiring::class);
    }

    /**
     * Credentials onboarded before the certificate parser was fixed have a null
     * expiry, which made them invisible to every check. check() must recover the
     * date from the stored certificate instead of reporting Valid forever.
     */
    public function test_it_recovers_a_missing_expiry_date_from_the_stored_certificate(): void
    {
        $token = trim(file_get_contents(__DIR__.'/../Fixtures/Certificates/zatca_sample_bst.txt'));

        $credential = ZatcaEgsCredential::create([
            'egs_identifier' => self::EGS,
            'environment' => 'sandbox',
            'credential_type' => ZatcaCredentialType::Production->value,
            'binary_security_token' => $token,
            'certificate' => $token,
            'certificate_expires_at' => null,
            'certificate_state' => ZatcaCertificateState::Valid->value,
        ]);

        app(CertificateLifecycleManager::class)->check(self::EGS, ZatcaCredentialType::Production);

        $credential->refresh();

        $this->assertNotNull($credential->certificate_expires_at, 'The expiry must be recovered, not left null.');
        $this->assertSame('2029-01-09', $credential->certificate_expires_at->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- status

    public function test_status_passes_when_nothing_needs_attention(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(300));

        $this->artisan('zatca:status')->assertSuccessful();
    }

    public function test_status_fails_when_an_invoice_is_in_manual_review(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(300));
        $this->makeInvoice(ZatcaInvoiceStatus::ManualReview);

        $this->artisan('zatca:status')->assertFailed();
    }

    public function test_status_fails_when_the_certificate_has_expired(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->subDay());

        $this->artisan('zatca:status')->assertFailed();
    }

    public function test_status_flags_invoices_stuck_in_the_queue(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(300));

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::PendingReporting);
        $invoice->forceFill(['created_at' => CarbonImmutable::now()->subHours(5)])->save();

        $this->artisan('zatca:status', ['--stale-minutes' => 60])->assertFailed();
    }

    public function test_status_emits_valid_json(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(300));

        $this->artisan('zatca:status', ['--json' => true])->assertSuccessful();
    }

    /**
     * A certificate two months out is worth planning for, but must not page anyone
     * daily for two months. It fails only under --strict.
     */
    public function test_a_warning_does_not_fail_unless_strict(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(45));

        $this->artisan('zatca:status')->assertSuccessful();
        $this->artisan('zatca:status', ['--strict' => true])->assertFailed();
    }

    /** 30 days out is a hard failure: renewal needs a human with a portal OTP. */
    public function test_status_fails_when_renewal_can_no_longer_wait(): void
    {
        $this->seedProductionCredential(CarbonImmutable::now()->addDays(20));

        $this->artisan('zatca:status')->assertFailed();
    }

    // --------------------------------------------------------------- requeue

    public function test_requeue_returns_a_manual_review_invoice_to_the_queue(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 4);

        $this->artisan('zatca:requeue')->assertSuccessful();

        $invoice->refresh();

        $this->assertSame(ZatcaInvoiceStatus::Retrying, $invoice->status);
        $this->assertSame(0, $invoice->attempt_count, 'Attempts must reset or the invoice bounces straight back.');

        Bus::assertDispatched(RetryZatcaInvoiceJob::class);
    }

    public function test_requeue_can_keep_the_attempt_counter(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 4);

        $this->artisan('zatca:requeue', ['--keep-attempts' => true])->assertSuccessful();

        $this->assertSame(4, $invoice->refresh()->attempt_count);
    }

    /** The one thing this command must never do. */
    public function test_requeue_never_touches_an_invoice_zatca_already_accepted(): void
    {
        Bus::fake();

        $reported = $this->makeInvoice(ZatcaInvoiceStatus::Reported);

        $this->artisan('zatca:requeue', ['--id' => [$reported->id]])->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::Reported, $reported->refresh()->status);

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
    }

    public function test_requeue_refuses_a_terminal_success_status(): void
    {
        $this->artisan('zatca:requeue', ['--status' => 'reported'])->assertFailed();
    }

    public function test_requeue_dry_run_changes_nothing(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 4);

        $this->artisan('zatca:requeue', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $invoice->refresh()->status);

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
    }

    public function test_requeue_is_a_no_op_when_nothing_is_stuck(): void
    {
        Bus::fake();

        $this->artisan('zatca:requeue')->assertSuccessful();

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
    }

    // ------------------------------------------------- scheduled auto-requeue

    /**
     * The whole point of --only-transient: a ZATCA outage victim is restarted
     * automatically, while an invoice ZATCA rejected is left alone no matter how
     * often the scheduler runs. Retrying a rejection would fail identically forever.
     */
    public function test_only_transient_requeues_outage_victims_but_not_rejections(): void
    {
        Bus::fake();

        $outage = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 12);
        $this->addAttempt($outage, httpStatus: 503);

        $rejected = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 1);
        $this->addAttempt($rejected, httpStatus: 400);

        $this->artisan('zatca:requeue', ['--only-transient' => true])->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::Retrying, $outage->refresh()->status);
        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $rejected->refresh()->status);

        Bus::assertDispatchedTimes(RetryZatcaInvoiceJob::class, 1);
    }

    /** A network failure records HTTP 0 — also an outage, also retryable. */
    public function test_only_transient_treats_a_network_error_as_retryable(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 12);
        $this->addAttempt($invoice, httpStatus: 0);

        $this->artisan('zatca:requeue', ['--only-transient' => true])->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::Retrying, $invoice->refresh()->status);
    }

    /** Without an attempt on record there is no evidence of an outage — don't guess. */
    public function test_only_transient_skips_invoices_with_no_attempt_history(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview);

        $this->artisan('zatca:requeue', ['--only-transient' => true])->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $invoice->refresh()->status);
    }

    /** Stops an endless retry loop if something keeps returning 5xx forever. */
    public function test_only_transient_gives_up_after_too_many_total_attempts(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(ZatcaInvoiceStatus::ManualReview, attempts: 60);

        for ($i = 1; $i <= 6; $i++) {
            $this->addAttempt($invoice, httpStatus: 503, attemptNumber: $i);
        }

        $this->artisan('zatca:requeue', ['--only-transient' => true, '--max-total-attempts' => 5])
            ->assertSuccessful();

        $this->assertSame(ZatcaInvoiceStatus::ManualReview, $invoice->refresh()->status);
        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
    }

    private function addAttempt(ZatcaInvoice $invoice, int $httpStatus, int $attemptNumber = 1): void
    {
        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $invoice->id,
            'attempt_number' => $attemptNumber,
            'outcome' => 'failed',
            'http_status' => $httpStatus,
            'sanitized_response' => [],
            'error_message' => 'failure',
            'attempted_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- setup

    private function seedProductionCredential(CarbonImmutable $expiresAt): ZatcaEgsCredential
    {
        return ZatcaEgsCredential::create([
            'egs_identifier' => self::EGS,
            'environment' => 'sandbox',
            'credential_type' => ZatcaCredentialType::Production->value,
            'binary_security_token' => 'dG9rZW4=',
            'certificate' => 'dG9rZW4=',
            'certificate_expires_at' => $expiresAt,
            'certificate_state' => ZatcaCertificateState::Valid->value,
        ]);
    }

    private function makeInvoice(ZatcaInvoiceStatus $status, int $attempts = 0): ZatcaInvoice
    {
        return ZatcaInvoice::create([
            'egs_identifier' => self::EGS,
            'environment' => 'sandbox',
            'source_reference' => 'order:'.uniqid(),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'icv' => 1,
            'previous_invoice_hash' => base64_encode(hash('sha256', '0')),
            'unsigned_xml' => '<Invoice/>',
            'signed_xml_base64' => base64_encode('<Invoice/>'),
            'invoice_hash' => 'hash',
            'qr_code' => base64_encode('qr'),
            'total_excl_vat' => 100,
            'total_vat' => 15,
            'total_incl_vat' => 115,
            'currency' => 'SAR',
            'status' => $status->value,
            'attempt_count' => $attempts,
            'last_error' => $status === ZatcaInvoiceStatus::ManualReview ? 'ZATCA rejected the invoice' : null,
        ]);
    }
}
