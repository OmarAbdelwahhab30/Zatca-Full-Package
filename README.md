# Zatca Package

A Laravel package that connects any Laravel app to **ZATCA** (Saudi Arabia's e-invoicing authority, also called **FATOORA**). It handles everything needed to generate, sign, and report electronic invoices — automatically, through Laravel Queue Jobs, with no manual steps.

This is the company-wide standard package for ZATCA integration. If your app needs to send invoices to ZATCA, you use this package.

> **Version 1.0.0 — B2C only.** This version supports **Simplified Tax Invoices** for individual customers (B2C), using the **Reporting** model. See [Roadmap](#roadmap) for what is planned next.

> **Production hardening note:** this README reflects the package after a production-readiness audit and hardening pass (see `docs/ZATCA_PRODUCTION_HARDENING_REPORT.md`). Two things changed that existing integrations should know about:
> - `GenerateZatcaInvoiceJob` now takes `(string $sourceType, string $sourceId, string $egsIdentifier = 'default')` — or use `GenerateZatcaInvoiceJob::forModel($order)` — instead of the model/object itself. See [Quick Start](#quick-start).
> - Automatic certificate renewal is **disabled by default** (`ZATCA_AUTO_RENEWAL_ENABLED=false`) until ZATCA's real renewal contract is verified. See [Certificate Lifecycle](#certificate-lifecycle).

---

## Table of Contents

- [Package Overview](#package-overview)
- [Supported Versions](#supported-versions)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture Overview](#architecture-overview)
- [Quick Start](#quick-start)
- [Commands](#commands)
- [Public API](#public-api)
- [Invoice Generation](#invoice-generation)
- [Onboarding](#onboarding)
- [Production Reporting](#production-reporting)
- [Certificate Lifecycle](#certificate-lifecycle)
- [Error Handling](#error-handling)
- [Health Check & Diagnostics](#health-check--diagnostics)
- [Logging](#logging)
- [Testing](#testing)
- [Examples](#examples)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Changelog / Versioning](#changelog--versioning)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

---

## Package Overview

### What the package does

ZATCA requires every business in Saudi Arabia to send electronic invoices in a specific signed XML format. Doing this by hand means dealing with certificates, XML signing, QR codes, and a Java command-line tool. This package removes all of that manual work.

Give the package an order (or any "paid" record), and it will:

1. Build a ZATCA-compliant invoice (UUID, invoice counter, hash chain).
2. Generate the invoice XML.
3. Sign it using the ZATCA SDK (a Java tool — the package runs it for you).
4. Generate the QR code.
5. Send it to ZATCA.
6. Store the result and retry automatically if ZATCA is down.

### Main features

- **Fully automatic** — no manual PowerShell, Postman, or command-line steps in production.
- **Queue-based** — invoice signing and reporting always run in background jobs, never during the request/webhook that triggered them.
- **Safe retries** — a failed report is retried without ever re-signing or regenerating the invoice.
- **Idempotent** — dispatching invoice generation twice for the same order never creates a duplicate.
- **Encrypted secrets** — tokens, secrets, and private keys are encrypted at rest and never appear in logs.
- **Multi-EGS ready** — the package is built so a company can register more than one device/branch (EGS) later, without code changes.
- **Certificate lifecycle tracking** — the package watches certificate expiry and can renew automatically.

### Supported ZATCA phases and workflows

| Phase | Supported in v1.0.0? |
|---|---|
| Onboarding (Compliance CSID, Compliance Invoice check, Production CSID) | ✅ Yes |
| Simplified Tax Invoice (B2C) | ✅ Yes |
| Reporting model (`POST /invoices/reporting/single`) | ✅ Yes |
| Standard Tax Invoice (B2B) | ❌ Not in v1 |
| Credit Note / Debit Note | ❌ Not in v1 |
| Clearance model | ❌ Not in v1 |

---

## Supported Versions

| Component | Supported version(s) |
|---|---|
| PHP | 8.1 or newer |
| Laravel | 9.x or 10.x |
| ZATCA API version | `V2` |
| ZATCA SDK (Java) | `3.4.8` |
| UBL version | `2.1` |
| Package version | `1.0.0` |

These are pinned on purpose. See `docs/COMPATIBILITY.md` inside the package for the full compatibility table and the process for adding a new version combination.

---

## Installation

### 1. Require the package with Composer

If the package is hosted on GitHub (private or public repo), add it as a path or VCS repository in your app's root `composer.json`, then require it:

```bash
composer require zatca/zatca
```

*(If you're pulling this package directly from this monorepo, it is already wired up via a Composer path repository — you only need to run `composer install`.)*

### 2. Let Laravel discover the package

```bash
php artisan package:discover
```

`Zatca\ZatcaServiceProvider` registers itself automatically — you do not need to add it to `config/app.php`.

### 3. Publish the config file (optional)

The package works with sane defaults out of the box. If you want to see/edit every option:

```bash
php artisan vendor:publish --tag=zatca-config
```

This creates `config/zatca.php` in your app.

### 4. Run migrations

```bash
php artisan migrate
```

This creates 5 tables: `zatca_egs_credentials`, `zatca_egs_states`, `zatca_compliance_documents`, `zatca_invoices`, `zatca_submission_attempts`.

### 5. Java / SDK prerequisites

The package drives the official ZATCA Java SDK. You must install this **on the server**, separately from Composer:

- **Java**: JRE or JDK version **11 to 15**. Check with `java -version`.
- **ZATCA SDK folder**: download the SDK zip from ZATCA and unzip it somewhere on the server (e.g. `/opt/zatca-sdk`). You need the whole folder — `Apps/`, `Configuration/`, `Data/`.

You do **not** need `jq`, and you do **not** need to run the SDK's own `install.sh`/`install.bat` — the package calls the Java `.jar` file directly.

### 6. Filesystem / storage requirements

The package writes temporary files under `storage/app/zatca/`:

- `storage/app/zatca/runtime/` — one folder per SDK call, created and deleted automatically.
- `storage/app/zatca/tmp/` — temporary certificate/key files, deleted right after use.

Make sure the **queue worker process** (not just the web server) can write to `storage/app/`.

### 7. Required environment variables

Add these to your `.env`:

```env
# --- Version pins (do not change unless upgrading intentionally) ---
ZATCA_API_VERSION=V2
ZATCA_UBL_VERSION=2.1
ZATCA_XML_PROFILE_VERSION=V2

# --- ZATCA SDK ---
ZATCA_SDK_EXECUTABLE=/absolute/path/to/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_ROOT=/absolute/path/to/zatca-sdk-folder
ZATCA_SDK_VERSION=3.4.8
ZATCA_SDK_FINGERPRINT=
ZATCA_SDK_PROCESS_TIMEOUT=60

# --- Environment: sandbox | simulation | production ---
ZATCA_ENVIRONMENT=sandbox

# --- Your business / device (EGS) data ---
ZATCA_EGS_IDENTIFIER=default
ZATCA_VAT_NUMBER=399999999900003
ZATCA_ORG_NAME="Your Legal Org Name"
ZATCA_ORG_UNIT_NAME="Your Unit Name"
ZATCA_CSR_COMMON_NAME=TST-YOURORG-EGS-01
ZATCA_CSR_SERIAL_NUMBER="1-YOURORG|2-EGS01|3-<uuid>"
ZATCA_ORG_IDENTIFIER=399999999900003
ZATCA_COUNTRY_CODE=SA
ZATCA_INVOICE_TYPE=0100
ZATCA_LOCATION_ADDRESS="Your street address"
ZATCA_BUSINESS_CATEGORY="Education"
ZATCA_CRN=1010010000

# --- HTTP ---
ZATCA_TIMEOUT=30
ZATCA_ACCEPT_LANGUAGE=ar

# --- Retries (generation and reporting are separate policies) ---
ZATCA_GENERATION_RETRY_TRIES=5
ZATCA_REPORTING_RETRY_ATTEMPTS=4

# --- Certificate renewal — leave false until the real ZATCA renewal contract is verified ---
ZATCA_AUTO_RENEWAL_ENABLED=false

# --- Queue ---
ZATCA_QUEUE_CONNECTION=database
ZATCA_QUEUE_NAME=zatca

# --- Housekeeping ---
ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS=180
```

> **Never** put real ZATCA tokens, secrets, or private keys in `.env`. Those are created by the onboarding commands and stored **encrypted** in the database.

---

## Configuration

All options live in `config/zatca.php`. Here is what every key does.

### Top-level

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `api_version` | `ZATCA_API_VERSION` | `V2` | Which ZATCA API version the connector uses. | Only when ZATCA releases a new API version **and** the package has added support for it. |
| `ubl_version` | `ZATCA_UBL_VERSION` | `2.1` | The UBL (invoice XML standard) version. | Only when ZATCA changes the UBL standard. |
| `xml_profile_version` | `ZATCA_XML_PROFILE_VERSION` | `V2` | Which internal XML generator to use. Independent from `api_version` on purpose — the invoice XML format and the transport API can change on different schedules. | Only when a new XML generator version is added to the package. |

### `sdk`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `sdk.executable` | `ZATCA_SDK_EXECUTABLE` | *(none)* | Absolute path to the ZATCA SDK `.jar` file. | Set once per server, during install. |
| `sdk.root` | `ZATCA_SDK_ROOT` | *(none)* | Absolute path to the SDK's root folder (containing `Apps/`, `Configuration/`, `Data/`). | Set once per server, during install. |
| `sdk.version` | `ZATCA_SDK_VERSION` | `3.4.8` | The SDK version the package is pinned to. Controls which output parser is used. | Only when upgrading the SDK jar itself. |
| `sdk.expected_fingerprint` | `ZATCA_SDK_FINGERPRINT` | *(none)* | SHA-256 checksum of the SDK jar, used by `zatca:health-check` to detect if the jar file changed unexpectedly. | Set after running `zatca:generate-credentials` for the first time (it prints the fingerprint). |
| `sdk.process_timeout` | `ZATCA_SDK_PROCESS_TIMEOUT` | `60` | Seconds before an SDK process call is killed. | Increase if the server is slow or under heavy load. |

### `http`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `http.base_urls.sandbox` | `ZATCA_API_BASE_URL_SANDBOX` | ZATCA sandbox URL | Base URL used when `ZATCA_ENVIRONMENT=sandbox`. | Only if ZATCA changes their sandbox host. |
| `http.base_urls.simulation` | `ZATCA_API_BASE_URL_SIMULATION` | ZATCA simulation URL | Base URL used when `ZATCA_ENVIRONMENT=simulation`. | Only if ZATCA changes their simulation host. |
| `http.base_urls.production` | `ZATCA_API_BASE_URL_PRODUCTION` | ZATCA production URL | Base URL used when `ZATCA_ENVIRONMENT=production`. | Only if ZATCA changes their production host. |
| `http.timeout` | `ZATCA_TIMEOUT` | `30` | Seconds before an HTTP call to ZATCA times out. | Increase on a slow network. |
| `http.accept_language` | `ZATCA_ACCEPT_LANGUAGE` | `ar` | Language ZATCA uses in its response messages. `en` or `ar`. | Change if your admin team prefers English error messages. |

### `retries` — two distinct policies, never confuse them

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `retries.generation.tries` | `ZATCA_GENERATION_RETRY_TRIES` | `5` | How many times Laravel retries `GenerateZatcaInvoiceJob` itself (signing/creation) if it throws. | Rarely. |
| `retries.generation.backoff_seconds` | *(array in config file)* | `[5, 30, 120, 600]` | Delay before each `GenerateZatcaInvoiceJob` retry. | Adjust pacing if needed. |
| `retries.reporting.max_attempts` | `ZATCA_REPORTING_RETRY_ATTEMPTS` | `4` | How many times a **signed** invoice may be re-submitted to ZATCA before moving to `manual_review`. Reporting never regenerates the invoice — only re-sends the same stored hash/UUID/XML. | Rarely — 4 is a safe default. |

### `certificate`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `certificate.alert_days` | *(array in config file)* | `[60, 30, 7]` | Days before certificate expiry when the certificate is flagged `ExpiringSoon`. | Adjust to fit your renewal process. |
| `certificate.auto_renewal_enabled` | `ZATCA_AUTO_RENEWAL_ENABLED` | `false` | Whether `zatca:certificate:renew` (and any future scheduled renewal) is allowed to actually call ZATCA. **Off by default** because ZATCA's real renewal contract (endpoint, whether a new OTP is required, response shape) has not been verified against an official document in this repository — see [Certificate Lifecycle](#certificate-lifecycle). | Only turn this on after you've verified the real renewal contract and confirmed `HttpConnector::renewProductionCsid()` implements it correctly. |

### `credential_encrypter`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `credential_encrypter` | `ZATCA_CREDENTIAL_ENCRYPTER` | `laravel` | Which secret-encryption backend to use. The package ships with Laravel's own encrypter (`APP_KEY`-based). | Only if you build and bind your own `CredentialEncrypterInterface` implementation (e.g. for Vault/KMS). |

### `queue`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `queue.connection` | `ZATCA_QUEUE_CONNECTION` | Your app's `QUEUE_CONNECTION` | Which queue connection ZATCA jobs use. | Set to match your production queue driver (e.g. `redis`, `sqs`). |
| `queue.queue` | `ZATCA_QUEUE_NAME` | `zatca` | Which named queue ZATCA jobs run on. | Change if you want ZATCA jobs isolated on their own queue/worker. |

### `submission_attempts`

| Key | Env variable | Default | What it does | When to change it |
|---|---|---|---|---|
| `submission_attempts.prune_after_days` | `ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS` | `180` | How long to keep old attempt-history rows for successfully reported invoices. | Lower it to save database space; raise it if you need a longer audit trail. |

### `egs`

An array keyed by EGS identifier (device/branch). Each entry holds:

| Key | Env variable | Default | What it does |
|---|---|---|---|
| `environment` | `ZATCA_ENVIRONMENT` | `sandbox` | `sandbox`, `simulation`, or `production`. |
| `vat_number` | `ZATCA_VAT_NUMBER` | *(none)* | Your company's VAT registration number. |
| `organization_name` | `ZATCA_ORG_NAME` | *(none)* | Legal organization name (used in the invoice XML). |
| `organization_unit_name` | `ZATCA_ORG_UNIT_NAME` | *(none)* | Organization unit name (used in the CSR). |
| `common_name` | `ZATCA_CSR_COMMON_NAME` | *(none)* | CSR common name. |
| `serial_number` | `ZATCA_CSR_SERIAL_NUMBER` | *(none)* | CSR serial number (a specific ZATCA-defined format). |
| `organization_identifier` | `ZATCA_ORG_IDENTIFIER` | *(none)* | Organization identifier for the CSR (usually same as VAT number). |
| `country_code` | `ZATCA_COUNTRY_CODE` | `SA` | Country code. |
| `invoice_type` | `ZATCA_INVOICE_TYPE` | `0100` | 4-digit invoice type flag used in the CSR. |
| `location_address` | `ZATCA_LOCATION_ADDRESS` | *(none)* | Business address. |
| `business_category` | `ZATCA_BUSINESS_CATEGORY` | *(none)* | Industry/business category. |
| `commercial_registration_number` | `ZATCA_CRN` | *(none)* | Commercial Registration Number, shown in the invoice XML. |

Change these only when your business details change, or when adding a second EGS.

### `compatibility`

A read-only array documenting which `api_version` + `sdk_version` + `ubl_version` combinations are supported. Do not edit this by hand — see `docs/COMPATIBILITY.md`.

---

## Architecture Overview

The package is organized so that each concern lives in exactly one place:

```
Console/Commands/     Artisan commands (onboarding, health checks, certificate management)
Contracts/            Interfaces — the seams where you (or the package) can swap implementations
DTOs/                 Plain data objects passed between layers (no behavior, just data)
Enums/                Fixed sets of values (environment, status, credential type...)
Events/               Laravel events fired by the package
Exceptions/           Typed exceptions for specific failure cases
Jobs/                 Queueable jobs — invoice generation and reporting always run here
Models/                Eloquent models for the 5 database tables
Services/
  Certificate/        Certificate state machine (expiry, renewal)
  Connectors/          HTTP calls to ZATCA, versioned by API version (V2 today)
  Diagnostics/         Read-only inspection tool
  Execute/             Turns an order into a signed, stored invoice
  Invoice/             XML generation
  OnBoarding/          Drives the 3 onboarding API calls
  SDK/                 Wraps the Java SDK — the only place that runs external processes
  Security/            Credential encryption/storage, CSR generation
State/                 ICV (invoice counter) and PIH (previous invoice hash) managers
```

### Key ideas

- **Everything EGS-specific goes through `EgsRegistry`.** No code reads `config('zatca.egs.*')` directly — it always asks `EgsRegistry::for($egsIdentifier)`. This means adding a second business/branch later is a config change, not a code change.
- **Compliance and Production are always kept separate.** Different database rows, different ICV/PIH sequences, different certificates. They can never mix by accident.
- **The SDK is only ever called through `ZatcaSdkAdapter`.** Every call gets its own temporary, isolated folder. Nothing is shared between invoices, even if two invoices are being signed at the same time.
- **Signing happens once. Reporting can happen many times.** Once an invoice is signed, its UUID/hash/XML never change — even if reporting is retried 10 times.

---

## Quick Start

This is the smallest path from installation to your first invoice.

### 1. Install and configure (see [Installation](#installation) above)

### 2. Implement `InvoiceSourceContract` in your app

The package doesn't know what your "Order" model looks like. You tell it, once:

```php
<?php

namespace App\Zatca;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use App\Models\Order;

class OrderInvoiceSource implements InvoiceSourceContract
{
    public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
    {
        /** @var Order $source */
        $line = new ZatcaInvoiceLine(
            description: $source->course->title,
            quantity: 1,
            unitPrice: $source->price_excl_vat,
            vatRate: 15.0,
            lineTotalExclVat: $source->price_excl_vat,
            vatAmount: $source->vat_amount,
        );

        return new ZatcaInvoiceData(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($source),
            buyerName: $source->student->name,
            buyerVatNumber: null,
            issueDateTime: new \DateTimeImmutable(),
            currency: 'SAR',
            lines: [$line],
            totalExclVat: $source->price_excl_vat,
            totalVat: $source->vat_amount,
            totalInclVat: $source->total_amount,
        );
    }

    public function sourceReference(mixed $source): string
    {
        return 'order:' . $source->id;
    }
}
```

### 3. Bind it in your app's service provider

```php
// app/Providers/AppServiceProvider.php
use Zatca\Contracts\InvoiceSourceContract;
use App\Zatca\OrderInvoiceSource;

public function register(): void
{
    $this->app->bind(InvoiceSourceContract::class, OrderInvoiceSource::class);
}
```

### 4. Onboard your EGS once (sandbox first — see [Onboarding](#onboarding))

```bash
php artisan zatca:generate-credentials
php artisan zatca:onboard compliance --otp=123456
php artisan zatca:onboard test-invoice
php artisan zatca:onboard production
```

### 5. Dispatch invoice generation after a successful payment

`GenerateZatcaInvoiceJob` only ever carries scalar reference data (`source_type`/`source_id`/`egs_identifier`) — never your model object — so the queued payload can never go stale or fail to serialize. The record is reloaded fresh when the job actually runs, via `InvoiceSourceResolverContract` (a default binding already handles plain Eloquent models — see [Public API](#public-api) if you need something else):

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;

// Inside your "payment confirmed" flow — never inside the payment webhook itself:
dispatch(GenerateZatcaInvoiceJob::forModel($order, egsIdentifier: 'default'));

// Equivalent, if you prefer to be explicit:
GenerateZatcaInvoiceJob::dispatch(Order::class, (string) $order->id, 'default');
```

### 6. Run the queue worker and check the result

```bash
php artisan queue:work --queue=zatca
php artisan zatca:inspect invoice --id=1
```

That's it — the invoice is signed, reported to ZATCA, and stored.

---

## Commands

Every command below is registered automatically once the package is installed. Run `php artisan list zatca` to see them all.

### `zatca:generate-credentials`

**Purpose:** Generates the private key and CSR (Certificate Signing Request) for an EGS, using the real ZATCA SDK. No API call is made.

**Syntax:**
```bash
php artisan zatca:generate-credentials {--egs=default}
```

**Options:**
| Option | Default | Description |
|---|---|---|
| `--egs` | `default` | The EGS identifier to generate credentials for. |

**Example:**
```bash
php artisan zatca:generate-credentials
```

**Expected output:**
```
Generating private key + CSR for EGS [default] via the ZATCA SDK...
Private key and CSR generated and stored (encrypted) successfully.
SDK fingerprint: 48abeb828d453ef6fafba792fddbbb2701da5c7018c24bde918853e80ff5d530
Set this as ZATCA_SDK_FINGERPRINT in .env so `zatca:health-check` can detect unexpected SDK changes.

CSR (submit via `zatca:onboard compliance --otp=XXXXXX`):
LS0tLS1CRUdJTiBDRVJUSUZJQ0FURSBSRVFVRVNULS0t...
```

**ZATCA API used:** None — this only runs the local SDK.

**Common errors:**
- `Configured ZATCA_SDK_EXECUTABLE [...] does not point to an existing SDK jar file.` — check `ZATCA_SDK_EXECUTABLE` in `.env`.
- `Configured ZATCA_SDK_ROOT [...] does not point to an existing directory.` — check `ZATCA_SDK_ROOT` in `.env`.

---

### `zatca:onboard {step}`

**Purpose:** Runs one step of the 3-step ZATCA onboarding process.

**Syntax:**
```bash
php artisan zatca:onboard {step} {--egs=default} {--otp=}
```

**Parameters/Options:**
| Name | Required | Description |
|---|---|---|
| `step` | Yes | One of: `compliance`, `test-invoice`, `production`. |
| `--egs` | No | EGS identifier. Default: `default`. |
| `--otp` | Only for `compliance` | The OTP code from the ZATCA Fatoora portal. |

#### `zatca:onboard compliance --otp=XXXXXX`

**Purpose:** Requests the Compliance CSID (certificate) using the OTP from the Fatoora portal.

**Example:**
```bash
php artisan zatca:onboard compliance --otp=123456
```

**Expected output:**
```
Requesting Compliance CSID for EGS [default]...
Compliance Token, Secret, Certificate and Request ID saved.
Request ID: 1234567890123
```

**ZATCA API used:** `POST /compliance`

**Common errors:**
- `The provided OTP is invalid` — get a fresh OTP from the Fatoora portal (they expire quickly).
- `No CSR on record for EGS [...]` — run `zatca:generate-credentials` first.

#### `zatca:onboard test-invoice`

**Purpose:** Builds a canned test invoice, signs it, validates it, and submits it to ZATCA's Compliance Invoice check — fully automatically.

**Example:**
```bash
php artisan zatca:onboard test-invoice
```

**Expected output:**
```
Generating, signing, validating and submitting the compliance test invoice for EGS [default]...
Status: PASS
```

(If ZATCA finds a non-blocking issue, `Status: WARNING` is shown along with each warning line.)

**ZATCA API used:** `POST /compliance/invoices`

**Common errors:**
- `Status: ERROR` with error lines printed — read the message, it tells you exactly what ZATCA rejected.

#### `zatca:onboard production`

**Purpose:** Requests the Production CSID (the certificate used for real customer invoices).

**Example:**
```bash
php artisan zatca:onboard production
```

**Expected output:**
```
Requesting Production CSID for EGS [default]...
Production Token, Secret and Certificate saved. Reporting is now live for this EGS.
```

**ZATCA API used:** `POST /production/csids`

**Common errors:**
- `No compliance request ID on record for EGS [...]` — run `zatca:onboard compliance` first.

---

### `zatca:report-pending`

**Purpose:** Re-dispatches reporting for every invoice still `pending_reporting`, `retrying`, or `failed` — **without regenerating them**. Meant to run on a schedule.

**Syntax:**
```bash
php artisan zatca:report-pending
```

**Example:**
```bash
php artisan zatca:report-pending
```

**Expected output:**
```
Dispatched 3 pending invoice(s) for reporting.
```

**ZATCA API used:** Indirectly, `POST /invoices/reporting/single` (through the jobs it dispatches).

**Common errors:** None specific — this command only dispatches jobs; failures show up on the individual invoices (`zatca:inspect invoice`).

---

### `zatca:inspect {resource}`

**Purpose:** Read-only inspection of credentials, EGS state, or one invoice. Never prints secrets, private keys, or raw XML.

**Syntax:**
```bash
php artisan zatca:inspect {resource} {--egs=default} {--id=}
```

**Parameters/Options:**
| Name | Required | Description |
|---|---|---|
| `resource` | Yes | One of: `credentials`, `state`, `invoice`. |
| `--egs` | No | EGS identifier. Default: `default`. |
| `--id` | Only for `invoice` | Invoice ID or UUID. |

**Examples:**
```bash
php artisan zatca:inspect credentials
php artisan zatca:inspect state
php artisan zatca:inspect invoice --id=42
```

**Expected output (credentials):**
```json
[
    {
        "credential_type": "compliance",
        "has_token": true,
        "has_certificate": true,
        "has_private_key": true,
        "certificate_state": "valid",
        "certificate_expires_at": "2029-01-09 09:19:30",
        "sdk_fingerprint": null
    }
]
```

**ZATCA API used:** None — reads only from your local database.

**Common errors:**
- `--id is required for the invoice resource.` — pass `--id=<invoice id or uuid>`.

---

### `zatca:health-check`

**Purpose:** Verifies the SDK is reachable, the SDK binary hasn't changed unexpectedly, and every EGS's credentials/certificate state look healthy.

**Syntax:**
```bash
php artisan zatca:health-check
```

**Example:**
```bash
php artisan zatca:health-check
```

**Expected output:**
```
[OK] SDK executable found: /opt/zatca-sdk/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
[OK] SDK fingerprint matches recorded value.
[OK] [default] compliance credentials present — certificate state: valid.
[OK] [default] production credentials present — certificate state: valid.
```

Exit code is non-zero if anything is missing, expired, or failed — safe to use in deployment health checks.

**ZATCA API used:** None — this is a local check only.

**Common errors:**
- `[FAIL] ZATCA SDK binary fingerprint mismatch...` — the jar file on disk changed since `ZATCA_SDK_FINGERPRINT` was recorded. Investigate before trusting signing results.
- `[PENDING] [...] credentials not yet onboarded.` — run the onboarding steps.

---

### `zatca:certificate:check`

**Purpose:** Evaluates certificate expiry state (`valid` → `expiring_soon` → `expired`) for one or all EGS. Meant to run daily via the scheduler.

**Syntax:**
```bash
php artisan zatca:certificate:check {--egs=}
```

**Options:**
| Option | Default | Description |
|---|---|---|
| `--egs` | *(all configured EGS)* | Check only this EGS. |

**Example:**
```bash
php artisan zatca:certificate:check
```

**Expected output:**
```
[default] compliance: valid
[default] production: valid
```

**ZATCA API used:** None — reads the expiry date already stored from onboarding.

**Common errors:** None specific.

---

### `zatca:certificate:renew`

**Purpose:** Renews a certificate that is `expiring_soon`, `expired`, or `failed`, and automatically resumes any invoices left `pending_reporting`.

**Syntax:**
```bash
php artisan zatca:certificate:renew {--egs=default} {--type=production}
```

**Options:**
| Option | Default | Description |
|---|---|---|
| `--egs` | `default` | EGS identifier. |
| `--type` | `production` | `compliance` or `production`. |

**Example:**
```bash
php artisan zatca:certificate:renew --egs=default --type=production
```

**Expected output:**
```
Renewing production certificate for EGS [default]...
Resulting state: active
```

**ZATCA API used:** `POST /production/csids` (re-requests the Production CSID using the existing CSR/private key).

**Common errors:**
- Renewing a `compliance` certificate always fails — compliance certificates cannot be renewed. Re-run `zatca:onboard compliance` with a new OTP instead.
- `Resulting state: failed` — check the logs for the underlying ZATCA error.

---

### `zatca:prune-submission-attempts`

**Purpose:** Deletes old `zatca_submission_attempts` rows for invoices that were already successfully reported, based on `ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS`.

**Syntax:**
```bash
php artisan zatca:prune-submission-attempts
```

**Example:**
```bash
php artisan zatca:prune-submission-attempts
```

**Expected output:**
```
Pruned 12 submission attempt(s) older than 180 days.
```

**ZATCA API used:** None — a local database cleanup only.

**Common errors:** None specific.

---

## Public API

Everything below is a class you can inject (via Laravel's container) or resolve with `app(...)`.

### `InvoiceSourceContract` (interface — you implement this)

| Method | Signature | Purpose |
|---|---|---|
| `toInvoiceData` | `toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData` | Maps your own Order/model into the package's neutral invoice data. |
| `sourceReference` | `sourceReference(mixed $source): string` | Returns a unique idempotency key for the source (e.g. `"order:{$id}"`). |

**Example:** see [Quick Start](#quick-start) step 2.

---

### `GenerateZatcaInvoiceJob` (queued job — your entry point)

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;

dispatch(GenerateZatcaInvoiceJob::forModel($order, egsIdentifier: 'default'));
// or explicitly:
GenerateZatcaInvoiceJob::dispatch(Order::class, (string) $order->id, 'default');
```

| Constructor parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceType` | `string` | — | Usually your model's class name (e.g. `Order::class`). Passed to `InvoiceSourceResolverContract` to reload the record at execution time. |
| `$sourceId` | `string` | — | The record's primary key. |
| `$egsIdentifier` | `string` | `'default'` | Which EGS to generate the invoice for. |

The job carries **only these three scalar strings** — never the model/object itself — so it can never fail to serialize and never goes stale between dispatch and execution. `forModel(Model $model, string $egsIdentifier = 'default')` is a convenience constructor that fills in `$sourceType`/`$sourceId` from an Eloquent model.

**What it does:** Reloads the source via `InvoiceSourceResolverContract`, signs and stores the invoice (via `ProductionInvoiceOrchestrator`), then automatically dispatches `ReportZatcaInvoiceJob`. Safe to dispatch twice for the same order — it is idempotent. If the resolved source is `null` (deleted, or an unrecognized `source_type`), the job fails without retrying — retrying can't fix a record that no longer exists.

---

### `InvoiceSourceResolverContract` (interface — default binding provided)

```php
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\DTOs\InvoiceSourceReference;
```

| Method | Signature | Purpose |
|---|---|---|
| `resolve` | `resolve(InvoiceSourceReference $reference): mixed` | Reloads the host record from `$reference->sourceType` / `$reference->sourceId`. Return `null` if it no longer exists. |

The package ships a default binding, `EloquentInvoiceSourceResolver`, that handles the common case (`source_type` is an Eloquent model class, `source_id` is its primary key) automatically — you don't need to implement this yourself unless your source isn't a plain Eloquent model. To override it:

```php
$this->app->bind(InvoiceSourceResolverContract::class, MyCustomResolver::class);
```

---

### `ProductionInvoiceOrchestrator`

```php
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;

$invoice = app(ProductionInvoiceOrchestrator::class)
    ->generate($invoiceSource, $order, 'default');
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `generate` | `generate(InvoiceSourceContract $source, mixed $sourceRecord, string $egsIdentifier): ZatcaInvoice` | `ZatcaInvoice` model | Signs and stores one invoice. Returns the existing invoice untouched if one already exists for this source — never regenerates. |

You normally don't call this directly — `GenerateZatcaInvoiceJob` does it for you.

---

### `ComplianceOnboardingOrchestrator`

```php
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;

$orchestrator = app(ComplianceOnboardingOrchestrator::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `generateCredentials` | `generateCredentials(string $egsIdentifier): array` | `['csr' => string, 'privateKeyGenerated' => bool, 'sdkFingerprint' => string]` | Generates the CSR/private key. |
| `compliance` | `compliance(string $egsIdentifier, string $otp): array` | `['requestId' => string, 'dispositionMessage' => ?string]` | Calls `POST /compliance`. |
| `testInvoice` | `testInvoice(string $egsIdentifier): array` | `['status' => string, 'warnings' => array, 'errors' => array]` | Calls `POST /compliance/invoices`. |
| `production` | `production(string $egsIdentifier): array` | `['binarySecurityToken' => bool]` | Calls `POST /production/csids`. |

This is what the `zatca:onboard` and `zatca:generate-credentials` commands call internally. Use it directly if you need onboarding from a web UI instead of the console.

---

### `ZatcaCredentialStore`

```php
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaCredentialType;

$store = app(ZatcaCredentialStore::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `has` | `has(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): bool` | `bool` | Whether credentials exist for this EGS/environment/type. |
| `get` | `get(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ZatcaCredentialSet` | `ZatcaCredentialSet` | Decrypted credential bundle. Throws `ZatcaCredentialsMissingException` if not found. |
| `store` | `store(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type, array $data): void` | `void` | Creates/updates a credential row. Encrypts `secret` and `private_key` automatically. |
| `getCsr` | `getCsr(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ?string` | `?string` | Reads the stored CSR (not encrypted — it's not a secret). |

You rarely need to call this directly — onboarding and invoice generation use it internally. Note: there is deliberately **no** general-purpose "give me the raw private key" method here — that access is narrowed to `ZatcaSigningKeyProvider` below.

---

### `ZatcaSigningKeyProvider`

```php
use Zatca\Services\Security\ZatcaSigningKeyProvider;

$provider = app(ZatcaSigningKeyProvider::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `hasProductionCredentials` | `hasProductionCredentials(string $egsIdentifier, ZatcaEnvironment $environment): bool` | `bool` | |
| `hasComplianceCredentials` | `hasComplianceCredentials(string $egsIdentifier, ZatcaEnvironment $environment): bool` | `bool` | |
| `prepareSigningWorkspace` | `prepareSigningWorkspace(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $credentialType, string $unsignedXml): array` | `[certPath, keyPath, xmlPath, cleanup]` | Writes cert/key/XML to a private, `0600`-permissioned, per-call temp workspace. **You must call the returned `cleanup` closure in a `finally` block.** |

This is the **only** place in the package that ever writes decrypted private-key material to disk, and the only place application code needs to go through to sign something. There is no general-purpose method anywhere in the package that hands back a raw private key string to arbitrary calling code — the key only ever exists as file content inside a `prepareSigningWorkspace()` call, for the duration of that call.

---

### `EgsRegistry`

```php
use Zatca\State\EgsRegistry;

$egsConfig = app(EgsRegistry::class)->for('default');
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `for` | `for(string $egsIdentifier): EgsConfig` | `EgsConfig` | Resolves all config for one EGS. Throws if the identifier isn't configured. |
| `identifiers` | `identifiers(): array` | `string[]` | All configured EGS identifiers. |
| `default` | `default(): EgsConfig` | `EgsConfig` | The first configured EGS. |

---

### `ZatcaInspector`

```php
use Zatca\Services\Diagnostics\ZatcaInspector;

$inspector = app(ZatcaInspector::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `credentials` | `credentials(string $egsIdentifier): array` | array of masked credential rows | Never includes secrets/keys. |
| `state` | `state(string $egsIdentifier): array` | ICV + truncated PIH preview | Read-only. |
| `invoice` | `invoice(string $identifier): ?array` | Invoice summary, or `null` | Accepts an invoice ID or UUID. |

This powers the `zatca:inspect` command — use it directly if you're building an admin dashboard.

---

### `CertificateLifecycleManager`

```php
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Zatca\Enums\ZatcaCredentialType;

$manager = app(CertificateLifecycleManager::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `check` | `check(string $egsIdentifier, ZatcaCredentialType $type): ZatcaCertificateState` | `ZatcaCertificateState` | Evaluates and updates expiry state. |
| `renew` | `renew(string $egsIdentifier, ZatcaCredentialType $type): ZatcaCertificateState` | `ZatcaCertificateState` | Renews and resumes any paused invoices. |

---

### `ZatcaSdkInterface`

```php
use Zatca\Contracts\ZatcaSdkInterface;

$sdk = app(ZatcaSdkInterface::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `generateCsr` | `generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array` | `['csr' => string, 'privateKey' => string]` (file paths) | Runs the SDK's CSR generation. |
| `signInvoice` | `signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array` | `['signedXmlPath' => string, 'signedXmlBase64' => string, 'invoiceHash' => string, 'qrCode' => string]` | Signs an invoice. |
| `generateHash` | `generateHash(string $xmlPath): string` | invoice hash | Computes only the hash. |
| `validateInvoice` | `validateInvoice(string $signedXmlPath, string $certificatePath): array` | `['valid' => bool, 'errors' => array, 'warnings' => array]` | Runs the SDK's full validation. |
| `version` | `version(): string` | SDK version string | |
| `executablePath` | `executablePath(): string` | Absolute path to the jar | |

You will rarely call this directly — it's an advanced/low-level API. Most apps only need `GenerateZatcaInvoiceJob`.

---

### `ZatcaSanitizer`

```php
use Zatca\Services\Security\ZatcaSanitizer;

$sanitizer = app(ZatcaSanitizer::class);
```

| Method | Signature | Returns | Description |
|---|---|---|---|
| `sanitizeSdkOutput` | `sanitizeSdkOutput(string $output, int $maxLength = 500): string` | `string` | Redacts PEM-armored blocks, long base64 blobs, and `Authorization` header values, then truncates. |
| `sanitizeResponseBody` | `sanitizeResponseBody(array $body): array` | `array` | Recursively redacts keys like `secret`, `token`, `privateKey`, `certificate`, `csr`. |
| `sanitizeCommandArgs` | `sanitizeCommandArgs(array $args): array` | `array` | Reduces filesystem-path arguments to their basename (flags are left as-is). |

Every place the package turns raw SDK output or an HTTP body into an exception message or a log line routes through this class first — see [Error Handling](#error-handling) and [Logging](#logging).

---

### Key DTOs (plain data objects)

| DTO | Fields |
|---|---|
| `ZatcaInvoiceData` | `egsIdentifier`, `sourceReference`, `buyerName`, `buyerVatNumber`, `issueDateTime`, `currency`, `lines` (array of `ZatcaInvoiceLine`), `totalExclVat`, `totalVat`, `totalInclVat` |
| `ZatcaInvoiceLine` | `description`, `quantity`, `unitPrice`, `vatRate`, `lineTotalExclVat`, `vatAmount` (+ `lineTotalInclVat()` helper method) |
| `ZatcaCredentialSet` | `binarySecurityToken`, `secret`, `requestId`, `privateKey`, `certificate`, `sdkFingerprint`, `certificateExpiresAt`, `certificateState` — returned only by `ZatcaCredentialStore::get()`, consumed internally by `ZatcaSigningKeyProvider` |
| `InvoiceSourceReference` | `sourceType`, `sourceId`, `egsIdentifier` — what `GenerateZatcaInvoiceJob` actually queues |
| `EgsConfig` | All fields listed under [Configuration → `egs`](#configuration) |
| `ZatcaApiResult` | `successful`, `status` (`ZatcaResponseStatus`), `httpStatus`, `requestId`, `sanitizedResponse`, `warnings`, `errors`, `payload` |

### Key Enums

| Enum | Values |
|---|---|
| `ZatcaEnvironment` | `Sandbox`, `Simulation`, `Production` |
| `ZatcaCredentialType` | `Compliance`, `Production` |
| `ZatcaInvoiceStatus` | `Draft`, `Generated`, `PendingReporting`, `Reported`, `ReportedWithWarnings`, `Failed`, `Retrying`, `ManualReview` |
| `ZatcaResponseStatus` | `Pass`, `Warning`, `Error`, `Unknown` |
| `ZatcaCertificateState` | `Valid`, `ExpiringSoon`, `Expired`, `Renewing`, `Failed` — `Valid` is the one durable "safe to report" state; there is no separate `Active` state (an earlier revision had one, but nothing ever treated it differently from `Valid`, so it was removed) |

### Event

| Event | Fired when | Payload |
|---|---|---|
| `ZatcaInvoiceGenerated` | Right after an invoice is signed and stored, before reporting | `$event->invoice` (a `ZatcaInvoice` model) |

Listen to this event to send/render the student-facing PDF invoice — the package deliberately does not generate PDFs itself, so you can use your own branding/template.

```php
use Zatca\Events\ZatcaInvoiceGenerated;

Event::listen(ZatcaInvoiceGenerated::class, function (ZatcaInvoiceGenerated $event) {
    // Render/send your PDF using $event->invoice
});
```

---

## Invoice Generation

### Simplified Invoice (B2C) — supported in v1

This is the only invoice type v1 supports. It is a **Simplified Tax Invoice**, used when selling to individual customers.

### Standard Invoice, Credit Note, Debit Note — not in v1

These are **not implemented yet**. See [Roadmap](#roadmap).

### How generation works, step by step

1. **XML generation** — `SimplifiedInvoiceXmlGenerator` builds an unsigned UBL 2.1 XML document from your `ZatcaInvoiceData`, the current ICV (invoice counter), and the Previous Invoice Hash.
2. **Signing** — the XML is handed to the ZATCA SDK (`ZatcaSdkAdapter::signInvoice()`), which signs it with your certificate/private key and returns the signed XML, the invoice hash, and the QR code — all computed by the real SDK, never by hand.
3. **QR generation** — the QR code is embedded automatically by the SDK during signing. The package extracts it from the signed XML and stores it on the invoice.
4. **Validation** — during onboarding's `test-invoice` step, the SDK also validates the signed XML against XSD, EN 16931, KSA rules, QR, signature, and PIH — before it's ever submitted.

You never call these steps individually in your app — `GenerateZatcaInvoiceJob` runs all of them for you.

---

## Onboarding

Onboarding is a **one-time setup per EGS per environment** (sandbox, then again for production).

| Step | What happens | ZATCA API | Required input | Expected output | Possible failures |
|---|---|---|---|---|---|
| 1. Generate credentials | The SDK generates a private key + CSR locally | None | Your organization data in `.env` | CSR + private key stored (encrypted) | SDK path misconfigured |
| 2. Compliance CSID | The CSR is submitted with an OTP to get a test certificate | `POST /compliance` | OTP from the Fatoora portal | Compliance token, secret, certificate, request ID stored | Invalid/expired OTP, invalid CSR |
| 3. Compliance Invoice | A test invoice is generated, signed, and validated automatically | `POST /compliance/invoices` | None (uses a built-in sample invoice) | `PASS` or `WARNING` | `ERROR` — read the returned message |
| 4. Production CSID | The real production certificate is requested | `POST /production/csids` | The compliance request ID from step 2 | Production token, secret, certificate stored | Compliance step not completed |

Run these commands in order:

```bash
php artisan zatca:generate-credentials
php artisan zatca:onboard compliance --otp=123456
php artisan zatca:onboard test-invoice
php artisan zatca:onboard production
```

After onboarding, verify with:

```bash
php artisan zatca:health-check
```

---

## Production Reporting

### Reporting invoices

Every real invoice uses the **Reporting model** — `POST /invoices/reporting/single`. This is the only model v1 supports (see [Clearance](#clearance) below).

### Clearance

The **Clearance model** is not implemented in v1. Only Reporting is supported.

### Retry behavior

If ZATCA is unreachable, or returns a temporary (5xx) error, the invoice is marked `retrying` and picked up again by `zatca:report-pending` (run this on a schedule). If ZATCA returns a real validation error, the invoice moves to `manual_review` — it is **not** retried automatically, because retrying the same broken data would just fail again. An admin must look at it first.

### Idempotency

- `GenerateZatcaInvoiceJob` will never create two invoices for the same source (checked by `sourceReference()`).
- Reporting reuses the exact same `invoice_hash` / `uuid` / signed XML on every attempt — nothing is ever recomputed.
- HTTP 409 is treated as success **only when the response body itself confirms** the invoice was already reported (its error message matches "already ... report"). A 409 for any other reason is treated as a real failure (`manual_review`), never silently accepted.

### State transitions

```
pending_reporting → reported                  (ZATCA returned PASS)
pending_reporting → reported_with_warnings     (ZATCA returned WARNING)
pending_reporting → retrying → reported        (temporary failure, then success)
pending_reporting → retrying → manual_review   (retries exhausted)
pending_reporting → manual_review              (non-retryable error, e.g. validation failure or expired cert)
```

---

## Certificate Lifecycle

| Step | What happens | ZATCA API |
|---|---|---|
| CSR | Generated locally by the SDK | None |
| Compliance CSID | A test certificate is issued | `POST /compliance` |
| Production CSID | The real certificate is issued | `POST /production/csids` |
| Renewal | Calls `ZatcaConnectorInterface::renewProductionCsid()` — a **separate** method from onboarding's `requestProductionCsid()`, with its own request mapper/response normalizer. **Disabled by default** — see below. | `POST /production/csids` (today — see note) |
| Expiration | Tracked locally by `certificate_expires_at`; checked by `zatca:certificate:check` | None |
| Rotation | Not automatic — run `zatca:certificate:renew` manually (only takes effect if enabled — see below) | `POST /production/csids` |

**State machine:** `Valid → ExpiringSoon → Expired → Renewing → Valid` (or `Failed`).

### ⚠️ Automatic renewal is disabled by default

ZATCA's dedicated certificate-renewal contract — whether it uses the same `/production/csids` endpoint, whether it requires a **new OTP**, and whether the response shape differs — **has not been verified against an official ZATCA document in this codebase**. `HttpConnector::renewProductionCsid()` currently calls the same endpoint as initial onboarding purely because that's the only production-cert-issuing endpoint this package has verified documentation for.

Because of that, `config('zatca.certificate.auto_renewal_enabled')` defaults to **`false`**. With it disabled:

- `zatca:certificate:renew` / `CertificateLifecycleManager::renew()` **never calls the ZATCA API**.
- The certificate stays exactly as it was (`expired`/`expiring_soon`).
- An administrator is alerted via `Log::error`.
- Affected invoices stay `pending_reporting` — never sent with an expired certificate, and never lost.

**Do not enable `ZATCA_AUTO_RENEWAL_ENABLED=true`** until someone has verified the real renewal contract against an official ZATCA document and confirmed `HttpConnector::renewProductionCsid()` implements it correctly. Once enabled and a renewal succeeds, every invoice left `pending_reporting`/`retrying` for that EGS is automatically re-dispatched — without any XML/UUID/ICV/PIH/Hash being regenerated.

> Compliance certificates cannot be renewed — they are single-use during onboarding. If you need a new one, run `zatca:onboard compliance` again with a fresh OTP.

---

## Error Handling

### Exceptions

| Exception | Thrown when |
|---|---|
| `ZatcaException` | Base exception — generic package errors. |
| `ZatcaCredentialsMissingException` | Credentials for an EGS/environment/type don't exist yet. |
| `ZatcaContractChangedException` | A ZATCA API response no longer matches the expected shape — usually means ZATCA changed something. |
| `ZatcaSdkOutputChangedException` | The SDK's console output no longer matches the expected format — usually means the SDK version changed. |
| `ZatcaSdkFingerprintMismatchException` | The SDK jar file on disk doesn't match the recorded fingerprint. |
| `ZatcaSdkProcessException` | The SDK process failed, timed out, or didn't produce its expected output file. |

### Validation errors (from ZATCA)

Reported through `ZatcaApiResult::$errors` / `$warnings` — human-readable messages taken directly from ZATCA's response.

### SDK errors

Caught and wrapped as `ZatcaSdkProcessException`. The SDK's raw console output is included in the exception message for debugging.

### API errors

Network failures and HTTP errors are caught inside the connector and turned into a `ZatcaApiResult` with `successful: false` — code calling the connector never has to catch a Guzzle exception directly.

### Retryable vs. non-retryable errors

| Type | Retried automatically? |
|---|---|
| Network failure / timeout | ✅ Yes |
| HTTP 5xx from ZATCA | ✅ Yes |
| HTTP 409 "already reported" | Treated as success, not an error |
| HTTP 401 (bad credentials) | ❌ No — needs a human to fix credentials |
| Validation `ERROR` (bad invoice data) | ❌ No — needs a human to fix the data |
| Expired certificate | ❌ No — needs `zatca:certificate:renew`, then resumes automatically |

---

## Health Check & Diagnostics

| Command | What it verifies | ZATCA API used |
|---|---|---|
| `zatca:health-check` | SDK jar exists, SDK fingerprint matches, credentials exist per EGS, certificate state is healthy | None |
| `zatca:inspect credentials` | Which credentials exist, certificate state/expiry — never raw secrets | None |
| `zatca:inspect state` | Current ICV and a truncated PIH preview | None |
| `zatca:inspect invoice --id=` | One invoice's status, attempt count, last error | None |
| `zatca:certificate:check` | Certificate expiry state transitions | None |

All diagnostics are **read-only** and safe to run at any time, including in production.

---

## Logging

- The package logs through Laravel's normal logging system (`Log::warning`, `Log::error`) — no separate log file.
- **What gets logged:** high-level status messages (e.g. "invoice generation skipped", "certificate expired", "renewal failed") and sanitized error summaries.
- **What never gets logged:** Authorization headers, tokens, secrets, private keys, certificates, CSR content, or raw/signed XML. `ZatcaApiResult::$sanitizedResponse` is hand-built per endpoint to only keep non-sensitive fields, and `ZatcaSanitizer` (see [Public API](#public-api)) redacts PEM blocks/long base64 blobs/Authorization headers before any SDK output or command argument reaches an exception message or log line.
- **Log levels used:** `warning` for expected/recoverable situations (certificate expiring, invoice generation skipped because onboarding isn't done), `error` for failures that need attention (certificate expired, renewal failed).

---

## Testing

### Standalone package test suite (Orchestra Testbench)

The package has its own self-contained test suite under `packages/zatca/zatca/tests/` — it boots a throwaway Laravel app via [Orchestra Testbench](https://packages.tools/testbench) with an in-memory SQLite database, with **no dependency on any host application**. This is the authoritative suite that proves the package works on its own.

```bash
cd packages/zatca/zatca
composer install   # first time only — installs its own isolated vendor/
composer test       # runs vendor/bin/phpunit
```

It covers: unit tests (sanitizer, response normalizer, XML generator), feature tests (service provider bindings, migrations, registered commands, ICV/PIH isolation, credential encryption, certificate lifecycle, queue job payload safety), golden-file SDK output parser tests, and SDK runtime workspace isolation tests.

### Host-application integration tests

`tests/Unit/Zatca` and `tests/Feature/Zatca` inside the host app cover what's specific to this host: exercising the real SDK jar against a live MySQL connection, and (once implemented) the host's own `InvoiceSourceContract` mapping and payment trigger.

```bash
php artisan test --filter=Zatca
# or:
vendor/bin/phpunit tests/Unit/Zatca tests/Feature/Zatca
```

### Sandbox testing

Set `ZATCA_ENVIRONMENT=sandbox` and go through the full [Onboarding](#onboarding) sequence against ZATCA's public sandbox. This is safe — sandbox has no real legal effect. The normal test suites above **never** call the live ZATCA sandbox — only the SDK smoke suite below does anything real, and only the local SDK, not the network.

### Real SDK smoke suite (explicitly opt-in)

```bash
php artisan zatca:sdk-smoke-test
```

Verifies, against the **real** local SDK (Java, the jar, the SDK's own bundled sample certificate) — never the ZATCA network: Java availability, the SDK executable/root, fingerprint computation, CSR generation, hash generation, signing, QR extraction, and that validation runs. It **skips safely** (exit 0, clearly labeled `SKIPPED`) when the SDK isn't configured on the current machine — but the Production Readiness Checklist in `docs/ZATCA_ACTIVATION_GUIDE.md` requires it to actually pass, not just skip, before go-live. This command is never invoked by the normal unit/feature test suites.

---

## Examples

### Example: Generate an invoice after a payment webhook

```php
// In your payment confirmation handler — NOT inside the webhook's raw request handling:
use Zatca\Jobs\GenerateZatcaInvoiceJob;

public function markOrderAsPaid(Order $order): void
{
    $order->update(['status' => 'paid']);

    // Activate the course immediately — never wait on ZATCA:
    $this->enrollmentService->activate($order);

    // ZATCA invoice happens in the background:
    dispatch(GenerateZatcaInvoiceJob::forModel($order));
}
```

### Example: Check if an EGS is fully onboarded before allowing checkout

```php
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaCredentialType;

$ready = app(ZatcaCredentialStore::class)
    ->has('default', ZatcaEnvironment::Production, ZatcaCredentialType::Production);
```

### Example: Show an admin the status of a specific invoice

```php
use Zatca\Services\Diagnostics\ZatcaInspector;

$invoice = app(ZatcaInspector::class)->invoice('42');
// or by UUID:
$invoice = app(ZatcaInspector::class)->invoice('8e6000cf-1a98-4174-b3e7-b5d5954bc10d');
```

### Example: Manually resend all stuck invoices

```bash
php artisan zatca:report-pending
```

### Example: Renew a production certificate before it expires

```bash
php artisan zatca:certificate:check
# if it prints "expiring_soon" AND you have verified the real renewal contract:
ZATCA_AUTO_RENEWAL_ENABLED=true php artisan zatca:certificate:renew --type=production
```

---

## Troubleshooting

| Problem | Likely cause | Fix |
|---|---|---|
| `Configured ZATCA_SDK_EXECUTABLE [...] does not point to an existing SDK jar file.` | Wrong or missing path in `.env` | Check the path exists on the server, use an absolute path. |
| `Configured ZATCA_SDK_ROOT [...] does not point to an existing directory.` | Wrong `ZATCA_SDK_ROOT` | Point it at the SDK's root folder (containing `Apps/`, `Data/`, `Configuration/`). |
| Java-related errors when signing | Java not installed, or wrong version | Install Java 11–15. Check with `java -version`. |
| `The provided OTP is invalid` | OTP expired or wrong | Get a new OTP from the Fatoora portal — they expire fast. |
| Invoice stuck in `pending_reporting` forever | Queue worker not running, or scheduler not running | Confirm `php artisan queue:work --queue=zatca` is running, and the scheduler cron is set up. |
| Invoice in `manual_review` | A non-retryable error occurred | Run `zatca:inspect invoice --id=...` to see `last_error`, fix the underlying issue. |
| `[FAIL] ZATCA SDK binary fingerprint mismatch` | The jar file changed on disk | Confirm it wasn't replaced unexpectedly. If the change is intentional (SDK upgrade), regenerate and update `ZATCA_SDK_FINGERPRINT`. |
| `Unknown ZATCA EGS identifier [...]` | Using an EGS identifier that isn't in `config('zatca.egs')` | Check `ZATCA_EGS_IDENTIFIER` matches what you pass to jobs/commands. |
| `zatca:certificate:renew` does nothing / certificate stays `expired` | `ZATCA_AUTO_RENEWAL_ENABLED` is `false` (the default) | This is intentional — see [Certificate Lifecycle](#certificate-lifecycle). Don't enable it without verifying the real ZATCA renewal contract first. |
| A 409 response was expected to succeed but didn't | The response body didn't confirm "already reported" | Check `last_zatca_response`/`last_error` on the invoice — this is by design (see [Error Handling](#error-handling)), not a bug. |
| `Unable to resolve invoice source [...]` | `InvoiceSourceResolverContract` returned `null` — the record was deleted, or `source_type` isn't a recognized Eloquent model | If your source isn't a plain Eloquent model, bind your own `InvoiceSourceResolverContract` implementation. |
| Migration fails with a MySQL "identifier too long" error | Should not happen — already fixed in the package's migrations | If you see this, you're on an outdated copy of the package; update it. |
| `Changing columns ... requires Doctrine DBAL` when running the package's own tests | `doctrine/dbal` not installed in the package's own `packages/zatca/zatca/vendor/` | Run `composer install` inside `packages/zatca/zatca/` — it's declared as a real dependency in that `composer.json`. |

---

## FAQ

**Does this package generate the PDF invoice for students?**
No. It generates the signed XML and QR code, and fires `ZatcaInvoiceGenerated` so your app can render/send its own branded PDF.

**Can I use this for B2B invoices?**
Not yet — v1.0.0 only supports Simplified Invoices (B2C). See [Roadmap](#roadmap).

**What happens if ZATCA is down when a student pays?**
The student's course access is never blocked. The invoice is signed and saved as `pending_reporting`, and reporting is retried automatically once ZATCA is back.

**Do I need Postman or PowerShell to onboard?**
No. Everything is done through the `php artisan zatca:...` commands (see [Commands](#commands)).

**Why doesn't `zatca:certificate:renew` do anything?**
Automatic renewal is disabled by default (`ZATCA_AUTO_RENEWAL_ENABLED=false`) because ZATCA's real renewal contract hasn't been verified in this codebase yet. See [Certificate Lifecycle](#certificate-lifecycle) before enabling it.

**Can I pass any object to `GenerateZatcaInvoiceJob`?**
No — as of the production hardening pass, it only takes `source_type`/`source_id` (or use `::forModel($order)`), never the object itself. This keeps the queued payload safe to serialize and always fresh at execution time.

**Can I add a second business/branch (EGS) later?**
Yes — the package is built for it (`EgsRegistry`). Add a new entry to `config('zatca.egs')` and onboard it separately; no code changes needed.

**Where are my ZATCA secrets stored?**
Encrypted, in the `zatca_egs_credentials` table, using Laravel's own encryption (your app's `APP_KEY`).

**What if the SDK jar gets accidentally replaced or corrupted?**
`zatca:health-check` will fail loudly with a fingerprint mismatch — it will not silently sign invoices with a different jar.

---

## Changelog / Versioning

This package follows [Semantic Versioning](https://semver.org/):

- **Major** (`x.0.0`) — breaking changes, or scope changes (e.g. adding B2B support would likely bump to `2.0.0`).
- **Minor** (`1.x.0`) — new features that don't break existing usage.
- **Patch** (`1.0.x`) — bug fixes only.

**Current version: `1.0.0`** — first release. B2C Simplified Invoices, Reporting model only.

Supported ZATCA API/SDK/UBL version combinations are tracked in `docs/COMPATIBILITY.md` inside the package. Never mix versions outside that table.

---

## Roadmap

The following are **intentionally out of scope for v1.0.0** and are not implemented:

- Standard Tax Invoices (B2B)
- Credit Notes
- Debit Notes
- Clearance model (only Reporting is supported today)
- Multiple EGS in production (the architecture supports it, but v1 ships configured for a single EGS)

These are candidates for future versions. Do not assume any of the above works until it appears in this README and in a released version.

---

## Contributing

- Open an issue or discuss the change with the team before starting large work.
- Keep changes scoped — one feature/fix per pull request.
- Add or update tests for anything you change (see [Testing](#testing)).
- Never commit real ZATCA credentials, `.env` files, or certificates.
- Run `php artisan zatca:health-check` and the test suite before opening a PR.
- Update this README and `docs/COMPATIBILITY.md` if you change public behavior.

---

## License

Proprietary — internal use only. Not for external distribution outside the company.
