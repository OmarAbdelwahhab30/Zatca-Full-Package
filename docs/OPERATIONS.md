# ZATCA Package — Operations Runbook

Follow the steps in order. Each step says **what to run** and **what you should see**.

- **Something is broken right now** → [`WHEN_SOMETHING_BREAKS.md`](WHEN_SOMETHING_BREAKS.md)
- Setup and code examples → [`README.md`](../README.md)
- What each command writes to the database → [`DATABASE.md`](DATABASE.md)
- Version table → [`COMPATIBILITY.md`](COMPATIBILITY.md)
- Prompt to paste into an AI assistant when something breaks → [`AI_SUPPORT_PROMPT.md`](AI_SUPPORT_PROMPT.md)

---

## 1. Deployment (staging and production)

The same order works for both. On staging set `ZATCA_ENVIRONMENT=sandbox`; on production set `production`.

> ### ⭐ The golden rule: prove the server can sign **before** you ask for an OTP
>
> The natural instinct is to start with onboarding. Don't.
>
> Onboarding needs an OTP from the Fatoora portal. If Java is missing or an SDK path is wrong on that server, the onboarding call fails and you are back at the portal for another OTP — an avoidable round trip, and an avoidable delay on production. Treat every OTP as single-use.
>
> `zatca:sdk-smoke-test` checks Java, the SDK, signing and validation **without contacting ZATCA and without needing any certificate**. Run it first. Get the OTP only after it passes.
>
> **Stage C below is that check. Never skip it, and never reorder it after Stage D.**

### Stage A — Server prerequisites (once per server)

**Step 1 — Install Java.**

```bash
java -version
```

You should see version **11 to 15**. If `java` is not found, the package will fail. It runs `java -jar ...` directly.

**Step 2 — Copy the ZATCA SDK folder to the server.**
It must contain `Apps/`, `Configuration/`, and `Data/`. The PHP user must be able to read it.

**Step 3 — Make the storage folder writable.**

```bash
mkdir -p storage/app/private/zatca && chmod 700 storage/app/private/zatca
```

**Step 4 — Check `APP_KEY` is set and will never change.**
It decrypts the certificate and private key in the database. If you change it, all ZATCA credentials become unreadable and you must onboard again.

### Stage B — Install the package

**Step 1 — Put these values in `.env`:**

```
ZATCA_API_VERSION=V2
ZATCA_SDK_VERSION=3.4.8
ZATCA_UBL_VERSION=2.1
ZATCA_XML_PROFILE_VERSION=V2

ZATCA_SDK_EXECUTABLE=/full/path/to/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_ROOT=/full/path/to/ZACTA-SDK-JAVA
ZATCA_SDK_FINGERPRINT=<sha256 printed by zatca:generate-credentials>

ZATCA_ENVIRONMENT=production        # staging: sandbox
ZATCA_QUEUE_NAME=zatca
```

⚠ Double-check `ZATCA_ENVIRONMENT` on staging. Setting it to `production` there means staging sends test purchases to the real ZATCA.

Leave `ZATCA_SDK_FINGERPRINT` empty for now — Stage D prints the value to put here.

Plus your company data: `ZATCA_VAT_NUMBER`, `ZATCA_ORG_NAME`, `ZATCA_ORG_UNIT_NAME`, `ZATCA_ORG_IDENTIFIER`, `ZATCA_CSR_COMMON_NAME`, `ZATCA_CSR_SERIAL_NUMBER`, `ZATCA_CRN`, `ZATCA_LOCATION_ADDRESS`, `ZATCA_BUSINESS_CATEGORY`, `ZATCA_INVOICE_TYPE`, `ZATCA_COUNTRY_CODE`.

**Step 2 — Install dependencies:**

```bash
composer install --no-dev
```

**Step 3 — Run migrations** (creates the 5 `zatca_*` tables):

```bash
php artisan migrate
```

**Step 4 — Cache the config:**

