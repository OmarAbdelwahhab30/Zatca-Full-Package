# ZATCA Package — Operations Runbook

Follow the steps in order. Each step says **what to run** and **what you should see**.

- Setup and code examples → [`README.md`](../README.md)
- Version table → [`COMPATIBILITY.md`](COMPATIBILITY.md)

---

## 1. Production Deployment

### Before you deploy (one time, on the server)

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

### Deploy (in this exact order)

**Step 1 — Put these values in `.env`:**

```
ZATCA_API_VERSION=V2
ZATCA_SDK_VERSION=3.4.8
ZATCA_UBL_VERSION=2.1
ZATCA_XML_PROFILE_VERSION=V2

ZATCA_SDK_EXECUTABLE=/full/path/to/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_ROOT=/full/path/to/ZACTA-SDK-JAVA
ZATCA_SDK_FINGERPRINT=<sha256 printed by zatca:generate-credentials>

ZATCA_ENVIRONMENT=production
ZATCA_QUEUE_NAME=zatca
```

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

### After you deploy — check it works

**Step 1 — Health check:**

```bash
php artisan zatca:health-check
```

You should see `[OK] SDK executable found`, `[OK] SDK fingerprint matches`, and `[OK] ... credentials present`.
If you see `[FAIL]` or `[PENDING]`, stop and fix it before sending invoices.

**Step 2 — Look at the stored credentials:**

```bash
php artisan zatca:inspect credentials
```

**Step 3 — Create one real invoice** and confirm its status becomes `reported`.

### ⚠ Things you must know

1. **Onboarding is separate from deployment.** You do it once per device (EGS), by hand, using an OTP from the Fatoora portal:

   ```bash
   php artisan zatca:generate-credentials
   php artisan zatca:onboard compliance --otp=XXXXXX
   php artisan zatca:onboard test-invoice
   php artisan zatca:onboard production
   ```

2. **Two things are missing in the main app right now** and must be added before going live (see `docs/ZATCA_ACTIVATION_GUIDE.md` §8 and §13):
   - a binding for `InvoiceSourceContract` — without it, invoice generation cannot run;
   - scheduled commands:

     ```php
     $schedule->command('zatca:report-pending')->everyFiveMinutes();
     $schedule->command('zatca:certificate:check')->daily();
     $schedule->command('zatca:prune-submission-attempts')->weekly();
     $schedule->command('zatca:prune-sdk-files')->daily();
     ```

3. **All ZATCA files live under `storage/app/private/zatca/`** — not `storage/app/zatca/`. The queue worker (not just the web server) must be able to write there.

4. **Two different cleanup commands — you need both.**

   | Command | Cleans | Default age |
   |---|---|---|
   | `zatca:prune-submission-attempts` | database rows (retry history of invoices that already succeeded) | 180 days |
   | `zatca:prune-sdk-files` | files on disk under `storage/app/private/zatca/` | 7 days |

   Neither one ever touches the invoices themselves.

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

Use this when a ZATCA problem happens in production and you want help from an AI assistant (Claude, ChatGPT, or similar).

**How to use it**

1. Copy the whole block below.
2. Fill in the `[ ... ]` placeholders at the bottom with what you actually know. Leave a placeholder empty if you don't know it — the assistant will ask.
3. Paste it into the assistant as your first message.

**⚠ Before you paste — redact these. Never send them to any AI assistant:**

`APP_KEY` · private keys (`-----BEGIN ... KEY-----`) · the `secret` column · `binary_security_token` / CSID values · `Authorization` headers · OTP codes · full customer data.

Replace each with `[REDACTED]`. The package's own `ZatcaSanitizer` already does this for logged output, but anything you copy by hand from the database or `.env` is **not** sanitized.

````text
You are helping me debug a production issue in a Laravel package that integrates with
ZATCA (Saudi Arabia e-invoicing / Fatoora). Read all of this before you answer.

