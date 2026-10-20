<?php

namespace Zatca\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Zatca\Tests\TestCase;

/**
 * zatca:sdk-smoke-test is the one consumer that validates a signed file, so it must keep
 * working now that the SDK adapter returns the signed document in memory only.
 *
 * Opt-in like the other real-SDK tests: runs only with ZATCA_SDK_EXECUTABLE and
 * ZATCA_SDK_ROOT pointing at an SDK install.
 */
class SdkSmokeTestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $executable = env('ZATCA_SDK_EXECUTABLE');
        $root = env('ZATCA_SDK_ROOT');

        if (! $executable || ! is_file($executable) || ! $root || ! is_dir($root)) {
            $this->markTestSkipped('ZATCA SDK not configured in this environment (set ZATCA_SDK_EXECUTABLE and ZATCA_SDK_ROOT to run).');
        }

        Config::set('zatca.sdk.executable', $executable);
        Config::set('zatca.sdk.root', $root);
    }

    public function test_the_smoke_test_passes_against_the_real_sdk(): void
    {
        // Generates a CSR, hashes, signs and validates a sample invoice through the real adapter.
        $this->artisan('zatca:sdk-smoke-test')->assertSuccessful();
    }
}