```bash
php artisan config:cache
```

**Step 5 — Restart the queue worker:**

```bash
php artisan queue:work --queue=zatca --tries=1
```

### ⭐ Stage C — Prove the server can sign (before any OTP)

**Step 1 — Run the real-SDK check:**

```bash
php artisan zatca:sdk-smoke-test
```

You must see **nine `[OK]` lines**: Java, SDK executable, SDK root, fingerprint, CSR generation, hash, signing, QR extraction, validation.

| What you see | What it means | Do this |
|---|---|---|
| 9 × `[OK]` | The server can sign. | Continue to Stage D. |
| `SKIPPED` | `ZATCA_SDK_EXECUTABLE` / `ZATCA_SDK_ROOT` are wrong or unset. **The command still exits 0** — read the text, not the exit code. | Fix the paths in `.env`, re-run. |
| `[FAIL]` on Java | Java missing, or not version 11–15, or not on the PHP user's `PATH`. | Fix Java, re-run. |
| `[FAIL]` on signing | Usually the SDK folder is incomplete or unreadable by the PHP user. | Fix, re-run. |

**Do not continue until all nine are `[OK]`.** This step contacts nobody and costs nothing to repeat.

**Step 2 — Health check (before onboarding):**

```bash
php artisan zatca:health-check
```

At this point this is the **expected and correct** output:

```
[OK] SDK executable found: ...
[SKIP] No SDK fingerprint recorded yet ...
[PENDING] [default] compliance credentials not yet onboarded.
[PENDING] [default] production credentials not yet onboarded.
```

`[PENDING]` is normal here — you have not onboarded yet. Only the SDK lines matter at this stage.

### Stage D — Onboarding (one time per EGS, by hand)

Now — and only now — get the OTP from the Fatoora portal.

**Step 1 — Generate the key and CSR:**

```bash
php artisan zatca:generate-credentials
```

It prints `SDK fingerprint: <sha256>`. **Copy it into `.env` as `ZATCA_SDK_FINGERPRINT`**, then run `php artisan config:cache` again.

**Step 2 — The three ZATCA calls, in order:**

```bash
php artisan zatca:onboard compliance --otp=XXXXXX
php artisan zatca:onboard test-invoice
php artisan zatca:onboard test-credit-note
php artisan zatca:onboard test-debit-note
php artisan zatca:onboard production
```

ZATCA tracks compliance separately per document type. `production` is refused with `Production CSID request failed: The compliance certificate is not done with the following compliance steps yet [...]` until **all three** `test-*` steps have a PASS/WARNING submission on record — `test-credit-note` and `test-debit-note` each bill against the invoice `test-invoice` already submitted, so **run `test-invoice` first**.

If a step fails, fix the cause and re-run **that step** — you do not restart the whole sequence. Get a fresh OTP from the portal before retrying the compliance step; treat OTPs as single-use.

### Stage E — Final verification

**Step 1 — Health check (now everything should pass):**

```bash
php artisan zatca:health-check
```

Expected now: `[OK]` on the SDK lines, `[OK] SDK fingerprint matches recorded value`, and both credentials present with `certificate state: valid`.

**Step 2 — Look at what was stored:**

```bash
php artisan zatca:inspect credentials
php artisan zatca:inspect state
```

**Step 3 — Create one real invoice** and confirm its status becomes `reported`.

> See [`DATABASE.md`](DATABASE.md) for exactly which rows each of these stages adds.

### ⚠ Things you must know

1. **Two things are missing in the main app right now** and must be added before going live (see `docs/ZATCA_ACTIVATION_GUIDE.md` §8 and §13):
   - a binding for `InvoiceSourceContract` — without it, invoice generation cannot run;
   - scheduled commands:

     ```php
     $schedule->command('zatca:report-pending')->everyFiveMinutes();
     $schedule->command('zatca:requeue --only-transient')->hourly();
     $schedule->command('zatca:certificate:check')->daily();
     $schedule->command('zatca:status')->hourly()->emailOutputOnFailure('ops@example.com');
     $schedule->command('zatca:prune-submission-attempts')->weekly();
     $schedule->command('zatca:prune-sdk-files')->daily();
     ```

   - listeners for the two alerting events (see "Getting told when something breaks" below).

