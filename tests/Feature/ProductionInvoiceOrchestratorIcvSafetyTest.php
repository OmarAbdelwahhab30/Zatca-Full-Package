<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Models\ZatcaEgsState;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;
use DateTimeImmutable;

/**
 * Guards the single most dangerous failure mode in the package: a gap or a
 * duplicate in the ICV sequence. ZATCA rejects every invoice after a broken
 * chain, so a bug here is not one bad invoice — it is every future one.
 *
 * Proves reserving the ICV and persisting the Draft row happen in one
 * transaction, and that a signing failure never causes a retry to reserve a
 * second ICV or create a second invoice for the same source. Uses a scripted
 * fake SDK — no Java required.
 */
class ProductionInvoiceOrchestratorIcvSafetyTest extends TestCase
{
    private const EGS = 'default';

    public function test_icv_and_uuid_are_reused_when_a_retry_follows_a_signing_failure(): void
    {
        $this->seedFakeProductionCredentials();

        $fakeSdk = $this->fakeSdk(failFirstSigningAttempt: true);
        $this->app->instance(ZatcaSdkInterface::class, $fakeSdk);

        $source = $this->fakeInvoiceSource();
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        // First attempt: signing throws. The Draft row (ICV/UUID/PIH already persisted) must survive.
        try {
            $orchestrator->generate($source, (object) ['id' => 7001], self::EGS);
            $this->fail('Expected the first signing attempt to throw.');
        } catch (ZatcaSdkProcessException) {
            // expected
        }

        $draft = ZatcaInvoice::where('egs_identifier', self::EGS)->where('source_reference', 'order:7001')->first();

        $this->assertNotNull($draft, 'A Draft row must exist after a failed signing attempt.');
        $this->assertSame(ZatcaInvoiceStatus::Draft, $draft->status);
        $this->assertSame(1, $draft->icv);

        $draftUuid = $draft->uuid;

        // Only ONE row exists — no orphaned/duplicate invoice from the failed attempt.
        $this->assertSame(1, ZatcaInvoice::where('egs_identifier', self::EGS)->count());

        // The ICV sequence must NOT have advanced past 1 despite the failure — reservation
        // and the Draft insert were atomic, so nothing was "spent" by the failed attempt.
        $state = ZatcaEgsState::where('egs_identifier', self::EGS)->where('state_type', 'production')->first();
        $this->assertSame(1, $state->icv);

        // Second attempt (the job's automatic retry): must reuse the SAME uuid/icv.
        $invoice = $orchestrator->generate($source, (object) ['id' => 7001], self::EGS);

        $this->assertSame($draft->id, $invoice->id);
        $this->assertSame($draftUuid, $invoice->uuid);
        $this->assertSame(1, $invoice->icv);
        $this->assertSame('pending_reporting', $invoice->status->value);
        $this->assertSame(1, ZatcaInvoice::where('egs_identifier', self::EGS)->count());

        // ICV still 1 — the retry did not reserve a second slot.
        $this->assertSame(1, $state->fresh()->icv);
    }

    public function test_invoice_is_never_re_signed_once_signing_has_succeeded(): void
    {
        $this->seedFakeProductionCredentials();

        $fakeSdk = $this->fakeSdk();
        $this->app->instance(ZatcaSdkInterface::class, $fakeSdk);

        $source = $this->fakeInvoiceSource();
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $first = $orchestrator->generate($source, (object) ['id' => 7002], self::EGS);
        $second = $orchestrator->generate($source, (object) ['id' => 7002], self::EGS);

        $this->assertSame(1, $fakeSdk->signAttempts, 'signInvoice() must be called exactly once — the second generate() must not re-sign.');
        $this->assertSame($first->invoice_hash, $second->invoice_hash);
        $this->assertSame($first->uuid, $second->uuid);
    }

    /** Each invoice must take the next slot — never repeat, never skip. */
    public function test_consecutive_invoices_take_consecutive_icvs(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->fakeSdk());

        $source = $this->fakeInvoiceSource();
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $first = $orchestrator->generate($source, (object) ['id' => 7101], self::EGS);
        $second = $orchestrator->generate($source, (object) ['id' => 7102], self::EGS);
        $third = $orchestrator->generate($source, (object) ['id' => 7103], self::EGS);

        $this->assertSame([1, 2, 3], [$first->icv, $second->icv, $third->icv]);

        // And each invoice must be chained to the hash of the one before it.
        $this->assertSame($first->invoice_hash, $second->previous_invoice_hash);
        $this->assertSame($second->invoice_hash, $third->previous_invoice_hash);
    }

    private function fakeSdk(bool $failFirstSigningAttempt = false): ZatcaSdkInterface
    {
        return new class($failFirstSigningAttempt) implements ZatcaSdkInterface
        {
            public int $signAttempts = 0;

            public function __construct(private readonly bool $failFirstSigningAttempt) {}

            public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
            {
                return ['csr' => '', 'privateKey' => ''];
            }

            public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
            {
                $this->signAttempts++;

                if ($this->failFirstSigningAttempt && $this->signAttempts === 1) {
                    throw ZatcaSdkProcessException::nonZeroExit(['-sign'], 1, 'simulated SDK crash');
                }

                return [
                    'signedXmlPath' => $unsignedXmlPath,
                    'signedXmlBase64' => base64_encode('signed'),
                    'invoiceHash' => 'HASH-'.$this->signAttempts,
                    'qrCode' => 'QR-DATA',
                ];
            }

            public function generateHash(string $xmlPath): string
            {
                return 'HASH';
            }

            public function validateInvoice(string $signedXmlPath, string $certificatePath): array
            {
                return ['valid' => true, 'errors' => [], 'warnings' => []];
            }

            public function version(): string
            {
                return '3.4.8';
            }

            public function executablePath(): string
            {
                return '/fake/path.jar';
            }
        };
    }

    private function seedFakeProductionCredentials(): void
    {
        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'FAKE_TOKEN',
            'secret' => 'FAKE_SECRET',
            'certificate' => 'FAKE_CERT',
            'private_key' => 'FAKE_KEY',
        ]);
    }

    private function fakeInvoiceSource(): InvoiceSourceContract
    {
        return new class implements InvoiceSourceContract
        {
            public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
            {
                $line = new ZatcaInvoiceLine('Course Enrollment', 1, 100.00, 15.0, 100.00, 15.00);

                return new ZatcaInvoiceData(
                    $egsIdentifier,
                    $this->sourceReference($source),
                    null,
                    null,
                    new DateTimeImmutable,
                    'SAR',
                    [$line],
                    100.00,
                    15.00,
                    115.00,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'order:'.$source->id;
            }
        };
    }
}
