<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\CredentialEncrypterInterface;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\Contracts\OwnerResolverInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Services\Security\ZatcaSanitizer;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\State\EgsRegistry;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Proves the package boots and wires all its bindings on a bare Laravel app — not just inside the host. */
class ServiceProviderTest extends TestCase
{
    /** @dataProvider bindingsProvider */
    #[DataProvider('bindingsProvider')]
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
