# Server setup and deployment

Prepare a Linux server to generate, sign and submit ZATCA documents. This guide assumes your Laravel app already runs and has a working database. Examples use Debian/Ubuntu, a **database queue**, and either Supervisor or systemd.

Replace `/var/www/your-app` with your app's absolute path and `deploy` with its worker account throughout. Run application commands from the app root. On Windows, use equivalent permissions and process management; the Linux service files do not apply.

## Setup order

1. [Check requirements](#1-requirements).
2. [Choose the operating account and permissions](#2-operating-account-and-permissions).
3. [Install and configure the package](#3-install-and-configure).
4. [Install Java and locate the SDK](#4-java-and-sdk).
5. [Check and onboard](#5-check-and-onboard).
6. [Keep the worker running](#6-persistent-worker).
7. [Configure the scheduler and alerts](#7-scheduler-and-alerts).
8. [Deploy and verify](#8-deployment).

## 1. Requirements

| Component | Requirement |
|---|---|
| Laravel / PHP | Repository recommendation: Laravel 12 with PHP 8.2+. Legacy Laravel 9–10 require PHP 8.1+; Laravel 11 requires PHP 8.2+. |
| PHP extensions | `openssl`, `dom`, `mbstring`, `ctype`, `json`, your PDO driver; enable CLI `pcntl` on Linux for worker timeout/signal handling. |
| Java / SDK | Java **11** and bundled ZATCA Java SDK **3.4.8** (`238-R3.4.8`). |
| Queue | Persistent queue connection, queue tables if using `database`, and a continuously running worker. Avoid `sync` in production. |
| Scheduler / cache | Cron every minute, registered Laravel scheduled commands and a cache that persists between processes. |
| Network | Outbound HTTPS to `gw-fatoora.zatca.gov.sa` on port 443. |
| Storage | Private signing directory plus normal Laravel write access to logs, `storage/` and `bootstrap/cache`. |

### Laravel compatibility

Technical compatibility differs from framework security support. The repository records tested Laravel 9–12 combinations. See the [version matrix](COMPATIBILITY.md#3-laravel-and-php) and [legacy security/install guidance](COMPATIBILITY.md#legacy-laravel-installation-and-security-record). Run `composer audit` in your app; do not disable security blocking just to force installation.

## 2. Operating account and permissions

Use the same application account for the worker, scheduler and manual ZATCA commands where possible. This also allows cleanup to remove private files left by that worker. Do not assume the account is `www-data`.

| Process | Access it needs |
|---|---|
| Worker and manual signing/onboarding CLI | CLI PHP, Java, SDK read access, private-storage write access, database/cache/log access. |
| Scheduler | Laravel CLI/database/cache/log access and permission to clean signing files. No Java needed for the asynchronous schedule below. |
| Web server | Normal Laravel permissions. Signing access only if you deliberately use a synchronous queue. |

For an existing worker, find its account in Supervisor's `user=` or systemd's `User=`. `whoami` shows your current CLI account. Keep any separate PHP-FPM user's existing Laravel permissions intact.

### Private signing directory

For the example `deploy` account:

```bash
cd /var/www/your-app
sudo mkdir -p storage/app/private/zatca
sudo chown deploy storage/app/private/zatca
sudo chmod 700 storage/app/private/zatca
sudo -u deploy -- test -w storage/app/private/zatca
```

The last command must exit successfully. This directory temporarily contains keys/certificates. Keep it outside `public/`; never use `chmod 777` or recursively change the whole `storage/` tree for ZATCA.

Run manual commands as the same account, for example:

```bash
sudo -u deploy -- /usr/bin/php artisan zatca:health-check
```

If separate worker/CLI accounts are necessary, use the [shared-group directory setup](../README_SIMPLE.md#storage-permissions). Restart/relogin after group changes. Workspaces are still private to their creating process's user: run cleanup as their owner or arrange explicit authorized access. Do not recursively make existing key files group-readable or executable.

## 3. Install and configure

Configure your private Composer registry/VCS source first, then run as the application/deployment account:

```bash
cd /var/www/your-app
composer require zatca/zatca
php artisan vendor:publish --tag=zatca-config
php artisan migrate
```

Laravel discovers the provider and loads migrations automatically. Do not publish migrations. For an **existing install**, run `php artisan zatca:check-source-uniqueness` before upgrade migrations and resolve duplicates first.

| Package tables | Contents |
|---|---|
| `zatca_egs_credentials` | Encrypted keys, CSRs and certificates per EGS/environment. |
| `zatca_egs_states` | Counters and previous hashes per EGS/environment. |
| `zatca_invoices` | Invoices and notes: snapshots, XML, QR and status. |
| `zatca_submission_attempts` | Submission attempts and sanitized responses. |
| `zatca_compliance_documents` | Onboarding sample documents. |
| `zatca_credit_notes`, `zatca_credit_note_lines`, `zatca_credit_note_events` | Credit-note business records and audit history. |
| `zatca_debit_notes`, `zatca_debit_note_lines`, `zatca_debit_note_events` | Debit-note business records and audit history. |

Fill in seller/device details using [configuration](../README_SIMPLE.md#configure-env) and [where each value comes from](../README_SIMPLE.md#where-do-these-values-come-from). Include these settings for the database queue:

```dotenv
QUEUE_CONNECTION=database
ZATCA_QUEUE_CONNECTION=database
ZATCA_QUEUE_NAME=zatca
ZATCA_SDK_PROCESS_TIMEOUT=60
ZATCA_TIMEOUT=30
```

Ensure Laravel's queue tables exist and have been migrated. Use a persistent cache for heartbeat/restart signals, not `array`/`null`; recovery and scheduling also need suitable cache locks. See [all configuration options](CONFIGURATION.md#all-other-options).

The worker below uses `--timeout=300`. Set the database connection's `retry_after` in your app's `config/queue.php` **higher**, for example `360` seconds. The worker timeout must allow the entire job to finish, including SDK calls, and stay below `retry_after` to prevent another worker picking it up while still running. Review both values when changing SDK/HTTP limits or workloads.

## 4. Java and SDK

Use Java 11, the version used for the recorded SDK checks. Install it using your OS packages; on Debian/Ubuntu releases that provide it:

```bash
sudo apt-get update
sudo apt-get install -y openjdk-11-jre-headless
sudo -u deploy -- java -version
```

Java must be on the **worker account's** `PATH`, not only your SSH user's. The SDK already ships with the installed package. Add these absolute paths to `.env`:

```dotenv
ZATCA_SDK_ROOT=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
ZATCA_SDK_EXECUTABLE=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_VERSION=3.4.8
```

No SDK installer is needed. Keep the complete `Apps/`, `Configuration/` and `Data/` folders readable/traversable by the worker. The package writes into Laravel private storage, so the SDK needs no application write access. Its bundled sample key/certificate are for local smoke tests only.

For terminal checks, explicitly define a shell variable: Laravel's `.env` does **not** define shell variables automatically.

```bash
SDK_ROOT=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
sudo -u deploy -- ls "$SDK_ROOT/Apps" "$SDK_ROOT/Configuration" "$SDK_ROOT/Data"
sudo -u deploy -- test -r "$SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar"
sha256sum "$SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar"
```

Compare the hash with the [bundled SDK checksum](../SDKs/README.md), then save the trusted value as `ZATCA_SDK_FINGERPRINT`. Investigate unexpected changes before accepting a new hash. **Do not run `zatca:generate-credentials` just to inspect the SDK:** it replaces your stored key and CSR.

If releases change the app's absolute path, point settings at the current release or copy the **whole SDK folder** to a stable path such as `/opt/zatca-sdk` and update both paths. Keep it outside `public/`. Composer may replace the bundled `vendor/` copy; you maintain a separate copy yourself. SDK upgrades require the [compatibility procedure](COMPATIBILITY.md#adding-a-new-zatca-version-combination).

## 5. Check and onboard

From the app root, as the operating account:

```bash
php artisan config:clear
php artisan zatca:health-check
php artisan zatca:sdk-smoke-test
```

Missing credentials are expected before onboarding. The smoke test makes no ZATCA calls. Require all nine checks (Java, SDK paths, fingerprint computation, CSR, hash, signature, QR, validation) to show **`[OK]`**. Skipped SDK checks may still exit successfully. `health-check` separately verifies the configured fingerprint.

Complete [onboarding](../README_SIMPLE.md#onboard-with-zatca) per EGS/environment using the matching portal's OTP. Creating keys is a deliberate onboarding action, not a routine deployment step. Review the [simulation verification gaps](LIMITATIONS.md#known-limitations) before production.

After changing `.env`, refresh config and restart running workers:

```bash
php artisan config:cache
php artisan queue:restart
```

## 6. Persistent worker

Choose **one** process manager. These examples explicitly use connection `database` and queue `zatca`; change them to match your config. Replace `/usr/bin/php` if your CLI PHP binary is elsewhere.

### Supervisor

Install with `sudo apt-get install -y supervisor`, then create `/etc/supervisor/conf.d/zatca-worker.conf`:

```ini
[program:zatca-worker]
directory=/var/www/your-app
command=/usr/bin/php /var/www/your-app/artisan queue:work database --queue=zatca --sleep=3 --timeout=300 --max-time=3600
user=deploy
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/your-app/storage/logs/zatca-worker.log
```

```bash
sudo systemctl enable --now supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status zatca-worker
```

Expect `RUNNING`. If it fails/restarts repeatedly, read the configured log file. The worker account must be able to write that log.

### systemd alternative

Create `/etc/systemd/system/zatca-worker.service`:

```ini
[Unit]
Description=ZATCA queue worker
After=network.target

[Service]
User=deploy
WorkingDirectory=/var/www/your-app
ExecStart=/usr/bin/php /var/www/your-app/artisan queue:work database --queue=zatca --sleep=3 --timeout=300 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now zatca-worker
sudo systemctl status zatca-worker
```

Read logs with `journalctl -u zatca-worker`. Start with one worker. Both examples restart after the one-hour `--max-time` and allow running jobs time to finish during shutdown.

## 7. Scheduler and alerts

The worker executes queued jobs; cron triggers scheduled sweeps, certificate checks and cleanup. **Both must run.** The package does not install cron or start workers for you.

### Laravel 11/12

Add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('zatca:report-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('zatca:requeue --only-transient')->hourly()->withoutOverlapping();
Schedule::command('zatca:certificate:check')->daily();
Schedule::command('zatca:status')->hourly();
Schedule::command('zatca:prune-submission-attempts')->weekly();
Schedule::command('zatca:prune-sdk-files')->daily();
```

### Laravel 9/10

Inside `app/Console/Kernel.php`:

```php
protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
{
    $schedule->command('zatca:report-pending')->everyFiveMinutes()->withoutOverlapping();
    $schedule->command('zatca:requeue --only-transient')->hourly()->withoutOverlapping();
    $schedule->command('zatca:certificate:check')->daily();
    $schedule->command('zatca:status')->hourly();
    $schedule->command('zatca:prune-submission-attempts')->weekly();
    $schedule->command('zatca:prune-sdk-files')->daily();
}
```

### B2B outage recovery

`zatca:report-pending` covers **both Reporting and Clearance**, including `clearance_in_doubt`. It excludes manual-review/rejected documents and unsigned drafts.

The hourly requeue defaults to B2C `manual_review`. If operating B2B, also add:

```php
// Laravel 11/12; use $schedule->command(...) inside Kernel::schedule() on Laravel 9/10.
Schedule::command('zatca:requeue --status=clearance_manual_review --only-transient')
    ->hourly()->withoutOverlapping();
```

`--only-transient` selects last-attempt HTTP `0`/`5xx` failures, not validation rejections. See [commands and attempt limits](COMMANDS.md#13-console-commands).

### Cron and multiple servers

Run `sudo crontab -e -u deploy` and add, using your actual paths:

```cron
* * * * * cd /var/www/your-app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler account needs access to private files for cleanup. With this asynchronous queue, scheduled commands do not sign documents themselves and do not need Java.

```bash
sudo crontab -l -u deploy
cd /var/www/your-app
sudo -u deploy -- /usr/bin/php artisan schedule:list
```

Confirm actual execution through your OS's cron logs. `schedule:list` proves registration, not that cron is running.

On multiple servers, designate one scheduler host or add `onOneServer()` to events using a shared supported cache such as Redis/database. Keep `withoutOverlapping()` on retry commands: it prevents overlapping execution through the configured cache, across whichever hosts share that cache. `onOneServer()` elects one host for each due event. A local file cache cannot coordinate separate hosts.

SDK cleanup operates on local files. If workers run on several hosts with separate storage, arrange cleanup on each worker host as the appropriate account; one scheduler host cannot clean another host's local workspaces.

### Monitoring and cleanup

- Connect `zatca:status --json` to monitoring that sends alerts on failures; `--strict` also fails on warnings. Merely scheduling it does not notify anyone.
- Status warns on unreported B2C documents after 20 hours by default and fails after 24 hours. It checks review states, failed jobs, expiry and the sweep heartbeat (stale after 30 minutes by default).
- Listen to `ZatcaInvoiceNeedsAttention` and `ZatcaCertificateExpiring`. Certificate warnings default to 60 days before expiry. Renewal is unverified and disabled: arrange deliberate re-onboarding before expiry, rehearsed in simulation, without resetting state.
- Attempt pruning defaults to 180 days and selects successful B2C attempt outcomes, not all B2B/history rows. SDK cleanup defaults to 7 days, excludes files younger than one hour, and supports `--dry-run`. Neither command deletes invoice records.

## 8. Deployment

For the first production setup, finish the preceding steps, configure production seller/environment values and onboard with a production OTP. Keep B2B off until its documented verification checks are complete. See the [onboarding reference](INTEGRATION_REFERENCE.md#8-zatca-onboarding).

For subsequent deploys, use your normal maintenance/release procedure and include:

```bash
cd /var/www/your-app
composer install --no-dev --optimize-autoloader
php artisan zatca:check-source-uniqueness
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
php artisan zatca:health-check
```

Stop on a failed uniqueness check before migrations. Confirm your process manager starts replacement workers using new code/config/SDK paths. Do not regenerate keys or re-onboard on routine deploys. Read [release notes](../CHANGELOG.md) and [upgrade instructions](../UPGRADE.md) before updating dependencies.

After first production setup, verify one small **real sale**, not a fake test sale. Inspect its document with `zatca:inspect invoice --id=...`: B2C should be `reported`/`reported_with_warnings`; B2B should be `cleared`/`cleared_with_warnings`. Review warnings and give B2B buyers the cleared document only after acceptance.

### Protect production data

- Securely back up the database and `APP_KEY`. Losing/changing that key makes default-encrypted credentials unreadable.
- Never delete/edit stored credentials, invoice documents or EGS counter/hash state to repair failures. Never run `migrate:fresh`/`migrate:refresh` in production.
- Restoring an old database rewinds the chain. Stop submissions and contact ZATCA before issuing more documents after such a restore.
- Keep keys, CSIDs, tokens and certificates out of Git/logs/`.env`. Store credentials encrypted in the database and protect temporary signing files. SDK and private storage must stay outside the web root.

### Go-live checklist

- [ ] Correct seller/device data and production environment; required EGSs onboarded.
- [ ] Worker account can run PHP/Java, read the SDK and write private storage/logs.
- [ ] Trusted SDK fingerprint; smoke checks `[OK]`, not skipped.
- [ ] Queue tables exist; worker connection/name match; timeout is below `retry_after`.
- [ ] Worker service survives restarts; cron executes the registered schedule as the correct user.
- [ ] Persistent cache and shared coordination for multiple scheduler hosts if applicable.
- [ ] Health/status monitoring, alerts, backups and expiry handling in place.
- [ ] One real production document accepted on the intended route.

## 9. Troubleshooting

Start with `php artisan zatca:status --json`, `php artisan zatca:health-check` and worker/cron logs.

| Symptom | Check / action |
|---|---|
| Java works over SSH, signing fails | Run `java -version` as the worker user; check its service `PATH`. |
| SDK missing after deploy | Verify absolute current-release or stable SDK paths and all three folders. |
| Fingerprint mismatch | Investigate the jar. For a verified deliberate upgrade, update its SHA-256; do not replace keys just to inspect a hash. |
| Private-storage permission denied | Check actual worker/CLI account, parent-directory traversal and directory write access. Avoid recursive key-file permission changes. |
| Worker `FATAL`, inactive or repeatedly restarting | Check Supervisor log/systemd journal, PHP/app paths, operating account, log permissions and queue settings. |
| Jobs time out / are picked up twice | Review duration, SDK/HTTP limits, worker timeout and queue `retry_after`. |
| Pending documents accumulate | Check worker, connection/name, credentials and scheduled sweep. |
| Stale heartbeat / schedules never run | Check correct user's cron, actual cron logs, `schedule:list` and persistent cache. |
| Manual review persists after outage | Check last attempt, total attempt limit and B2C/B2B requeue status filter. Do not resend validation rejections. |
| Cleanup cannot remove old files | Run as workspace owner or arrange explicit authorized access. Ordinary Laravel CLI access may not suffice. |
| Config/code changes have no effect | Cache config, restart workers and verify replacement processes. |
| B2B timed out, acceptance unknown | Inspect `clearance_in_doubt`; do not regenerate merely because submission timed out. |

For business-rule errors, draft completion and recovery, see [Troubleshooting](TROUBLESHOOTING.md) and [commands](COMMANDS.md). The package logs through Laravel with a `[ZATCA]` prefix; redact secrets in custom diagnostics.