2. **All ZATCA files live under `storage/app/private/zatca/`** — not `storage/app/zatca/`. The queue worker (not just the web server) must be able to write there.

3. **Two different cleanup commands — you need both.**

   | Command | Cleans | Default age |
   |---|---|---|
   | `zatca:prune-submission-attempts` | database rows (retry history of invoices that already succeeded) | 180 days |
   | `zatca:prune-sdk-files` | files on disk under `storage/app/private/zatca/` | 7 days |

   Neither one ever touches the invoices themselves.

### Getting told when something breaks

Everything in this package logs. **Nobody reads logs.** Two things close that gap.

**1. `zatca:status` — one command that answers "is anything wrong?"**

```bash
php artisan zatca:status              # human-readable table
php artisan zatca:status --json       # for a monitor
php artisan zatca:status --strict     # warnings also count as failure
```

| Exit code | Meaning |
|---|---|
| `0` | Nothing needs doing (warnings may still be printed) |
| `1` | Something needs a human |

It checks: invoices in `manual_review`, invoices unreported for over an hour, certificate expiry per EGS, and failed jobs on the `zatca` queue.

Severity is deliberate: a certificate 45 days out is a **warning** (exit 0) so nobody is paged daily for two months, but at **30 days it becomes a failure** — renewal needs a portal OTP and a human's calendar. An hour-old reporting backlog is a failure too: it means the worker died or the certificate expired.

This is different from `zatca:health-check`, which asks "is the install wired up correctly" (SDK, fingerprint, credentials present). Use health-check after deploying; use status continuously.