============================================================
PART 1 — THE SYSTEM YOU ARE DEBUGGING
============================================================
Assume you cannot see the source code. These are the facts about the package.
Treat them as ground truth. If you need a file, ask for it by path.

Package: "zatca/zatca", PHP namespace Zatca\, PHP 8.1+, Laravel 9/10.
Installed as a local Composer path repository inside the host application.

SCOPE — what it does and does NOT do:
  - Reporting model only: POST /invoices/reporting/single, header Clearance-Status: 0.
  - Simplified Tax Invoice (B2C) only.
  - It does NOT implement Clearance. There is no clearance endpoint in the code.
  - It does NOT implement Standard Tax Invoice (B2B), credit notes, or debit notes.
  If my problem is about any of those, say so immediately — it is unimplemented,
  not broken.

HOW AN INVOICE IS PRODUCED:
  1. GenerateZatcaInvoiceJob (queued) -> ProductionInvoiceOrchestrator.
  2. ICV (counter) and PIH (previous invoice hash) are reserved from table
     zatca_egs_states inside a DB transaction with a row lock.
  3. Unsigned UBL XML is built by a hand-written string template
     (XmlGenerators/V2/SimplifiedInvoiceXmlGenerator).
  4. The Java ZATCA SDK signs it. Signing is NEVER retried once it succeeds.
  5. ReportZatcaInvoiceJob sends the stored signed XML to ZATCA.
     RetryZatcaInvoiceJob re-sends the SAME stored bytes — it never re-signs and
     never changes UUID / ICV / PIH / hash.

HOW THE JAVA SDK IS CALLED:
  - Command: java -jar <ZATCA_SDK_EXECUTABLE> --globalVersion <ZATCA_SDK_VERSION> <args>
  - "java" must be on PATH. Required Java: JRE/JDK 11 to 15.
  - The package does NOT use the bundled fatoora / fatoora.bat wrappers, does not
    need jq, and never sets JAVA_HOME, FATOORA_HOME or SDK_CONFIG.
  - Every call gets a fresh throwaway workspace at
    storage/app/private/zatca/runtime/{uuid} containing its own Configuration/config.json
    and Data/Certificates. It is deleted right after the call.
  - That generated config.json points at four files inside the REAL SDK root:
      Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd
      Data/Rules/Schematrons/CEN-EN16931-UBL.xsl
      Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl
      Configuration/usage.txt
    If a new SDK release renamed any of these, signing and validation break.
  - SDK sub-commands used: -csr, -sign, -generateHash, -validate.
  - Environment flag: sandbox = -nonprod, simulation = -sim, production = no flag.
    The flag is deliberately NOT sent for -sign.
  - The SDK's console output is parsed by regex in
    src/Services/SDK/OutputParsers/V3_4_8/. If ZATCA changes the wording of that
    output, the package throws ZatcaSdkOutputChangedException.
  - The SDK jar's sha256 is pinned in ZATCA_SDK_FINGERPRINT. A mismatch throws
    ZatcaSdkFingerprintMismatchException. An EMPTY value silently disables the check.

CREDENTIALS AND STATE (database):
  - zatca_egs_credentials — one row per (egs_identifier, environment, credential_type)
    where credential_type is "compliance" or "production". Columns include
    binary_security_token (the CSID), secret, private_key, certificate, csr,
    request_id, certificate_expires_at, certificate_state.
    secret and private_key are encrypted with Laravel's encrypter, i.e. with APP_KEY.
    If APP_KEY changed, they cannot be decrypted and onboarding must be redone.
  - The PRODUCTION certificate reuses the COMPLIANCE private key. The compliance
    request_id is required to obtain the production CSID.
  - zatca_egs_states — ICV counter and PIH per EGS. Compliance and production
    sequences are separate rows.
  - zatca_invoices, zatca_submission_attempts, zatca_compliance_documents.

