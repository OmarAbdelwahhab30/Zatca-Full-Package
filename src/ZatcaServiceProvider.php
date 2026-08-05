<?php

namespace Asas\Zatca;

use Asas\Zatca\Console\Commands\CheckCertificate;
use Asas\Zatca\Console\Commands\GenerateCredentials;
use Asas\Zatca\Console\Commands\InspectZatca;
use Asas\Zatca\Console\Commands\Onboard;
use Asas\Zatca\Console\Commands\PruneSubmissionAttempts;
use Asas\Zatca\Console\Commands\RenewCertificate;
use Asas\Zatca\Console\Commands\ReportPendingInvoices;
use Asas\Zatca\Console\Commands\SdkSmokeTest;
use Asas\Zatca\Console\Commands\ZatcaHealthCheck;
use Asas\Zatca\Contracts\CredentialEncrypterInterface;
use Asas\Zatca\Contracts\InvoiceSourceResolverContract;
use Asas\Zatca\Contracts\OwnerResolverInterface;
use Asas\Zatca\Contracts\ZatcaSdkInterface;
use Asas\Zatca\Services\Certificate\CertificateLifecycleManager;
use Asas\Zatca\Services\Connectors\ConnectorFactory;
use Asas\Zatca\Services\Diagnostics\ZatcaInspector;
use Asas\Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Asas\Zatca\Services\Invoice\EloquentInvoiceSourceResolver;
use Asas\Zatca\Services\Invoice\XmlGeneratorFactory;
use Asas\Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Asas\Zatca\Services\SDK\ZatcaSdkAdapter;
use Asas\Zatca\Services\SDK\ZatcaSdkFingerprint;
use Asas\Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Asas\Zatca\Services\Security\LaravelCredentialEncrypter;
use Asas\Zatca\Services\Security\NullOwnerResolver;
use Asas\Zatca\Services\Security\ZatcaCredentialStore;
use Asas\Zatca\Services\Security\ZatcaSanitizer;
use Asas\Zatca\Services\Security\ZatcaSigningKeyProvider;
use Asas\Zatca\State\ComplianceInvoiceCounterManager;
use Asas\Zatca\State\CompliancePreviousInvoiceHashManager;
use Asas\Zatca\State\EgsRegistry;
use Asas\Zatca\State\ProductionInvoiceCounterManager;
use Asas\Zatca\State\ProductionPreviousInvoiceHashManager;
use Illuminate\Support\ServiceProvider;

class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zatca.php', 'zatca');

        $this->app->singleton(EgsRegistry::class);

        $this->app->bind(CredentialEncrypterInterface::class, LaravelCredentialEncrypter::class);
        $this->app->bind(OwnerResolverInterface::class, NullOwnerResolver::class);
        $this->app->bind(InvoiceSourceResolverContract::class, EloquentInvoiceSourceResolver::class);

        $this->app->singleton(ZatcaCredentialStore::class);
        $this->app->singleton(ZatcaSigningKeyProvider::class);
        $this->app->singleton(ZatcaSanitizer::class);
        $this->app->singleton(ComplianceInvoiceCounterManager::class);
        $this->app->singleton(ProductionInvoiceCounterManager::class);
        $this->app->singleton(CompliancePreviousInvoiceHashManager::class);
        $this->app->singleton(ProductionPreviousInvoiceHashManager::class);

        $this->app->singleton(ZatcaSdkRuntimeManager::class);
        $this->app->singleton(ZatcaSdkFingerprint::class);
        $this->app->bind(ZatcaSdkInterface::class, ZatcaSdkAdapter::class);

        $this->app->singleton(ConnectorFactory::class);
        $this->app->singleton(XmlGeneratorFactory::class);
        $this->app->singleton(ComplianceOnboardingOrchestrator::class);
        $this->app->singleton(ProductionInvoiceOrchestrator::class);

        $this->app->singleton(ZatcaInspector::class);
        $this->app->singleton(CertificateLifecycleManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/zatca.php' => config_path('zatca.php'),
        ], 'zatca-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCredentials::class,
                Onboard::class,
                ReportPendingInvoices::class,
                InspectZatca::class,
                ZatcaHealthCheck::class,
                CheckCertificate::class,
                RenewCertificate::class,
                PruneSubmissionAttempts::class,
                SdkSmokeTest::class,
            ]);
        }
    }
}
