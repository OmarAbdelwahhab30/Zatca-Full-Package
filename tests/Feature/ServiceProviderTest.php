<?php

namespace Asas\Zatca\Tests\Feature;

use Asas\Zatca\Contracts\CredentialEncrypterInterface;
use Asas\Zatca\Contracts\InvoiceSourceResolverContract;
use Asas\Zatca\Contracts\OwnerResolverInterface;
use Asas\Zatca\Contracts\ZatcaSdkInterface;
use Asas\Zatca\Services\Certificate\CertificateLifecycleManager;
use Asas\Zatca\Services\Connectors\ConnectorFactory;
use Asas\Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Asas\Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Asas\Zatca\Services\Security\ZatcaCredentialStore;
use Asas\Zatca\Services\Security\ZatcaSanitizer;
use Asas\Zatca\Services\Security\ZatcaSigningKeyProvider;
use Asas\Zatca\State\EgsRegistry;
use Asas\Zatca\Tests\TestCase;

/** Proves the package boots and wires all its bindings on a bare Laravel app — not just inside the host. */
class ServiceProviderTest extends TestCase
{
    /** @dataProvider bindingsProvider */
    public function test_it_resolves_every_core_binding(string $abstract): void
    {
        $this->assertInstanceOf($abstract, $this->app->make($abstract));
    }

    public static function bindingsProvider(): array
    {
        return [
            [EgsRegistry::class],
            [ZatcaCredentialStore::class],
            [ZatcaSigningKeyProvider::class],
            [ZatcaSanitizer::class],
            [ConnectorFactory::class],
            [ComplianceOnboardingOrchestrator::class],
            [ProductionInvoiceOrchestrator::class],
            [CertificateLifecycleManager::class],
            [CredentialEncrypterInterface::class],
            [OwnerResolverInterface::class],
            [InvoiceSourceResolverContract::class],
            [ZatcaSdkInterface::class],
        ];
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('V2', config('zatca.api_version'));
        $this->assertIsArray(config('zatca.egs'));
    }
}
