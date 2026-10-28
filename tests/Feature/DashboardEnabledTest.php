<?php

namespace Zatca\Tests\Feature;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Dashboard\Http\Middleware\EnsureDashboardIsAllowed;
use Zatca\Dashboard\Demo\DemoInvoiceSource;
use Zatca\Dashboard\Demo\DemoSeeder;
use Zatca\Dashboard\Demo\ScenarioRunner;
use Zatca\Tests\TestCase;

class DashboardEnabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('zatca.dashboard.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/dashboard-migrations');
    }

    public function test_overview_and_pages_render_without_credentials(): void
    {
        $this->get('/zatca-dashboard')->assertOk()->assertSee('Run a scenario');
        $this->get('/zatca-dashboard/onboarding')->assertOk()->assertSee('Seller configuration');
        $this->get('/zatca-dashboard/invoices')->assertOk()->assertSee('Invoice history');
    }

    public function test_refused_in_a_production_app(): void
    {
        $this->app['env'] = 'production';

        try {
            (new EnsureDashboardIsAllowed)->handle(Request::create('/zatca-dashboard'), fn () => response('ok'));
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_refused_when_an_egs_is_in_production(): void
    {
        config(['zatca.egs.default.environment' => 'production']);

        $this->get('/zatca-dashboard')->assertForbidden();
    }

    public function test_unknown_scenario_and_onboarding_step_are_404(): void
    {
        $this->post('/zatca-dashboard/scenarios/nope')->assertNotFound();
        $this->post('/zatca-dashboard/onboarding/default/nope')->assertNotFound();
        $this->post('/zatca-dashboard/onboarding/unknown-egs/generate')->assertNotFound();
    }

    public function test_demo_source_is_bound_and_seeder_is_idempotent(): void
    {
        $this->assertInstanceOf(DemoInvoiceSource::class, app(InvoiceSourceContract::class));

        DemoSeeder::seed();
        DemoSeeder::seed();

        $this->assertSame(4, \Zatca\Dashboard\Demo\DemoCustomer::count());
        $this->assertSame(6, \Zatca\Dashboard\Demo\DemoProduct::count());
    }

    public function test_demo_orders_use_inclusive_prices_for_b2c_and_exclusive_for_b2b(): void
    {
        DemoSeeder::seed();
        $runner = new ScenarioRunner;

        $b2c = $runner->createOrder('b2c');
        $b2b = $runner->createOrder('b2b');

        $input = app(InvoiceSourceContract::class);
        $this->assertSame(\Zatca\Enums\VatPriceMode::Inclusive, $input->toInvoiceInput($b2c, 'default')->priceMode);
        $this->assertSame(\Zatca\Enums\VatPriceMode::Exclusive, $input->toInvoiceInput($b2b, 'default')->priceMode);
        $this->assertSame('DEMO-'.$b2c->id, $input->sourceReference($b2c));
    }
}
