<?php

namespace Zatca\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Deletes leftover files under storage/app/private/zatca.
 *
 * Nothing in the package reads any of these once a call has finished: the
 * authoritative signed invoice lives in zatca_invoices.signed_xml_base64
 * (never pruned), so removing them can never lose an invoice or break a
 * retry — RetryZatcaInvoiceJob re-sends the stored column, not a file.
 *
 * Two kinds of leftovers exist:
 *  - sdk-output/ — signed-XML copies the adapter persists out of a workspace
 *    and then abandons; only zatca:sdk-smoke-test cleans up after itself.
 *  - runtime/, tmp/, csr-config-*.properties — normally deleted in a `finally`
 *    block, but a killed process (OOM, reboot, timeout) leaves them behind.
 *    Those contain certificate and private-key material, so clearing them is a
 *    security concern, not just a disk-space one.
 *
 * Never deletes anything younger than MINIMUM_AGE_SECONDS regardless of
 * --days, because an SDK call in flight is actively using its workspace.
 */
class PruneSdkFiles extends Command
{
    protected $signature = 'zatca:prune-sdk-files
        {--days= : Delete entries older than this many days (default: config zatca.sdk_files.prune_after_days)}
        {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Prune leftover SDK workspaces and output files from storage/app/private/zatca.';

    /** Safety floor: an in-flight SDK call must never have its workspace pulled out from under it. */
    private const MINIMUM_AGE_SECONDS = 3600;

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) Config::get('zatca.sdk_files.prune_after_days', 7);

        if ($days < 0) {
            $this->error('--days must be 0 or greater.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // The floor wins whenever it is stricter than --days (e.g. --days=0).
        $cutoff = min(now()->getTimestamp() - ($days * 86400), now()->getTimestamp() - self::MINIMUM_AGE_SECONDS);

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be deleted.');
        }

        $rows = [];
        $totalCount = 0;
        $totalBytes = 0;

        foreach ($this->targets() as $label => $target) {
            [$count, $bytes] = $this->prune($target, $cutoff, $dryRun);

            $rows[] = [$label, $count, $this->formatBytes($bytes)];
            $totalCount += $count;
            $totalBytes += $bytes;
        }

        $this->table(['Location', $dryRun ? 'Would delete' : 'Deleted', 'Size'], $rows);

        $verb = $dryRun ? 'Would free' : 'Freed';
        $this->info("{$verb} {$this->formatBytes($totalBytes)} across {$totalCount} entr(ies) older than {$days} day(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{path: string, mode: string, pattern?: string}>
     */
    private function targets(): array
    {
        $base = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca');

        return [
            'sdk-output' => ['path' => $base.DIRECTORY_SEPARATOR.'sdk-output', 'mode' => 'files'],
            'tmp' => ['path' => $base.DIRECTORY_SEPARATOR.'tmp', 'mode' => 'files'],
            'smoke-test' => ['path' => $base.DIRECTORY_SEPARATOR.'smoke-test', 'mode' => 'files'],
            'runtime (orphaned workspaces)' => ['path' => $base.DIRECTORY_SEPARATOR.'runtime', 'mode' => 'dirs'],
            'csr-config-*.properties' => ['path' => $base, 'mode' => 'glob', 'pattern' => 'csr-config-*.properties'],
        ];
    }

    /**
     * @param  array{path: string, mode: string, pattern?: string}  $target
     * @return array{0: int, 1: int} count and bytes
     */
    private function prune(array $target, int $cutoff, bool $dryRun): array
    {
        if (! is_dir($target['path'])) {
            return [0, 0];
        }

        $count = 0;
        $bytes = 0;

        foreach ($this->entries($target) as $entry) {
            $modifiedAt = @filemtime($entry);

            if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                continue;
            }

            $size = is_dir($entry) ? $this->directorySize($entry) : (@filesize($entry) ?: 0);

            if (! $dryRun && ! $this->delete($entry)) {
                $this->warn("Could not delete [{$entry}] — check permissions.");

                continue;
            }

            $count++;
            $bytes += $size;
        }

        return [$count, $bytes];
    }

    /**
     * @param  array{path: string, mode: string, pattern?: string}  $target
     * @return array<string>
     */
    private function entries(array $target): array
    {
        if ($target['mode'] === 'glob') {
            return glob($target['path'].DIRECTORY_SEPARATOR.$target['pattern']) ?: [];
        }

        $entries = glob($target['path'].DIRECTORY_SEPARATOR.'*') ?: [];

        return array_values(array_filter(
            $entries,
            fn (string $entry) => $target['mode'] === 'dirs' ? is_dir($entry) : is_file($entry)
        ));
    }

    private function delete(string $entry): bool
    {
        return is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
    }

    private function deleteDirectory(string $dir): bool
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        return @rmdir($dir);
    }

    private function directorySize(string $dir): int
    {
        $bytes = 0;

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;

            $bytes += is_dir($path) ? $this->directorySize($path) : (@filesize($path) ?: 0);
        }

        return $bytes;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 1).' '.$units[$unit];
    }
}
