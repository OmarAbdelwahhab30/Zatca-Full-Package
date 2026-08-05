<?php

namespace Zatca\Tests\Feature;

use Zatca\Tests\TestCase;

/** Proves every documented artisan command is actually registered by the package. */
class CommandsTest extends TestCase
{
    /** @dataProvider commandProvider */
    public function test_command_is_registered(string $command): void
    {
        $this->assertArrayHasKey($command, $this->app[\Illuminate\Contracts\Console\Kernel::class]->all());
    }

    public static function commandProvider(): array
    {
        return [
            ['zatca:generate-credentials'],
            ['zatca:onboard'],
            ['zatca:report-pending'],
            ['zatca:inspect'],
            ['zatca:health-check'],
            ['zatca:certificate:check'],
            ['zatca:certificate:renew'],
            ['zatca:prune-submission-attempts'],
            ['zatca:prune-sdk-files'],
            ['zatca:sdk-smoke-test'],
        ];
    }
}
