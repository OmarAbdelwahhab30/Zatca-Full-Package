# Artisan command reference

<a id="13-console-commands"></a>
## Console commands

| Command | What it does | When |
|---|---|---|
| `zatca:generate-credentials` | Creates private key + CSR | Once per EGS/environment |
| `zatca:onboard {step}` | Runs one onboarding step | Once per EGS/environment |
| `zatca:health-check` | Checks SDK, config, credentials | After install, after each deploy |
| `zatca:sdk-smoke-test` | Tests Java + SDK locally | After install / SDK change |
| `zatca:report-pending` | Re-sends waiting documents | Scheduler, every 5 minutes |
| `zatca:requeue` | Restarts stuck B2C invoices | By hand, or hourly with `--only-transient` |
| `zatca:recover` | Finishes one sale's document after a technical failure (sign + report/clear) | By hand, never scheduled |
| `zatca:status` | Shows anything needing attention | Scheduler hourly, or monitoring |
| `zatca:inspect {resource}` | Shows credentials/state/invoice (no secrets) | When debugging |
| `zatca:certificate:check` | Updates certificate states | Scheduler, daily |
| `zatca:certificate:renew` | Renews a certificate (disabled by default) | Only after the renewal API is verified |
| `zatca:prune-submission-attempts` | Deletes old attempt rows | Scheduler, weekly |
| `zatca:prune-sdk-files` | Deletes leftover SDK temp files | Scheduler, daily |
| `zatca:check-source-uniqueness` | Finds sales with documents on two EGS | Before upgrading an existing install |

### `zatca:generate-credentials`

```bash
php artisan zatca:generate-credentials --egs=default
```

- `--egs` (optional, default `default`): the EGS identifier.
- Uses the SDK to create the private key and CSR from your `.env` seller data, and stores both encrypted. It does not call ZATCA.
- Prints the SDK fingerprint (put it in `ZATCA_SDK_FINGERPRINT`) and the CSR.
- Running it again **replaces** the stored key and CSR for that EGS and environment. Only do that before the compliance step, or when you deliberately start onboarding over.

### `zatca:onboard {step}`

```bash
php artisan zatca:onboard compliance --egs=default --otp=123456
```

| Argument / option | Required | Values |
|---|---|---|
| `step` | Yes | `compliance`, `test-invoice`, `test-credit-note`, `test-debit-note`, `production` |
| `--egs` | No (default `default`) | EGS identifier |
| `--otp` | Only for `compliance` | OTP from the Fatoora portal |

