# Upgrade guide

## Moving to 1.0.0

1.0.0 is the first tagged release, so there is no earlier version number to upgrade from. This guide is for applications that installed the package from the untagged development branch. If you are installing for the first time, follow the README instead.

**Database:** no new migrations. Do not change existing ones.

### 1. Update Composer dependencies

```bash
composer update omarabdelwahhab30/zatca guzzlehttp/guzzle
```

The package now requires `guzzlehttp/guzzle ^7.15.2`. If your application pins an older Guzzle, raise the pin. On Laravel 9, 10 and 11 Composer refuses to install because those Laravel versions have open security advisories; the README explains the override and why Laravel 12 is recommended.

### 2. Reporting and Clearance now stop on an expired certificate

Before this release the check that pauses submission for an expired production certificate never matched, so documents were still sent. It now works as documented: a document stays `pending_reporting` or `pending_clearance` and nothing is sent until the certificate is renewed. If your production certificate has already expired, expect pending documents after the upgrade. Re-onboard first (README, *Certificates*), then run `php artisan zatca:report-pending`.

### 3. Custom SDK implementations

`ZatcaSdkInterface::signInvoice()` returns `signedXmlBase64`, `invoiceHash` and `qrCode`. The `signedXmlPath` key is gone, because the signed file is no longer written to disk. If you bind your own `ZatcaSdkInterface` or fake it in tests, stop returning and reading `signedXmlPath`.

### 4. Delete old signed-XML copies

Earlier builds left a copy of each signed invoice in `storage/app/private/zatca/sdk-output/`. They contain buyer data and nothing reads them. `php artisan zatca:prune-sdk-files` removes the ones older than the retention period. To remove them all now, delete the `*.xml` files in that folder.

### 5. Stricter invoice text checks

Invoices whose text (item descriptions, buyer name, reasons, buyer address fields) is not valid UTF-8, or contains control characters other than tab and line breaks, are now refused with a `ZatcaException` before a counter number is used. Clean the data at its source. Documents already created are not affected.

### 6. Scheduler and deadline monitoring

- `zatca:report-pending` records its run in the Laravel cache. The scheduler check only works with a cache store that survives between processes (`file`, `database`, `redis`, `memcached`). With `array` or `null` it shows a warning in `zatca:status`.
- If you run `zatca:status --strict` in a monitor, that warning (or a scheduler that has never run) now makes it exit non-zero. Switch the cache store, or drop `--strict`.
- `zatca:health-check` fails when the sweep last ran more than `ZATCA_SCHEDULER_STALE_MINUTES` ago (default 30). Before the first run it prints `[PENDING]`.
- New optional settings (defaults apply if your published `config/zatca.php` does not have them): `scheduler.stale_after_minutes` (`ZATCA_SCHEDULER_STALE_MINUTES`) and `reporting_deadline.warn_after_hours` (`ZATCA_REPORTING_DEADLINE_WARN_HOURS`). To publish the config again: `php artisan vendor:publish --tag=zatca-config --force`, then re-apply your changes.

### 7. Debugging output

`ZatcaCredentialSet` no longer shows its secret, private key or token in `var_dump` or `print_r`. Symfony's `dump()` and `dd()` read properties directly and are not covered, so do not dump a credential set where the output is shared.

### After upgrading

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan zatca:health-check
php artisan zatca:status
```
