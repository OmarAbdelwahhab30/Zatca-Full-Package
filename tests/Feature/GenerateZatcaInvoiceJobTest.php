<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\InvoiceSourceReference;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;

/**
 * Proves GenerateZatcaInvoiceJob carries only scalar reference data (never
 * the host's model/object), reloads the source fresh via
 * InvoiceSourceResolverContract at execution time, and behaves safely when
 * the source is missing/deleted or of an unsupported type.
 */
class GenerateZatcaInvoiceJobTest extends TestCase
{
    private const EGS = 'default';

    public function test_job_payload_is_fully_scalar_and_serializes_cleanly(): void
    {
        $job = new GenerateZatcaInvoiceJob('App\\Models\\Order', '42', self::EGS);

        $serialized = serialize($job);
        $restored = unserialize($serialized);

        $this->assertInstanceOf(GenerateZatcaInvoiceJob::class, $restored);
    }

    public function test_handle_resolves_the_source_at_execution_time_and_generates_the_invoice(): void
    {
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->fakeSdk());
        $this->app->bind(InvoiceSourceContract::class, fn () => $this->fakeInvoiceSource());
        $this->app->bind(InvoiceSourceResolverContract::class, function () {
            return new class implements InvoiceSourceResolverContract
            {
                public function resolve(InvoiceSourceReference $reference): mixed
                {
                    return (object) ['id' => $reference->sourceId];
                }
            };
        });

        Bus::fake([ReportZatcaInvoiceJob::class]);

        $job = new GenerateZatcaInvoiceJob('fake-order-type', '9101', self::EGS);
        $job->handle(
            app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class),
            app(InvoiceSourceContract::class),
            app(InvoiceSourceResolverContract::class),
            app(\Zatca\Support\ZatcaGate::class)
        );

        $this->assertDatabaseHas('zatca_invoices', ['source_reference' => 'order:9101']);
        Bus::assertDispatched(ReportZatcaInvoiceJob::class);
    }

    public function test_handle_fails_gracefully_when_the_source_cannot_be_resolved(): void
    {
        $this->app->bind(InvoiceSourceContract::class, fn () => $this->fakeInvoiceSource());
        $this->app->bind(InvoiceSourceResolverContract::class, function () {
            return new class implements InvoiceSourceResolverContract
            {
                public function resolve(InvoiceSourceReference $reference): mixed
                {
                    return null; // deleted / unsupported source_type
                }
            };
        });

        $job = new GenerateZatcaInvoiceJob('App\\Models\\DeletedOrder', '404', self::EGS);
        $job->handle(
            app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class),
            app(InvoiceSourceContract::class),
            app(InvoiceSourceResolverContract::class),
            app(\Zatca\Support\ZatcaGate::class)
        );

        $this->assertDatabaseMissing('zatca_invoices', ['source_reference' => 'order:404']);
    }

    private function seedFakeProductionCredentials(): void
    {
        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'TOKEN',
            'secret' => 'SECRET',
            'certificate' => 'CERT',
            'private_key' => 'KEY',
        ]);
    }

    private function fakeSdk(): ZatcaSdkInterface
    {
        return new class implements ZatcaSdkInterface
        {
            public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
            {
                return ['csr' => '', 'privateKey' => ''];
            }

            public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
            {
                return ['signedXmlPath' => $unsignedXmlPath, 'signedXmlBase64' => base64_encode('signed'), 'invoiceHash' => 'HASH', 'qrCode' => 'QR'];
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

    private function fakeInvoiceSource(): InvoiceSourceContract
    {
        return new class implements InvoiceSourceContract
        {
            public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
            {
                $line = new ZatcaInvoiceLine('Item', 1, 100.00, 15.0, 100.00, 15.00);

                return new ZatcaInvoiceData($egsIdentifier, $this->sourceReference($source), null, null, new DateTimeImmutable, 'SAR', [$line], 100.00, 15.00, 115.00);
            }

            public function sourceReference(mixed $source): string
            {
                return 'order:'.$source->id;
            }
        };
    }
}
