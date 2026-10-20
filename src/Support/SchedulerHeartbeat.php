<?php

namespace Zatca\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Answers "is the Laravel scheduler actually running the ZATCA retry sweep?".
 *
 * Retries of unreported invoices are driven entirely by `zatca:report-pending` on the
 * host's scheduler. If the cron line is missing, everything works until the first ZATCA
 * hiccup and then invoices quietly stop being retried. The sweep records the time of each
 * run here, and `zatca:status` / `zatca:health-check` report when that time is too old.
 *
 * Deliberately only a timestamp in the host's cache: no table, no service. It needs a
 * cache store that survives between processes (file, database, redis, memcached); with the
 * `array` or `null` store the scheduler's last run can never be seen from another process,
 * which is reported as "cannot verify" rather than as a failure.
 *
 * Package vs host: the package records the run and reports a stale or missing one. Installing
 * the cron entry, keeping the queue worker alive, and wiring an alert to `zatca:status` stay
 * the host's job.
 */
class SchedulerHeartbeat
{
    public const STATE_OK = 'ok';

    public const STATE_NEVER = 'never';

    public const STATE_STALE = 'stale';

    public const STATE_UNVERIFIABLE = 'unverifiable';

    private const KEY = 'zatca:scheduler:last-report-pending-run';

    /** Called by zatca:report-pending on every run. A cache outage must never stop the sweep. */
    public function record(): void
    {
        try {
            Cache::forever(self::KEY, CarbonImmutable::now()->toIso8601String());
        } catch (\Throwable) {
            // Monitoring is best-effort; retrying invoices is not.
        }
    }

    public function canVerify(): bool
    {
        try {
            $store = Cache::getStore();
        } catch (\Throwable) {
            return false;
        }

        return ! ($store instanceof ArrayStore || $store instanceof NullStore);
    }

    public function lastRun(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function staleAfterMinutes(): int
    {
        return max(1, (int) Config::get('zatca.scheduler.stale_after_minutes', 30));
    }

    /** @return array{state: string, detail: string} */
    public function status(): array
    {
        if (! $this->canVerify()) {
            return [
                'state' => self::STATE_UNVERIFIABLE,
                'detail' => 'Cannot verify: the "'.Config::get('cache.default').'" cache store does not persist between processes. Use file, database, redis or memcached as the cache store to monitor the scheduler.',
            ];
        }

        $lastRun = $this->lastRun();

        if ($lastRun === null) {
            return [
                'state' => self::STATE_NEVER,
                'detail' => 'zatca:report-pending has not run yet. Add the scheduler cron entry and the ZATCA schedule (see the README, "Server Setup").',
            ];
        }

        $minutes = (int) $lastRun->diffInMinutes(CarbonImmutable::now());

        if ($minutes > $this->staleAfterMinutes()) {
            return [
                'state' => self::STATE_STALE,
                'detail' => "zatca:report-pending last ran {$lastRun->diffForHumans()}, but it should run every 5 minutes (alert after {$this->staleAfterMinutes()}). Failed submissions are not being retried. Check that the cron entry runs `php artisan schedule:run` every minute.",
            ];
        }

        return [
            'state' => self::STATE_OK,
            'detail' => "zatca:report-pending last ran {$lastRun->diffForHumans()}.",
        ];
    }
}
