<?php

namespace Zatca\Console\Commands;

use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\State\EgsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Answers one question: "is there anything a human needs to do right now?"
 *
 * Differs from zatca:health-check, which asks "is the installation wired up
 * correctly" (SDK jar, fingerprint, credentials present). This one looks at
 * live operational state — stuck invoices, certificate clock, failed jobs —
 * and exits non-zero when something needs attention, so it can be wired
 * straight into an uptime monitor or a cron that mails on failure.
 */
class ZatcaStatus extends Command
{
    protected $signature = 'zatca:status
        {--stale-minutes=60 : Treat an unreported invoice older than this as stuck}
        {--strict : Exit non-zero on warnings too, not only on failures}
        {--json : Machine-readable output for monitoring}';

    protected $description = 'Show anything that needs human attention: stuck invoices, certificate expiry, failed jobs.';

    /** @var array<int, array{check: string, level: string, detail: string}> */
    private array $results = [];

    public function handle(EgsRegistry $registry): int
    {
        $this->checkManualReview();
        $this->checkStuckInvoices();
        $this->checkCertificates($registry);
        $this->checkFailedJobs();

        $worst = $this->worstLevel();

        // Exit non-zero only on a real failure. A warning is something to plan for
        // (a certificate two months out, an EGS not onboarded yet) and would
        // otherwise page whoever is on call every day for weeks. Use --strict when
        // you do want warnings to break the build/monitor.
        $exitCode = match (true) {
            $worst === 'fail' => self::FAILURE,
            $worst === 'warn' && $this->option('strict') => self::FAILURE,
            default => self::SUCCESS,
        };

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $worst,
                'checked_at' => CarbonImmutable::now()->toIso8601String(),
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->table(
            ['Check', 'Level', 'Detail'],
            array_map(fn (array $row) => [$row['check'], strtoupper($row['level']), $row['detail']], $this->results)
        );

        if ($worst === 'ok') {
            $this->info('Nothing needs attention.');

            return $exitCode;
        }

        if ($worst === 'warn') {
            $this->warn('Nothing is broken, but the rows above need planning.');

            return $exitCode;
        }

        $this->error('Attention required — see the rows above.');
        $this->line('Stuck invoices can be re-queued with: php artisan zatca:requeue --dry-run');

        return $exitCode;
    }

    private function checkManualReview(): void
    {
        if (! Schema::hasTable('zatca_invoices')) {
            $this->record('Invoices', 'warn', 'Table zatca_invoices does not exist — run php artisan migrate.');

            return;
        }

        $count = ZatcaInvoice::query()
            ->where('status', ZatcaInvoiceStatus::ManualReview->value)
            ->count();

        if ($count === 0) {
            $this->record('Manual review', 'ok', 'No invoices waiting for a human.');

            return;
        }

        $oldest = ZatcaInvoice::query()
            ->where('status', ZatcaInvoiceStatus::ManualReview->value)
            ->orderBy('created_at')
            ->first(['id', 'created_at', 'last_error']);

        $this->record('Manual review', 'fail', sprintf(
            '%d invoice(s) stuck. Oldest: #%d from %s — %s',
            $count,
            $oldest->id,
            $oldest->created_at?->diffForHumans() ?? 'unknown',
            str($oldest->last_error ?? 'no error recorded')->limit(80)
        ));
    }

    private function checkStuckInvoices(): void
    {
        if (! Schema::hasTable('zatca_invoices')) {
            return;
        }

        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $cutoff = CarbonImmutable::now()->subMinutes($staleMinutes);

        $count = ZatcaInvoice::query()
            ->whereIn('status', [ZatcaInvoiceStatus::PendingReporting->value, ZatcaInvoiceStatus::Retrying->value])
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($count === 0) {
            $this->record('Reporting queue', 'ok', 'No invoice has been waiting longer than '.$staleMinutes.' minutes.');

            return;
        }

        // A backlog this old is not something to plan for — either the worker is dead
        // or reporting is paused behind an expired certificate. Both need action now.
        $this->record('Reporting queue', 'fail', sprintf(
            '%d invoice(s) unreported for over %d minutes. Check that a worker is running on the "%s" queue and that the certificate is valid.',
            $count,
            $staleMinutes,
            Config::get('zatca.queue.queue', 'zatca')
        ));
    }

    private function checkCertificates(EgsRegistry $registry): void
    {
        if (! Schema::hasTable('zatca_egs_credentials')) {
            $this->record('Certificates', 'warn', 'Table zatca_egs_credentials does not exist — run php artisan migrate.');

            return;
        }

        foreach ($registry->identifiers() as $egs) {
            $environment = $registry->for($egs)->environment;

            foreach ([ZatcaCredentialType::Compliance, ZatcaCredentialType::Production] as $type) {
                $credential = ZatcaEgsCredential::query()
                    ->where('egs_identifier', $egs)
                    ->where('environment', $environment->value)
                    ->where('credential_type', $type->value)
                    ->first();

                $label = "Certificate [{$egs}/{$type->value}]";

                if (! $credential) {
                    $this->record($label, 'warn', 'Not onboarded yet.');

                    continue;
                }

                if (! $credential->certificate_expires_at) {
                    $this->record($label, 'warn', 'No expiry date recorded — expiry cannot be monitored.');

                    continue;
                }

                $days = (int) CarbonImmutable::now()->diffInDays(
                    CarbonImmutable::parse($credential->certificate_expires_at),
                    false
                );

                $state = $credential->certificate_state->value ?? (string) $credential->certificate_state;

                // Fails at 30 days, not 7: renewal needs a human with a portal OTP,
                // so the alert has to arrive while there is still room to schedule it.
                [$level, $detail] = match (true) {
                    $days < 0 => ['fail', "EXPIRED {$this->plural(abs($days))} ago. Reporting is paused. Re-onboard now."],
                    $days <= 30 => ['fail', "Expires in {$this->plural($days)}. Renew now — renewal needs a portal OTP and cannot be automated."],
                    $days <= 60 => ['warn', "Expires in {$this->plural($days)}. Plan the renewal."],
                    default => ['ok', "Valid for {$this->plural($days)} (state: {$state})."],
                };

                $this->record($label, $level, $detail);
            }
        }
    }

    private function checkFailedJobs(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        $queue = Config::get('zatca.queue.queue', 'zatca');

        $count = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('queue', $queue)
            ->count();

        if ($count === 0) {
            $this->record('Failed jobs', 'ok', "No failed jobs on the \"{$queue}\" queue.");

            return;
        }

        $this->record('Failed jobs', 'fail', sprintf(
            '%d failed job(s) on the "%s" queue. Inspect with: php artisan queue:failed',
            $count,
            $queue
        ));
    }

    private function record(string $check, string $level, string $detail): void
    {
        $this->results[] = ['check' => $check, 'level' => $level, 'detail' => $detail];
    }

    private function worstLevel(): string
    {
        foreach (['fail', 'warn'] as $level) {
            foreach ($this->results as $result) {
                if ($result['level'] === $level) {
                    return $level;
                }
            }
        }

        return 'ok';
    }

    private function plural(int $days): string
    {
        return $days === 1 ? '1 day' : "{$days} days";
    }
}