**2. Two events to hook your own alerting onto**

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Zatca\Events\ZatcaCertificateExpiring::class => [SendZatcaCertificateAlert::class],
    \Zatca\Events\ZatcaInvoiceNeedsAttention::class => [SendZatcaInvoiceAlert::class],
];
```

| Event | Fired when | Properties |
|---|---|---|
| `ZatcaCertificateExpiring` | A certificate first becomes `expiring_soon` or `expired` — **once per transition**, not once per daily check | `egsIdentifier`, `credentialType`, `state`, `expiresAt`, `daysRemaining`, `hasExpired()` |
| `ZatcaInvoiceNeedsAttention` | An invoice lands in `manual_review` | `invoice`, `reason` (already sanitized, safe to put in an email) |

Without listeners these fire into the void — the package deliberately ships no mail/Slack code of its own.

### Restarting stuck invoices — `zatca:requeue`

`manual_review` covers two very different situations, and the difference decides whether a retry can help at all:

| Cause | Last HTTP status | Retrying helps? |
|---|---|---|
| **ZATCA outage** — server down, network failure | `0` or `5xx` | ✅ Yes. The invoice is fine; ZATCA simply wasn't reachable. |
| **ZATCA rejected it** — validation error, bad data | `4xx` | ❌ No. It will fail identically forever until a human fixes the cause. |

**Schedule the safe half.** `--only-transient` requeues only the outage victims and never touches a rejection:

```php
$schedule->command('zatca:requeue --only-transient')->hourly();
```

Now a ZATCA outage that outlasts the retry budget repairs itself an hour later, with nobody woken up. Genuine rejections stay in `manual_review` and wait for the alert you wired to `ZatcaInvoiceNeedsAttention`.

Two safeguards make this safe to leave running unattended: an invoice with **no attempt history** is skipped (nothing proves it was an outage), and one already attempted more than `--max-total-attempts` times (default 50) is skipped as genuinely stuck rather than retried forever.

**Manual use**, after you have fixed a rejection:

```bash
php artisan zatca:requeue --dry-run          # show what would be requeued
php artisan zatca:requeue                    # every manual_review invoice
php artisan zatca:requeue --id=41 --id=42    # specific invoices
php artisan zatca:requeue --egs=default --limit=50
```

It resets `attempt_count` by default — without that the invoice would be sent once and immediately fall back into `manual_review` for exceeding the budget. Use `--keep-attempts` to override.

**It never re-signs anything.** The stored signed XML, UUID, ICV and PIH are sent again exactly as they are. And it refuses to touch an invoice ZATCA already accepted, even if you pass its ID explicitly.

⚠ Only the `--only-transient` form belongs on the scheduler. Plain `zatca:requeue` retries rejections too, which just burns attempts and hides the real problem.

### Recovering one sale after a technical failure — `zatca:recover`

When a technical problem stopped one sale part-way (the SDK jar was missing, a folder was not writable, a worker died between signing and sending), fix the cause first, then finish that sale:

```bash
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042 --dry-run
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042
php artisan zatca:recover --id=41    # starting from a ZATCA document id
```

It finds the sale's current document and moves it one safe step forward: creates and signs it if it does not exist, signs an unsigned draft with its **same** ICV and UUID, or sends a signed document once more. B2C documents are reported and B2B documents are cleared, chosen from the document itself. It waits for ZATCA's answer and exits `0` only when ZATCA accepted the document.

It refuses, and says why, when:
- ZATCA rejected the document. Sending it again would fail the same way. Fix the cause and regenerate it.
- a B2B document is `clearance_in_doubt`. Check on the ZATCA side, then add `--resend-in-doubt`.
- a B2B EGS is configured and the sale has no document yet. Pass `--egs=` with the EGS your app signs that sale on.
- the production certificate has expired, or ZATCA is switched off.

**It never regenerates and never spends an extra ICV on a rejected document.** It is a manual tool: never schedule it. Full option list: README → Console commands → `zatca:recover`.

How it differs from `zatca:requeue`: requeue restarts many already-signed documents through the queue. Recover handles one sale end to end, including unsigned drafts and sales with no document yet, and shows the result straight away.

### Surviving a ZATCA outage

Reporting attempts are consumed at the pace `zatca:report-pending` runs:

```
ZATCA_REPORTING_RETRY_ATTEMPTS (default 12) × every-5-minutes ≈ 1 hour of downtime survived
```

Past that budget the invoice moves to `manual_review` — and if `zatca:requeue --only-transient` is scheduled, it is picked back up on the next hourly run and keeps trying. So the practical answer to "ZATCA went down at 2am" is: **nothing to do**. Nothing is ever lost either way; the signed invoice, hash chain, UUID and ICV are untouched throughout.

Raise `ZATCA_REPORTING_RETRY_ATTEMPTS` if you would rather absorb a longer outage without invoices passing through `manual_review` at all.

### Switching ZATCA off entirely — `ZATCA_ENABLED`

Different from an outage: an outage is survived by waiting, this is a deliberate stop. Set the flag, then run **both** commands — the flag is config-cached, and queue workers hold the old config until they restart:

```bash
php artisan optimize:clear
```

```bash
php artisan queue:restart
```

Safe to do with work in flight: queued jobs exit quietly rather than failing, claims are released, and no invoice status or `attempt_count` is written. Payments, order fulfilment, local invoices and invoice emails keep working; onboarding and the diagnostic commands stay available so an EGS can still be repaired while invoicing is off.

Full behaviour, including what invoice PDFs look like while disabled, is in the README's [Disabling ZATCA](../README.md#disabling-zatca) section.

### Cleaning up disk space — `zatca:prune-sdk-files`

```bash
php artisan zatca:prune-sdk-files --dry-run       # show what would go, delete nothing
php artisan zatca:prune-sdk-files                 # use the configured window (default 7 days)
php artisan zatca:prune-sdk-files --days=30       # override the window for this run
```

Set the default with `ZATCA_PRUNE_SDK_FILES_AFTER_DAYS` in `.env`.

**What it deletes:**

| Location | What it is |
|---|---|
| `sdk-output/` | Signed-XML copies the SDK adapter writes and then abandons |
| `tmp/` | Certificate/key files handed to the SDK |
| `smoke-test/` | Files from `zatca:sdk-smoke-test` |
| `runtime/{uuid}/` | SDK workspaces orphaned by a killed process |
| `csr-config-*.properties` | CSR config files |

**Why deleting these is safe:** the signed invoice is stored in the database column `zatca_invoices.signed_xml_base64`, which is **never pruned**. Reporting and retries read that column, not these files — `RetryZatcaInvoiceJob` re-sends the stored bytes. Nothing in the package reads any of these files after the call that created them finishes.

⚠ `tmp/` and `runtime/` normally clean themselves in a `finally` block. They only survive when a process is killed (out of memory, reboot, timeout) — and what survives includes **private-key material**. So this is a security cleanup, not only a disk-space one.

**Safety floor:** the command never deletes anything younger than **one hour**, even with `--days=0`, so a signing operation that is currently running can never have its workspace removed underneath it.

**Legal retention is a separate question.** How long invoices must be kept is a tax/legal matter to confirm with your accountant or the official ZATCA regulation — not something this package decides. What matters technically: the invoices live in the database and your database backup covers them; these files are duplicates.

---

## 2. When the ZATCA SDK Version Changes

### What to do

**Step 1 — Put the new SDK on the server** and update `.env`:

```
ZATCA_SDK_EXECUTABLE=/path/to/new-sdk.jar
ZATCA_SDK_ROOT=/path/to/new-sdk-root
ZATCA_SDK_VERSION=<new version>
```

**Step 2 — Get the new fingerprint and update `.env`:**

```bash
php artisan zatca:generate-credentials
```

It prints `SDK fingerprint: <sha256>`. Copy it into `ZATCA_SDK_FINGERPRINT`.
If you skip this, `zatca:health-check` will fail with a fingerprint mismatch error.

**Step 3 — Check if the SDK's console text changed.** If yes, create a **new** folder (never edit the old one):

- `src/Services/SDK/OutputParsers/V<new_version>/` — copy the 3 parser files and adjust them
- `tests/Fixtures/SdkOutput/V<new_version>/` — save the new console output as fixtures
- update the 3 `use` lines and the constructor in `src/Services/SDK/ZatcaSdkAdapter.php`

**Step 4 — Check the SDK folder structure did not change.** These 4 paths are written directly in `ZatcaSdkRuntimeManager`:

```
Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd
Data/Rules/Schematrons/CEN-EN16931-UBL.xsl
Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl
Configuration/usage.txt
```

If the new SDK renamed any of them, signing and validation break.

**Step 5 — Add one row to `docs/COMPATIBILITY.md`.**

### How to verify

```bash
composer test
php artisan zatca:sdk-smoke-test
```

⚠ `zatca:sdk-smoke-test` can print **SKIPPED** and still exit successfully. Read the output — green does not always mean it ran. Then test full onboarding + one invoice in sandbox before production.

### ⚠ Important

Changing `ZATCA_SDK_VERSION` in `.env` **does not** change which parser folder is used. The parser is chosen by the class name written inside `ZatcaSdkAdapter`, not by config. (The comment in `config/zatca.php` says otherwise — the comment is wrong.)

### Our versioning rule

Always **add**, never **edit**:

1. New version = new `V…` folder. The old folder stays exactly as it is.
2. Test the new version fully.
3. Add the row to `COMPATIBILITY.md`.
4. Only then change the value in `.env`.
5. Never change the default in `config/zatca.php` without a matching row in the table.
6. Bump the package version.

---

## 3. When ZATCA Releases a New API Version

### Which files change

| File | What to do |
|---|---|
| `src/Services/Connectors/V2/` | Do **not** edit. Create `Connectors/V3/` next to it (3 files: `HttpConnector`, `RequestMapper`, `ResponseNormalizer`) |
| `src/Services/Connectors/ConnectorFactory.php` | Add one `match` line: `'V3' => app(V3\HttpConnector::class)` |
| `src/Enums/ZatcaResponseStatus.php` | Check the status names still match |

### ⚠ Important

Each connector declares its own version **once**, as a constant:

```php
private const API_VERSION = 'V2';   // V2/HttpConnector.php
```

All 5 endpoints get their headers from one `headers()` method, so `Accept-Version` can never differ between them.

`ZATCA_API_VERSION` does **not** set this header. It only decides **which connector class** is used. Your new `V3/HttpConnector` must declare `private const API_VERSION = 'V3';`.

If the two disagree — for example `.env` says `V3` but the V2 connector is used — the request is **blocked** with a clear error instead of being sent with the wrong version:

```
ZATCA_API_VERSION is [V3] but the V2 connector was used.
Resolve the connector through ConnectorFactory, and add Connectors/V3/ before switching config to it.
```

### Check these before releasing

1. The 4 endpoints still exist and take the same body:
   `POST /compliance`, `/compliance/invoices`, `/production/csids`, `/invoices/reporting/single`
2. The response fields the code reads: `binarySecurityToken`, `secret`, `validationResults.status`, `reportingStatus`, `errorMessages[]`.
   If the shape changes, the code throws `ZatcaContractChangedException`.
3. HTTP **409** is treated as "already reported = success" only when the message matches `already ... report`.
4. The fixed header `Clearance-Status: 0` on reporting.
5. Does the new API also need a new XML format? If yes, that is a **separate** change: create `src/Services/Invoice/XmlGenerators/V3/` and set `ZATCA_XML_PROFILE_VERSION=V3`. Do not assume they change together.

**Note:** this package only does **reporting**. There is no clearance endpoint in the code at all.

---

## 4. When the Production Certificate Expires

### What happens automatically

| Days left | What the system does |
|---|---|
| 60 days | State becomes `expiring_soon`, writes a **warning** to the log |
| 0 days | State becomes `expired`, writes an **error** to the log |
| After expiry | Invoices are still created and signed, but **not sent** to ZATCA. They wait with status `pending_reporting`. |

**Nothing is lost.** Sending is paused, not cancelled.

⚠ But this only happens if `zatca:certificate:check` runs on a schedule. **Today nothing schedules it.** Add it (see section 1).

### The two certificate commands

**`zatca:certificate:check`** — read-only. Updates the state (`valid` / `expiring_soon` / `expired`) and writes to the log. Run it daily from the scheduler.

```bash
php artisan zatca:certificate:check
php artisan zatca:certificate:check --egs=default   # one EGS only
```

**`zatca:certificate:renew`** — ⚠ **does not renew anything today.**

```bash
php artisan zatca:certificate:renew --egs=default --type=production
```

Because `ZATCA_AUTO_RENEWAL_ENABLED=false`, this command prints a warning and calls no ZATCA API. It exists for the day the renewal contract is verified. Use the manual steps below instead.

It only acts when the state is `expiring_soon`, `expired`, or `failed` — on a `valid` certificate it does nothing.
Exit code is **0 only if the certificate ends up `valid`**, so a scheduler running it today will report failure.

### How to renew (the way that works today)

Renewal is **manual**. Repeat the onboarding steps with a new OTP from the Fatoora portal:

```bash
php artisan zatca:onboard compliance --otp=XXXXXX
php artisan zatca:onboard test-invoice
php artisan zatca:onboard test-credit-note
php artisan zatca:onboard test-debit-note
php artisan zatca:onboard production
```

Then release the waiting invoices:

```bash
php artisan zatca:report-pending
```

This re-sends the invoices **without** signing them again — the UUID, ICV, PIH and hash stay the same.
It does **not** touch invoices with status `manual_review` or `failed`. Those need a human to look at them.

### ⚠ Do not turn on automatic renewal

`ZATCA_AUTO_RENEWAL_ENABLED=false` is the default and should stay `false`.

The code has a `renewProductionCsid()` method that calls `/production/csids`, but **only because that is the only certificate endpoint we have official ZATCA documentation for**. The real renewal API (its URL, whether it needs a new OTP, what it returns) has **not** been confirmed against an official ZATCA document. Turning the flag on means sending a request we are not sure is correct.

Also: a **compliance** certificate can never be renewed by this code. You must onboard again.

### What you must keep (never delete)

1. `APP_KEY` — without it the stored certificate and private key cannot be decrypted.
2. The `zatca_egs_credentials` table rows: `private_key`, `secret`, `binary_security_token`, `csr`, `request_id`.
   The production certificate reuses the **compliance private key**, so losing that row means starting over.
3. The CSR values in `.env` (`ZATCA_CSR_COMMON_NAME`, `ZATCA_CSR_SERIAL_NUMBER`, `ZATCA_ORG_*`, `ZATCA_CRN`, ...).
4. Access to the Fatoora portal to get OTPs.

### How to avoid downtime

1. Schedule `zatca:certificate:check` **daily**.
2. Watch the log for the 60-day warning and act on it.
3. Renew while the state is still `expiring_soon` — never wait until `expired`.
4. After renewing, run `zatca:report-pending` to release the paused invoices.

---

## 5. Compatibility Matrix

`docs/COMPATIBILITY.md` is the **only** place the version table lives. Do not copy it anywhere else.

Every time **one** version changes, record **all** of them together as one row:

| Record this | Comes from |
|---|---|
| Package version | `composer.json` (currently no `version` field — README says `1.0.0`) |
| ZATCA API version | `ZATCA_API_VERSION` |
| SDK version | `ZATCA_SDK_VERSION` |
| UBL version | `ZATCA_UBL_VERSION` |
| XML profile version | `ZATCA_XML_PROFILE_VERSION` |
| Also useful | PHP + Laravel version, Java 11–15, SDK jar sha256 |

### Folders that carry a version in their name

A row in the table is only meaningful together with these folders:

| Folder | Chosen by |
|---|---|
| `src/Services/Connectors/V2/` | `ZATCA_API_VERSION` (via `ConnectorFactory`) |
| `src/Services/Invoice/XmlGenerators/V2/` | `ZATCA_XML_PROFILE_VERSION` (via `XmlGeneratorFactory`) |
| `src/Services/SDK/OutputParsers/V3_4_8/` | **Written in the code**, not config (see section 2) |
| `tests/Fixtures/SdkOutput/V3_4_8/` | Follows the parser folder above |

### ⚠ Three settings that do nothing

These exist in `config/zatca.php` but **no code reads them**. They are documentation only:

- `ubl_version` — the XML we generate has `ProfileID = reporting:1.0` and **no** `UBLVersionID` or `CustomizationID`. UBL 2.1 is enforced by the SDK's XSD files, not by this package.
- `compatibility` — a copy of the table that is never checked.
- `credential_encrypter` — the encryption class is fixed inside `ZatcaServiceProvider`.

Keep them updated by hand when a version changes, or they will slowly become wrong.

---

## 6. AI Support Prompt

When something breaks and you want help from an AI assistant, use the ready-made prompt in **[`AI_SUPPORT_PROMPT.md`](AI_SUPPORT_PROMPT.md)**.

It is kept in its own file so you can copy it in one go. It tells the assistant how this package is built, forbids it from inventing ZATCA behavior, and makes it ask for evidence before proposing a fix.

⚠ Redact `APP_KEY`, private keys, secrets, CSIDs and OTPs before pasting anything into any AI assistant. The instructions in that file list exactly what to strip.

