<?php

namespace Zatca\Tests\Feature;

use Zatca\Tests\TestCase;

/**
 * The command deletes real files, so every assertion here is against the
 * filesystem — the risk it guards is deleting too much, not too little.
 */
class PruneSdkFilesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca');

        $this->deleteTree($this->base);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

        parent::tearDown();
    }

    public function test_it_deletes_old_entries_and_keeps_recent_ones(): void
    {
        $old = $this->makeFile('sdk-output/old.xml', 30);
        $recent = $this->makeFile('sdk-output/recent.xml', 1);

        $this->artisan('zatca:prune-sdk-files', ['--days' => 7])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_it_cleans_every_leftover_location(): void
    {
        $sdkOutput = $this->makeFile('sdk-output/signed.xml', 30);
        $tmp = $this->makeFile('tmp/abc-key.pem', 30);
        $smokeTest = $this->makeFile('smoke-test/sample.xml', 30);
        $csrConfig = $this->makeFile('csr-config-abc.properties', 30);
        $workspace = $this->makeDirectory('runtime/abc-uuid', 30);

        $this->artisan('zatca:prune-sdk-files', ['--days' => 7])->assertSuccessful();

        $this->assertFileDoesNotExist($sdkOutput);
        $this->assertFileDoesNotExist($tmp);
        $this->assertFileDoesNotExist($smokeTest);
        $this->assertFileDoesNotExist($csrConfig);
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->makeFile('sdk-output/old.xml', 30);

        $this->artisan('zatca:prune-sdk-files', ['--days' => 7, '--dry-run' => true])->assertSuccessful();

        $this->assertFileExists($old);
    }

    /**
     * The one-hour floor outranks --days. Without it, `--days=0` would delete the
     * workspace of an SDK call that is still running.
     */
    public function test_it_never_deletes_entries_younger_than_one_hour_even_with_zero_days(): void
    {
        $inFlight = $this->makeDirectory('runtime/in-flight', 0, minutesAgo: 10);
        $ancient = $this->makeFile('sdk-output/ancient.xml', 2);

        $this->artisan('zatca:prune-sdk-files', ['--days' => 0])->assertSuccessful();

        $this->assertDirectoryExists($inFlight);
        $this->assertFileDoesNotExist($ancient);
    }

    public function test_it_falls_back_to_the_configured_retention_window(): void
    {
        config(['zatca.sdk_files.prune_after_days' => 90]);

        $withinWindow = $this->makeFile('sdk-output/within.xml', 30);
        $beyondWindow = $this->makeFile('sdk-output/beyond.xml', 120);

        $this->artisan('zatca:prune-sdk-files')->assertSuccessful();

        $this->assertFileExists($withinWindow);
        $this->assertFileDoesNotExist($beyondWindow);
    }

    public function test_it_rejects_a_negative_window(): void
    {
        $this->artisan('zatca:prune-sdk-files', ['--days' => -1])->assertFailed();
    }

    public function test_it_succeeds_when_no_zatca_storage_exists_yet(): void
    {
        $this->artisan('zatca:prune-sdk-files')->assertSuccessful();
    }

    private function makeFile(string $relativePath, int $daysAgo, int $minutesAgo = 0): string
    {
        $path = $this->base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, 'contents');
        touch($path, $this->timestamp($daysAgo, $minutesAgo));

        return $path;
    }

    private function makeDirectory(string $relativePath, int $daysAgo, int $minutesAgo = 0): string
    {
        $path = $this->base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        mkdir($path.DIRECTORY_SEPARATOR.'Data', 0700, true);
        file_put_contents($path.DIRECTORY_SEPARATOR.'Data'.DIRECTORY_SEPARATOR.'cert.pem', 'x');
        touch($path, $this->timestamp($daysAgo, $minutesAgo));

        return $path;
    }

    private function timestamp(int $daysAgo, int $minutesAgo): int
    {
        return now()->getTimestamp() - ($daysAgo * 86400) - ($minutesAgo * 60);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;

            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
