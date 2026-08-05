<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\DTOs\Payloads\ProductionCsidPayload;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CertificateLifecycleManagerTest extends TestCase
{
    private const EGS = 'default';

    public function test_check_transitions_valid_to_expiring_soon_to_expired(): void
    {
        $manager = app(CertificateLifecycleManager::class);

        $this->seedProductionCredential(CarbonImmutable::now()->addDays(90));
        $this->assertSame(ZatcaCertificateState::Valid, $manager->check(self::EGS, ZatcaCredentialType::Production));

        $this->seedProductionCredential(CarbonImmutable::now()->addDays(10));
        $this->assertSame(ZatcaCertificateState::ExpiringSoon, $manager->check(self::EGS, ZatcaCredentialType::Production));

        $this->seedProductionCredential(CarbonImmutable::now()->subDay());
        $this->assertSame(ZatcaCertificateState::Expired, $manager->check(self::EGS, ZatcaCredentialType::Production));
    }

    public function test_renew_does_not_call_the_api_when_auto_renewal_is_disabled(): void
    {
        Config::set('zatca.certificate.auto_renewal_enabled', false);

        $this->seedProductionCredential(CarbonImmutable::now()->subDay(), ZatcaCertificateState::Expired);

        $state = app(CertificateLifecycleManager::class)->renew(self::EGS, ZatcaCredentialType::Production);

        // Refuses to invent an API call — the certificate stays Expired, not silently "fixed".
        $this->assertSame(ZatcaCertificateState::Expired, $state);

        $credential = ZatcaEgsCredential::where('egs_identifier', self::EGS)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->first();

        $this->assertSame(ZatcaCertificateState::Expired, $credential->certificate_state);
    }

    public function test_renew_refuses_compliance_credentials(): void
    {
        Config::set('zatca.certificate.auto_renewal_enabled', true);

        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'binary_security_token' => 'TOKEN',
            'secret' => 'SECRET',
            'certificate' => 'CERT',
            'certificate_expires_at' => CarbonImmutable::now()->subDay(),
            'certificate_state' => ZatcaCertificateState::Expired,
        ]);

        $state = app(CertificateLifecycleManager::class)->renew(self::EGS, ZatcaCredentialType::Compliance);

        $this->assertSame(ZatcaCertificateState::Expired, $state);
    }

    public function test_expired_certificate_pauses_reporting_and_renewal_resumes_it_without_regenerating(): void
    {
        Bus::fake();

        // An invoice already signed and waiting to report, blocked by an expired certificate.
        $this->seedProductionCredential(CarbonImmutable::now()->subDay(), ZatcaCertificateState::Expired);

        $invoice = ZatcaInvoice::create([
            'egs_identifier' => self::EGS,
            'environment' => ZatcaEnvironment::Sandbox->value,
            'source_reference' => 'order:paused-1',
            'uuid' => (string) Str::uuid(),
            'icv' => 1,
            'invoice_hash' => 'HASH',
            'previous_invoice_hash' => base64_encode(hash('sha256', '0')),
            'unsigned_xml' => '<Invoice/>',
            'signed_xml_base64' => base64_encode('<Invoice/>'),
            'qr_code' => 'QR',
            'total_excl_vat' => 100,
            'total_vat' => 15,
            'total_incl_vat' => 115,
            'currency' => 'SAR',
            'status' => ZatcaInvoiceStatus::PendingReporting,
        ]);

        // Simulate a verified renewal succeeding, so renew() can reach the resume step.
        Config::set('zatca.certificate.auto_renewal_enabled', true);

        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'request_id' => 'req-123',
            'private_key' => 'FAKE-COMPLIANCE-PRIVATE-KEY',
        ]);

        $this->mock(ConnectorFactory::class, function ($mock) {
            $connector = \Mockery::mock(ZatcaConnectorInterface::class);

            $connector->shouldReceive('renewProductionCsid')->once()->andReturn(new ZatcaApiResult(
                successful: true,
                status: ZatcaResponseStatus::Pass,
                httpStatus: 200,
                requestId: null,
                sanitizedResponse: [],
                payload: new ProductionCsidPayload('NEW_TOKEN', 'NEW_SECRET'),
            ));

            $mock->shouldReceive('make')->andReturn($connector);
        });

        $state = app(CertificateLifecycleManager::class)->renew(self::EGS, ZatcaCredentialType::Production);

        $this->assertSame(ZatcaCertificateState::Valid, $state);

        Bus::assertDispatched(RetryZatcaInvoiceJob::class);

        // The invoice itself was NOT touched — same hash/uuid/xml. Resumption never regenerates.
        $invoice->refresh();
        $this->assertSame('HASH', $invoice->invoice_hash);
    }

    private function seedProductionCredential(CarbonImmutable $expiresAt, ?ZatcaCertificateState $initialState = null): void
    {
        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'TOKEN',
            'secret' => 'SECRET',
            'certificate' => 'CERT',
            'certificate_expires_at' => $expiresAt,
            'certificate_state' => $initialState ?? ZatcaCertificateState::Valid,
        ]);
    }
}