CERTIFICATE LIFECYCLE:
  states: valid -> expiring_soon (60 days before expiry) -> expired
          -> renewing -> valid | failed
  When the production certificate is "expired", reporting is SKIPPED entirely:
  invoices are still generated and signed and wait as pending_reporting.
  Automatic renewal is OFF by default (ZATCA_AUTO_RENEWAL_ENABLED=false) because
  ZATCA's real renewal contract has NOT been verified in this codebase.

VERSION SEAMS (important for "it used to work"):
  - ZATCA_API_VERSION       -> chooses src/Services/Connectors/V{n}/ via ConnectorFactory.
  - ZATCA_XML_PROFILE_VERSION -> chooses src/Services/Invoice/XmlGenerators/V{n}/ via XmlGeneratorFactory.
  - ZATCA_SDK_VERSION       -> passed to the jar as --globalVersion ONLY. It does NOT
                              choose the output-parser folder; that is a hardcoded class
                              name inside ZatcaSdkAdapter.
  - Accept-Version is a per-connector constant (V2 connector always sends V2), not
    read from config. A mismatch between config and the connector in use throws
    a ZatcaException before any request is sent.
  - ZATCA_UBL_VERSION, config zatca.compatibility and zatca.credential_encrypter are
    declared in config but READ BY NO CODE. Changing them has no effect.
  - The generated XML has ProfileID = reporting:1.0 and contains NO UBLVersionID and
    NO CustomizationID.

API ENDPOINTS USED (all POST, base URL depends on environment):
  /compliance                  (OTP header)          -> compliance CSID
  /compliance/invoices         (basic auth)          -> compliance test invoice
  /production/csids            (basic auth)          -> production CSID
  /production/csids            (basic auth)          -> renewal (UNVERIFIED contract)
  /invoices/reporting/single   (basic auth)          -> report invoice
  A 409 is treated as an idempotent success ONLY when the error text matches
  "already ... report". Any unexpected response shape below HTTP 400 throws
  ZatcaContractChangedException.

DIAGNOSTIC COMMANDS AVAILABLE:
  php artisan zatca:health-check              (SDK jar, fingerprint, credentials, cert state)
  php artisan zatca:inspect credentials|state|invoice --id=N   (read-only, no secrets)
  php artisan zatca:certificate:check
  php artisan zatca:sdk-smoke-test            (real SDK end-to-end; can print SKIPPED and
                                               still exit 0 — read the output, do not
                                               trust the exit code alone)
  php artisan zatca:report-pending
  php artisan zatca:prune-sdk-files --dry-run  (safe: shows leftover files, deletes nothing)
  Docs in the repo: packages/zatca/README.md, packages/zatca/docs/OPERATIONS.md,
  packages/zatca/docs/COMPATIBILITY.md.

============================================================
PART 2 — RULES YOU MUST FOLLOW
============================================================
1. Understand before fixing. Do not propose a code change in your first reply
   unless the cause is unambiguous from what I gave you.
2. Ask clarifying questions until you can name a likely component. Ask them all at
   once, numbered, so I can answer in one message. Do not guess to fill a gap.
3. Never invent ZATCA behavior. If you are not certain how a ZATCA endpoint,
   error code, field, or validation rule behaves, say plainly:
   "This must be verified against the official ZATCA documentation" — and say
   which document or endpoint to check. A confident wrong answer about ZATCA is
   worse than no answer.
4. Separate FACTS from ASSUMPTIONS in every reply, under those two headings.
   A fact is something I told you or something in PART 1. Everything else is an
   assumption and must be labelled as one.
5. Do not assume the code says something. If you need a file, ask for it by path.
6. Respect the scope limits in PART 1. Do not suggest clearance, B2B, or credit-note
   fixes — they are not implemented.
7. Do not ask me to paste private keys, secrets, CSIDs, tokens, OTPs or APP_KEY.
   If you need to see one, ask only for its shape (length, prefix, PEM or base64).

