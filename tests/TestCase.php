<?php

namespace Asas\Zatca\Tests;

use Asas\Zatca\ZatcaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base class for the package's OWN standalone test suite — runs the
 * package against a throwaway Orchestra Testbench Laravel app + in-memory
 * SQLite DB, with no dependency on the host application at all. This is
 * what proves the package works on its own, before it's ever installed
 * into a real app.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('zatca.egs', [
            'default' => [
                'environment' => 'sandbox',
                'vat_number' => '399999999900003',
                'organization_name' => 'Test Org',
                'organization_unit_name' => 'Test Unit',
                'common_name' => 'TST-EGS-01',
                'serial_number' => '1-TST|2-ASAS|3-uuid',
                'organization_identifier' => '399999999900003',
                'country_code' => 'SA',
                'invoice_type' => '0100',
                'location_address' => 'Test Address',
                'business_category' => 'Education',
                'commercial_registration_number' => '1010010000',
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