Run the steps in the order shown in [Onboarding](INTEGRATION_REFERENCE.md#8-zatca-onboarding). Test steps print `Status: PASS/WARNING/ERROR` and exit with code 1 on `ERROR`. Each test step uses up a compliance counter number, which is normal.

### `zatca:health-check`

```bash
php artisan zatca:health-check
```

It takes no options. It checks:

- the SDK jar and fingerprint,
- the EGS setup (including B2B config),
- that `ZATCA_ORG_IDENTIFIER` equals `ZATCA_VAT_NUMBER`,
- the formats of the VAT number, CR number, building number, additional number and postal code,
- the address,
- the credentials and certificate state of every EGS,
- that the scheduler is running the retry sweep (`zatca:report-pending` must have run within `ZATCA_SCHEDULER_STALE_MINUTES`).

It prints `[OK]`, `[PENDING]`, `[WARN]` or `[FAIL]` lines and exits with code 1 if anything fails. Run it after every deployment.

### `zatca:sdk-smoke-test`

```bash
php artisan zatca:sdk-smoke-test
```

It takes no options. It checks Java, the SDK files and the fingerprint. Then, using the SDK's own sample certificate, it tests CSR generation, hashing, signing, QR extraction and validation. It never calls ZATCA. If the SDK paths are not set, it prints `SKIPPED`.

### `zatca:report-pending`

```bash
php artisan zatca:report-pending
```

It takes no options. It re-dispatches every document in `pending_reporting`, `retrying`, `pending_clearance`, `clearance_retrying` or `clearance_in_doubt`. It never touches `manual_review` or rejected documents, and it never signs again. Schedule it every 5 minutes. When ZATCA is disabled, it sends nothing. Every run, including a disabled one, records its time in the cache so `zatca:status` can tell a stalled scheduler from a healthy one.

### `zatca:requeue`

```bash
php artisan zatca:requeue --dry-run
```

| Option | Default | Meaning |
|---|---|---|
| `--status=` | `manual_review` | B2C: `manual_review`, `failed`, `retrying`, `pending_reporting`. B2B: `clearance_manual_review`, `clearance_retrying`, `pending_clearance`, `clearance_in_doubt`. |
| `--only-transient` | off | Only invoices whose last failure was a ZATCA outage (HTTP 0 or 5xx). Safe to schedule. |
| `--max-total-attempts=` | `50` | With `--only-transient`, give up on invoices tried this many times |
| `--egs=` | all | Limit to one EGS |
| `--id=*` | – | Specific invoice ids (repeat the option) |
| `--limit=` | `0` (no limit) | Max invoices |
| `--keep-attempts` | off | Don't reset `attempt_count` |
| `--dry-run` | off | Show what would happen, change nothing |

It sends stuck documents again with the same signed XML. B2C documents go to Reporting and B2B documents go to Clearance. The package picks the route from the document itself. Accepted documents (`reported`, `cleared`…) and `clearance_rejected` are never resent, even with `--id`. Read `last_error` before requeueing a validation failure, because a rejected document will just be rejected again (use [regeneration](INTEGRATION_REFERENCE.md#re-issuing-a-rejected-invoice-regeneration) for those).

```bash
php artisan zatca:requeue --only-transient
```

### `zatca:recover`

Finishes the ZATCA side of **one sale** after a technical failure stopped it part-way: the SDK jar was missing, a folder was not writable, ZATCA was down, a worker died between signing and sending. It moves the sale's document one safe step forward through the normal pipeline and waits for ZATCA's answer, so you see the result straight away.

```bash
# Always look first
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042 --dry-run

# Then recover
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042

# Or start from a ZATCA document id (from logs, zatca:status or zatca:inspect)
php artisan zatca:recover --id=41
```

| Option | Meaning |
|---|---|
| `--source-type=` + `--source-id=` | The sale, exactly as your `InvoiceSourceResolverContract` understands it. With the default resolver, `--source-type` is the Eloquent model class. Works for orders, payments, bookings, or anything else you map. |
| `--id=` | A `zatca_invoices` id. Use it when a document already exists; it does not need the source record. Use either this or the two options above. |
| `--egs=` | The EGS to sign a **new** document on. Required when a B2B EGS is configured, because only your app knows whether a sale is B2C or B2B. Ignored for an existing document, which always stays on its own EGS. |
| `--resend-in-doubt` | Allow resending a B2B document in `clearance_in_doubt` (sent, but ZATCA never answered). Check on the ZATCA side first. |
| `--dry-run` | Show the current state and the planned action. Writes, signs and sends nothing. |

What it does, by the state of the sale's current document:

| Current state | Action |
|---|---|
| No document yet | Creates it (new ICV and UUID), signs it, then reports (B2C) or clears (B2B) it |
| Unsigned draft | Signs the **same** draft (same ICV and UUID), then reports or clears it |
| Signed, waiting (`pending_reporting`, `retrying`, `failed`, `pending_clearance`, `clearance_retrying`) | Sends the stored signed XML once more. Never signs again. |
| `manual_review` / `clearance_manual_review` after an outage (last attempt HTTP 0 or 5xx) | Sends it once more |
| `clearance_in_doubt` | Stops, unless you pass `--resend-in-doubt` |
| Rejected by ZATCA (`manual_review` / `clearance_manual_review` with a 4xx, or `clearance_rejected`) | **Stops.** The same document would be rejected again. Fix the cause and [regenerate](INTEGRATION_REFERENCE.md#re-issuing-a-rejected-invoice-regeneration) it. |
| Already `reported` / `cleared` | Nothing |

Safety rules:

- **It never regenerates.** A replacement document uses a new ICV that can never be given back, so that stays a human decision.
- It never resends a document ZATCA accepted or rejected, and never signs a document twice.
- A replaced (regenerated) sale is recovered through its **current** document, never the rejected one.
- More than one root document for the same sale stops the command with a table instead of a guess.
- It stops if the production certificate has expired, if the B2B switch is off for a new B2B document, or if ZATCA is disabled.
- One recovery per sale at a time (a cache lock). Your cache store must support locks (`redis`, `database`, `file`, `array`…).
- It is safe to run again. It continues from wherever the last run stopped.

It sends **once**. If ZATCA is still down, the document stays in `retrying` and `zatca:report-pending` carries on. It only handles the ZATCA document. Your app's own follow-ups for the sale (its invoice record, receipt, email) are not part of this package.

Exit code `0` means ZATCA accepted the document, nothing was left to do, or a dry run found a safe plan. Exit code `1` means anything else: bad input, a refusal, a failure, or ZATCA has not accepted it yet. The output says which.

### `zatca:status`

```bash
php artisan zatca:status --json
```

| Option | Default | Meaning |
|---|---|---|
| `--stale-minutes=` | `60` | An unreported document older than this counts as stuck |
| `--strict` | off | Exit with code 1 on warnings too |
| `--json` | off | Machine-readable output for monitoring |

It checks for documents waiting for a human (`manual_review`, `clearance_manual_review`, `clearance_rejected`), documents stuck on their way to ZATCA, certificate expiry and failed jobs. It also checks two things that are easy to miss:

- **Reporting deadline.** ZATCA wants a Simplified (B2C) document reported within 24 hours. Unreported B2C documents (including ones in `manual_review`) older than `ZATCA_REPORTING_DEADLINE_WARN_HOURS` (default 20) show as a warning, and any past 24 hours show as a failure. Replaced (superseded) documents and B2B documents are not counted. The check reports the current picture on every run, so it never repeats an alert by itself.
- **Scheduler.** `zatca:report-pending` records each run in the Laravel cache. If the last run is older than `ZATCA_SCHEDULER_STALE_MINUTES` (default 30), the row fails, because failed submissions are no longer being retried. Before the first run it is a warning. With the `array` or `null` cache store it cannot be checked and shows as a warning.

It exits with code 1 only on failures (or on warnings with `--strict`).

### `zatca:inspect {resource}`

```bash
php artisan zatca:inspect invoice --id=42
```

| Argument / option | Required | Values |
|---|---|---|
| `resource` | Yes | `credentials`, `state`, `invoice` |
| `--egs` | No (default `default`) | For `credentials` and `state` |
| `--id` | For `invoice` | Invoice id or UUID |

It is read-only and prints JSON. It never shows secrets, keys or XML.

### `zatca:certificate:check`

```bash
php artisan zatca:certificate:check
```

- `--egs=` (optional): one EGS. Without it, it checks all EGS.
- It updates the `valid`, `expiring_soon` and `expired` states and fires `ZatcaCertificateExpiring` on a change. Schedule it daily.

### `zatca:certificate:renew`

```bash
php artisan zatca:certificate:renew --egs=default --type=production
```

- `--egs` (default `default`), `--type` (`compliance` or `production`, default `production`).
- It only acts on certificates that are `expiring_soon`, `expired` or `failed`.
- While `ZATCA_AUTO_RENEWAL_ENABLED=false` (the default), it prints a warning and calls nothing. Don't enable it until ZATCA's renewal API is verified.

### `zatca:prune-submission-attempts`

```bash
php artisan zatca:prune-submission-attempts
```

It takes no options. It deletes attempt rows older than `ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS` (180) **for accepted B2C invoices only**. Invoices themselves are never deleted. Schedule it weekly.

### `zatca:prune-sdk-files`

```bash
php artisan zatca:prune-sdk-files --dry-run
```

- `--days=` (default `ZATCA_PRUNE_SDK_FILES_AFTER_DAYS`, 7), `--dry-run`.
- It deletes leftover SDK files under `storage/app/private/zatca`. It never touches anything younger than 1 hour or anything in the database. Schedule it daily.

### `zatca:check-source-uniqueness`

```bash
php artisan zatca:check-source-uniqueness
```

It takes no options and is read-only. It lists sales that have documents on more than one EGS in the same environment. The migration `2026_09_28_000002_add_env_source_unique_to_zatca_invoices_table` adds a unique index that fails if any exist. **When upgrading an existing install, run this before `php artisan migrate`.** It must print `[OK]`.