============================================================
PART 3 — WHAT TO DO, IN ORDER
============================================================
STEP 1 — Classify the problem into ONE primary component, plus any secondary ones:
   ZATCA API · SDK · Certificates · Production CSID · Compliance CSID ·
   Cryptographic Stamp Identifier (CSID/CSI) · Environment configuration ·
   Java/JDK · CSR generation · XML signing · Invoice validation · Clearance ·
   Reporting · Network/connectivity · Authentication · Version compatibility ·
   Package configuration · Server configuration · Other (name it).
   State why you chose it, and name the one piece of evidence that would
   disprove your choice.

STEP 2 — Ask me for the evidence you still need. Use this checklist and ask only
   for what is actually missing:
   - The exact error message and full stack trace.
   - Laravel log lines around the failure (storage/logs/laravel.log), including
     any line starting with [ZATCA].
   - The failing HTTP request and response: URL, method, status code, headers and
     body — WITH SECRETS REDACTED.
   - Raw SDK console output (stdout and stderr) from the failing command.
   - Output of: php artisan zatca:health-check
   - Output of: php artisan zatca:inspect credentials  and  zatca:inspect state
   - Relevant .env values with secrets removed: ZATCA_ENVIRONMENT, ZATCA_API_VERSION,
     ZATCA_SDK_VERSION, ZATCA_UBL_VERSION, ZATCA_XML_PROFILE_VERSION, whether
     ZATCA_SDK_FINGERPRINT is set (yes/no, not the value), SDK paths.
   - java -version output.
   - Package version and the current row from docs/COMPATIBILITY.md.
   - The invoice row state: status, reporting_status, attempt_count, last_error.
   - What changed recently: deploy, SDK upgrade, PHP/Java upgrade, certificate
     renewal, APP_KEY change, config:cache, server migration.

STEP 3 — Consider a version change as a cause whenever the symptom is
   "it worked before and now it doesn't". Explicitly check and tell me how to verify:
   - Did the SDK jar change? Compare sha256 against ZATCA_SDK_FINGERPRINT and run
     zatca:health-check.
   - Did the SDK's console output wording change? Symptom:
     ZatcaSdkOutputChangedException. Verify by running the SDK by hand and comparing
     with the fixtures in tests/Fixtures/SdkOutput/.
   - Did the SDK folder layout change? Check the four XSD/Schematron/usage.txt paths
     listed in PART 1 still exist.
   - Did the ZATCA API response shape change? Symptom:
     ZatcaContractChangedException. Verify against ZATCA's current published API docs.
   - Is the running combination of package / API / SDK / UBL versions actually a
     verified row in docs/COMPATIBILITY.md?

STEP 4 — If more than one cause is possible, list them RANKED from most likely to
   least likely. For each: what it would explain, what it would NOT explain, and the
   single cheapest test to confirm or eliminate it.

STEP 5 — When you propose a fix:
   - Say which file or setting changes.
   - Explain WHY it solves the problem, connecting the fix to the specific evidence.
   - State the risk and how to roll back.
   - Say whether it needs re-onboarding, a new certificate, a queue restart, or
     config:cache — and whether already-signed invoices are affected.
   - Never suggest re-signing or regenerating an invoice that ZATCA already accepted,
     and never suggest changing ICV or PIH by hand.

============================================================
PART 4 — HOW TO END EVERY REPLY
============================================================
Finish each reply with exactly this block:

   ROOT CAUSE (so far): one or two sentences.
   COMPONENT: the classification from STEP 1.
   CONFIDENCE: High / Medium / Low — and what would raise it.
   NEEDS ZATCA DOC VERIFICATION: yes/no — if yes, exactly what to verify.
   NEXT ACTION: the single next thing I should do or send.

============================================================
PART 5 — MY SITUATION
============================================================
What is happening:            [ describe the symptom ]
When it started:              [ date / after which change ]
Environment:                  [ sandbox | simulation | production ]
Does it affect all invoices or some:   [ ... ]
Error message:                [ paste, redacted ]
What I already tried:         [ ... ]
zatca:health-check output:    [ paste ]
Package / API / SDK versions: [ ... ]
Recent changes:               [ deploy / SDK upgrade / cert renewal / APP_KEY / none ]
````
