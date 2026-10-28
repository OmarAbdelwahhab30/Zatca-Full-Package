<?php

namespace Zatca;

use Zatca\Console\Commands\CheckCertificate;
use Zatca\Console\Commands\CheckSourceUniqueness;
use Zatca\Console\Commands\GenerateCredentials;
use Zatca\Console\Commands\InspectZatca;
use Zatca\Console\Commands\Onboard;
use Zatca\Console\Commands\PruneSdkFiles;
use Zatca\Console\Commands\PruneSubmissionAttempts;
use Zatca\Console\Commands\RecoverZatcaInvoice;
use Zatca\Console\Commands\RenewCertificate;
use Zatca\Console\Commands\ReportPendingInvoices;
use Zatca\Console\Commands\RequeueInvoices;
use Zatca\Console\Commands\SdkSmokeTest;
use Zatca\Console\Commands\ZatcaHealthCheck;
use Zatca\Console\Commands\ZatcaStatus;
use Illuminate\Support\Facades\Route;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Dashboard\Demo\DemoInvoiceSource;
use Zatca\Dashboard\Http\Middleware\EnsureDashboardIsAllowed;
use Zatca\Contracts\CredentialEncrypterInterface;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\Contracts\OwnerResolverInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Invoice\EloquentInvoiceSourceResolver;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Zatca\Services\SDK\ZatcaSdkAdapter;
use Zatca\Services\SDK\ZatcaSdkFingerprint;
use Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Zatca\Services\Security\LaravelCredentialEncrypter;
use Zatca\Services\Security\NullOwnerResolver;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Services\Security\ZatcaSanitizer;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\State\ComplianceInvoiceCounterManager;
use Zatca\State\CompliancePreviousInvoiceHashManager;
use Zatca\State\EgsRegistry;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\State\ProductionPreviousInvoiceHashManager;
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\Support\VatSettings;
use Zatca\Support\ZatcaGate;
use Illuminate\Support\ServiceProvider;

class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zatca.php', 'zatca');

        // The global kill switch. Resolved by jobs, commands, the orchestrator and the
        // host's admin service — every ZATCA entry point asks this one object.
        $this->app->singleton(ZatcaGate::class);

        // VAT: read from config('zatca.vat') at the moment of use. The calculator is bound
        // explicitly so the container never autowires it with a default (15%, inclusive)
        // VatSettings that would ignore the config.
        $this->app->bind(VatSettings::class, fn () => VatSettings::fromConfig());
        $this->app->bind(InvoiceCalculator::class, fn () => new InvoiceCalculator);

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

    /**
     * The optional dashboard: nothing is registered (no routes, views, migrations or
     * bindings) unless ZATCA_DASHBOARD_ENABLED is true.
     */
    private function bootDashboard(): void
    {
        $this->publishes([
            __DIR__.'/../resources/views/dashboard' => resource_path('views/vendor/zatca-dashboard'),
        ], 'zatca-dashboard-views');

        if (! config('zatca.dashboard.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/dashboard-migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views/dashboard', 'zatca-dashboard');

        Route::middleware(array_merge(
            (array) config('zatca.dashboard.middleware', ['web']),
            [EnsureDashboardIsAllowed::class],
        ))
            ->prefix(trim((string) config('zatca.dashboard.path', 'zatca-dashboard'), '/'))
            ->name('zatca.dashboard.')
            ->group(__DIR__.'/../routes/dashboard.php');

        // Let the demo orders be mapped without taking over the host app's own mapper:
        // anything that is not a DemoOrder is delegated to the binding the app already had.
        $previous = $this->app->bound(InvoiceSourceContract::class)
            ? $this->app->getBindings()[InvoiceSourceContract::class]['concrete'] ?? null
            : null;

        $this->app->bind(InvoiceSourceContract::class, function ($app) use ($previous) {
            $fallback = match (true) {
                $previous instanceof \Closure => $previous($app),
                is_string($previous) && $previous !== DemoInvoiceSource::class => $app->build($previous),
                default => null,
            };

            return new DemoInvoiceSource($fallback);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/zatca.php' => config_path('zatca.php'),
        ], 'zatca-config');

        $this->bootDashboard();

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCredentials::class,
                Onboard::class,
                ReportPendingInvoices::class,
                InspectZatca::class,
                ZatcaHealthCheck::class,
                ZatcaStatus::class,
                RequeueInvoices::class,
                CheckCertificate::class,
                RenewCertificate::class,
                PruneSubmissionAttempts::class,
                PruneSdkFiles::class,
                SdkSmokeTest::class,
                CheckSourceUniqueness::class,
                RecoverZatcaInvoice::class,
            ]);
        }
    }
}
