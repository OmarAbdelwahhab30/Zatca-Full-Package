<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Events\ZatcaInvoiceGenerated;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

/**
 * Exercises the full generate -> sign path against the REAL ZATCA SDK jar,
 * using the SDK's own bundled sample certificate and private key.
 *
 * Opt-in: skipped unless ZATCA_SDK_EXECUTABLE and ZATCA_SDK_ROOT are exported
 * in the environment running PHPUnit, so CI without the SDK stays green. Run
 * it locally before releasing a change to signing:
 *
 *   ZATCA_SDK_EXECUTABLE=/path/to.jar ZATCA_SDK_ROOT=/path/to/sdk vendor/bin/phpunit
 *
 * ProductionInvoiceOrchestratorIcvSafetyTest covers the same orchestration
 * logic with a fake SDK and always runs.
 */
class ProductionInvoiceOrchestratorTest extends TestCase
{
    private const EGS = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $executable = env('ZATCA_SDK_EXECUTABLE');
        $root = env('ZATCA_SDK_ROOT');

        if (! $executable || ! is_file($executable) || ! $root || ! is_dir($root)) {
            $this->markTestSkipped('ZATCA SDK not configured in this environment (set ZATCA_SDK_EXECUTABLE and ZATCA_SDK_ROOT to run).');
        }

        if (! is_file($root.'/Data/Certificates/cert.pem')) {
            $this->markTestSkipped('SDK sample certificate not found — cannot sign.');
        }

        Config::set('zatca.sdk.executable', $executable);
        Config::set('zatca.sdk.root', $root);
    }

    public function test_generation_is_idempotent_and_fires_event_only_once(): void
    {
        $this->seedDummyProductionCredentials();

        Event::fake([ZatcaInvoiceGenerated::class]);

        $source = $this->fakeInvoiceSource();
        $orchestrator = app(ProductionInvoiceOrchestrator::class);

        $first = $orchestrator->generate($source, (object) ['id' => 5001], self::EGS);
        $second = $orchestrator->generate($source, (object) ['id' => 5001], self::EGS);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame($first->invoice_hash, $second->invoice_hash);
        $this->assertSame(1, ZatcaInvoice::where('egs_identifier', self::EGS)->count());

        Event::assertDispatchedTimes(ZatcaInvoiceGenerated::class, 1);
    }

    public function test_generated_invoice_is_pending_reporting_with_a_valid_qr_code(): void
    {
        $this->seedDummyProductionCredentials();

        $invoice = app(ProductionInvoiceOrchestrator::class)->generate(
            $this->fakeInvoiceSource(),
            (object) ['id' => 5002],
            self::EGS
        );

        $this->assertSame('pending_reporting', $invoice->status->value);
        $this->assertNotEmpty($invoice->qr_code);
        $this->assertSame(1, $invoice->icv);
    }

    private function seedDummyProductionCredentials(): void
    {
        $sdkRoot = Config::get('zatca.sdk.root');

        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'FAKE_TOKEN',
            'secret' => 'FAKE_SECRET',
            'certificate' => trim(file_get_contents($sdkRoot.'/Data/Certificates/cert.pem')),
            'private_key' => trim(file_get_contents($sdkRoot.'/Data/Certificates/ec-secp256k1-priv-key.pem')),
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
