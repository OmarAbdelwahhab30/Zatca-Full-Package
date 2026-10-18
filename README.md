# ZATCA for Laravel

A Laravel package that sends your invoices to **ZATCA** (the Saudi tax authority) using the **Fatoora Phase 2** rules.

You give it your order. It builds the e-invoice, signs it, adds the QR code, sends it to ZATCA, retries when ZATCA is down, and saves everything in your database.

> **Status:** B2C (simplified invoices, credit notes and debit notes, sent by **Reporting**) is the main, tested flow.
> B2B (standard invoices sent by **Clearance**) is built, covered by tests, and passes in **ZATCA's developer-portal sandbox**: onboarding, invoices, credit notes and debit notes are all cleared with no warnings (`composer test:sandbox`). It has **not been run in ZATCA's simulation environment yet**, and the sandbox cannot prove everything (see [Known limitations](#known-limitations)). Do that before you turn B2B on in production.
>
> **Tested ZATCA SDK:** Java SDK **3.4.8** (`238-R3.4.8`). A clean copy ships with the package in [`SDKs/`](SDKs/) and passes `composer smoke` and the real-SDK tests. See [`SDKs/README.md`](SDKs/README.md).

---

## Table of contents

1. [What is this package?](#1-what-is-this-package)
2. [ZATCA basics in 5 minutes](#2-zatca-basics-in-5-minutes)
3. [Supported features](#3-supported-features)
4. [Requirements](#4-requirements)
5. [Installation](#5-installation)
6. [Configuration](#6-configuration)
7. [How the package works](#7-how-the-package-works)
8. [ZATCA onboarding](#8-zatca-onboarding)
9. [Complete usage examples](#9-complete-usage-examples)
10. [Invoice fields explained](#10-invoice-fields-explained)
11. [Understanding ZATCA responses](#11-understanding-zatca-responses)
12. [More features](#12-more-features)
13. [Console commands](#13-console-commands)
14. [Server Setup & Production Deployment](#server-setup)
15. [Troubleshooting](#15-troubleshooting)
16. [Known limitations](#known-limitations)
17. [Renamed classes and methods](#17-renamed-classes-and-methods)
18. [Running the package tests](#18-running-the-package-tests)

---

## Documentation map

This README covers installation, configuration and everyday usage end to end. The [`docs/`](docs/) folder goes deeper on specific topics — use this as a guide to which file to open:

| File | Read it for |
|---|---|
| [`docs/ZATCA_CONCEPTS.md`](docs/ZATCA_CONCEPTS.md) | What ZATCA, EGS, certificates, the hash chain, environments, XML/UBL and QR codes actually mean — the ideas behind the glossary in [§2](#2-zatca-basics-in-5-minutes) |
| [`docs/INVOICE_TYPES.md`](docs/INVOICE_TYPES.md) | Deciding B2B vs. B2C and credit vs. debit note for a real sale, with business scenarios and comparison tables |
| [`docs/USAGE_EXAMPLES.md`](docs/USAGE_EXAMPLES.md) | Code patterns beyond [§9](#9-complete-usage-examples): previewing VAT before checkout, refund admin screens, status dashboards, recovering stuck documents |
| [`docs/VAT_GUIDE.md`](docs/VAT_GUIDE.md) | The exact VAT rounding rules, plus worked examples (mixed VAT categories, split discounts, price-adjustment math) |
| [`docs/ZATCA_WORKFLOWS.md`](docs/ZATCA_WORKFLOWS.md) | The full document lifecycle, Reporting vs. Clearance in practice, sandbox/simulation/production, deployment stages, certificate renewal, SDK/API version changes, and what the package automates vs. what you must do |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | A symptom-first diagnosis flow (`zatca:status` → what to do), common rejection codes, the exceptions reference, and an incident-response checklist |
| [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) | The authoritative ZATCA/SDK/UBL/Laravel/PHP version matrix referenced throughout this README |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Pointer to `ZATCA_WORKFLOWS.md` §6 (kept at this path so existing bookmarks still work) |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Pointer to `ZATCA_WORKFLOWS.md` (kept at this path so existing bookmarks still work) |
| [`docs/SERVER_REQUIREMENTS.md`](docs/SERVER_REQUIREMENTS.md) | Pointer to this README's [§4](#4-requirements) and [§14 Server Setup & Production Deployment](#server-setup) (kept at this path so existing bookmarks still work) |

---

## 1. What is this package?

In Saudi Arabia, every VAT-registered business must send its invoices to ZATCA electronically. In Phase 2, each invoice must:

- be an XML file in a fixed format (UBL 2.1),
- carry a counter and the hash of the previous invoice, so invoices form a chain,
- be signed with a certificate that ZATCA gave you,
- carry a QR code,
- be sent to ZATCA's API.

Doing this by hand is a lot of work. This package does it for you:

- **Onboarding:** creates your private key and CSR, and gets your certificates from ZATCA.
- **Invoices:** turns your order into a ZATCA invoice, then numbers, chains and signs it, and adds the QR code.
- **Sending:** sends it to ZATCA from a Laravel queue job, so your checkout never waits for ZATCA.
- **Retries:** retries safely when ZATCA is down, and never sends the same invoice twice.
- **Credit and debit notes:** creates them against the original invoice.
- **Monitoring:** gives you Artisan commands to check health, certificates and stuck invoices.

**What you write:** one small class that turns your order into invoice data. The package does the rest.

**What you can build with it:** e-invoicing for an online shop, a POS, a restaurant, a subscription service, a services company, or a wholesale business selling to other companies. It also handles refunds, returns and extra charges.

**What it does not do:** it does not make PDF invoices. It gives you the signed XML, the QR code and an event, and you render your own PDF or receipt.

---

## 2. ZATCA basics in 5 minutes

### B2C vs B2B

| | B2C | B2B |
|---|---|---|
| Who buys? | A normal person | Another company |
| Example | A shop sells headphones to Sara | A furniture company sells 20 chairs to an office company |
| ZATCA name | **Simplified** tax invoice | **Standard** tax invoice |
| Buyer details needed | None (name is optional) | Company legal name, VAT number (or another ID such as a CR number), full address |
| How it reaches ZATCA | **Reporting** | **Clearance** |

### Reporting vs Clearance

- **Reporting:** you give the invoice to the customer right away, then send it to ZATCA. ZATCA's rule is "within 24 hours". This package sends it a few seconds after the sale, from the queue. Used for **simplified (B2C)** documents.
- **Clearance:** you send the invoice to ZATCA **first**. ZATCA checks it, stamps it and sends it back. Only the cleared invoice is the legal invoice you give to the buyer. Used for **standard (B2B)** documents.

B2B/B2C and Clearance/Reporting are related, but they are not two names for the same thing. B2B/B2C is about **who buys**, and that decides the **document type** (standard or simplified). The document type then decides **how it is sent** (Clearance or Reporting). In this package, the document type you choose decides the route, and you never pick the endpoint yourself.

### Credit notes vs Debit notes

- **Credit note:** lowers the amount of an invoice that ZATCA already accepted. Examples: a customer returns a product, you refund part of an order, or you charged too much.
- **Debit note:** raises the amount of an invoice that ZATCA already accepted. Examples: you forgot to charge for gift wrapping, a price was too low, or VAT was under-charged.

You never edit an accepted invoice. You issue a note that points to it. A note always follows the flow of its original invoice: a note against a B2C invoice is reported, and a note against a B2B invoice is cleared.

### The six documents

| # | Document | When to use it | ZATCA code | Sent by | Package enum (`ZatcaComplianceDocumentType`) |
|---|---|---|---|---|---|
| 1 | B2C invoice | A person buys something | 388 / 02 | Reporting | `Invoice` |
| 2 | B2C credit note | A person returns or is refunded | 381 / 02 | Reporting | `CreditNote` |
| 3 | B2C debit note | A person must pay more | 383 / 02 | Reporting | `DebitNote` |
| 4 | B2B invoice | A company buys something | 388 / 01 | Clearance | `StandardInvoice` |
| 5 | B2B credit note | A company returns or is refunded | 381 / 01 | Clearance | `StandardCreditNote` |
| 6 | B2B debit note | A company must pay more | 383 / 01 | Clearance | `StandardDebitNote` |

### Words you will see

| Word | Meaning |
|---|---|
| **EGS** | "E-invoice Generation Solution", the "device" (your app) registered with ZATCA. Each EGS has its own certificate, counter and hash chain. This package uses one EGS for B2C and an optional second one for B2B. |
| **CSR** | A certificate request. You send it to ZATCA to get a certificate. |
| **Private key** | The secret key used to sign invoices. It never leaves your server. |
| **OTP** | A one-time code from the Fatoora portal. You need it once to start onboarding. |
| **Compliance CSID** | A test certificate. Used only to prove to ZATCA that your invoices are correct. |
| **Production CSID** | The real certificate used to sign live invoices. |
| **ICV** | Invoice counter. 1, 2, 3… per EGS. Never reused. |
| **PIH** | Previous invoice hash. Links each invoice to the one before it. |
| **UUID** | A unique id for each document. |
| **Sandbox / Simulation / Production** | ZATCA's three environments. See [Environments](#environments). |

---

## 3. Supported features

**Invoicing**
- B2C simplified invoice, credit note and debit note, sent by Reporting.
- B2B standard invoice, credit note and debit note, sent by Clearance (passes in the developer-portal sandbox; rehearse in simulation before production, see [Known limitations](#known-limitations)).
- Several lines per invoice, several VAT rates per invoice, and document-level discounts.
- Standard-rated (`S`), zero-rated (`Z`) and exempt (`E`) items, with ZATCA exemption reason codes.
- Full or partial credit notes. Credit notes for part of a line's value (price adjustments). Debit notes for added items, price corrections and VAT corrections.
- Automatic UUID, counter (ICV), hash chain (PIH), XML, signature and QR code.
- An invoice-data check before any counter number is used. It refuses totals that don't match the lines, line math that is wrong (`quantity × unit price`, `net × VAT rate`), bad VAT categories, a non-SAR currency and incomplete B2B buyer data.
- Each invoice's business data is frozen when it is built, so notes and re-issues never re-read changed prices.

**Sending**
- Queued generation and submission, with retries.
- Safe retries: a retry always resends the same signed XML and never signs again.
- A scheduler sweep for invoices still waiting (`zatca:report-pending`).
- Manual and scheduled requeue for stuck B2C invoices (`zatca:requeue`).
- Duplicate protection: one document per sale. A sale is never sent on two EGS.
- Re-issuing a rejected invoice as a new, corrected document (regeneration).

**Onboarding and security**
- Onboarding through Artisan commands: CSR, compliance CSID, compliance checks, production CSID.
- Credentials stored encrypted (Laravel `APP_KEY` by default, and you can swap in your own encrypter).
- Certificate expiry tracking: an event when a certificate enters "expiring soon" (60 days before) and when it expires.
- SDK fingerprint check, to detect a changed ZATCA SDK jar.

**Operations**
- A global on/off switch (`ZATCA_ENABLED`) and a separate B2B switch (`ZATCA_B2B_ENABLED`).
- 14 Artisan commands for health checks, status, inspection, requeue, recovery, cleanup and more.
- Events for "invoice generated", "needs attention", "note accepted" and "certificate expiring".

---

## 4. Requirements

| What | Version / note |
|---|---|
| PHP | 8.1 or newer for Laravel 9 and 10. 8.2 or newer for Laravel 11 and 12 (Laravel's own rule). |
| Laravel | **12** is recommended. 9, 10 and 11 still work (Legacy Compatibility). See [Laravel compatibility](#laravel-compatibility) below. |
| PHP extensions | `openssl`, `dom`, `mbstring`, `ctype`, `json`, plus your database's PDO driver |
| Java | Java 11 (the ZATCA SDK supports Java 11 to 15). Server setup, user accounts and verification: [Server Setup & Production Deployment](#server-setup). |
| ZATCA Java SDK | Version **3.4.8**, the version the package is tested with — already bundled with the package. Installing it on a real server: [§14.4](#server-setup-sdk-install). |
| Database | Any database Laravel supports (MySQL, PostgreSQL, SQLite in tests) |
| Queue | Any real Laravel queue driver (`database`, `redis`…). `sync` works for quick tests only. A persistent queue worker is required for production — [§14.5](#server-setup-supervisor). |
| Scheduler | Laravel scheduler (cron) for the retry sweep and certificate checks — [§14.6](#server-setup-cron) |

Composer dependencies are installed for you: `guzzlehttp/guzzle`, `doctrine/dbal`, `symfony/process`, `nesbot/carbon`.

### Laravel compatibility

"Works" and "safe" are two different questions. The table answers both.

- **Technical compatibility:** the package installs and its test suite passes on that Laravel version.
- **Security support:** Laravel itself still ships security fixes for that version.

| Laravel | Level | Technical compatibility | Security support from Laravel | Installs with Composer's default settings? |
|---|---|---|---|---|
| **12** | **Recommended** | ✅ Tested | ✅ Yes | ✅ Yes |
| 11 | Legacy Compatibility | ✅ Tested | ❌ No. Known advisories with no fixed 11.x release | ❌ No (see below) |
| 10 | Legacy Compatibility | ✅ Tested | ❌ No. Known advisories with no fixed 10.x release | ❌ No (see below) |
| 9 | Legacy Compatibility | ✅ Tested | ❌ No. Known advisories with no fixed 9.x release | ❌ No (see below) |

**Use Laravel 12 for new production projects.** Laravel 9, 10 and 11 stay supported by this package so that existing apps keep working while they plan their upgrade. They are not removed just because Laravel stopped maintaining them.

**What was tested (2026-10-14, SQLite):** the full package suite ran in a clean install for each Laravel version, on each PHP version listed.

| Laravel | Framework | Testbench | PHPUnit | Carbon | PHP versions run | Result on each PHP version |
|---|---|---|---|---|---|---|
| 9 | 9.52.22 | 7.58 | 9.6 | 2.73 | 8.1, 8.3 | 834 tests passed, 8 skipped |
| 10 | 10.50.3 | 8.39 | 10.5 | 2.73 | 8.2, 8.3 | 834 tests passed, 8 skipped |
| 11 | 11.57.0 | 9.18 | 11.5 | 3.14 | 8.2, 8.3 | 834 tests passed, 8 skipped |
| 12 | 12.70.0 | 10.12 | 11.5 | 3.14 | 8.2, 8.3, 8.4 | 834 tests passed, 8 skipped |
| 12 | 12.70.0 | 10.12 | 12.5 | 3.14 | 8.3 | 834 tests passed, 8 skipped |

Every run gave 2,706 assertions and no PHPUnit warnings or deprecations. Full version details are in [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md).

Since that matrix, the suite has grown to 847 tests (the onboarding failure cases and the fixes found in the sandbox). The new count was run on Laravel 10 / PHP 8.2 only. The matrix above has not been re-run yet.

Of the 8 skipped tests, 7 drive the real ZATCA Java SDK and skip unless you point them at an SDK install (see [Running the package tests](#18-running-the-package-tests)). The other one checks Unix file permissions and skips on Windows, where these runs were made. So these results show the package code works on each Laravel version. They **do not** prove that real SDK signing works on each version. Real signing, and the SDK validator on invoices whose VAT the package calculated, were checked separately with the bundled SDK 3.4.8 on Laravel 10 / PHP 8.3 (see [`SDKs/README.md`](SDKs/README.md)). Run `composer smoke` and the onboarding sandbox steps on your own Laravel version before you go live.

The package's own tests run on PHPUnit 9 to 12. Each data provider and group is written twice: as a PHP attribute (`#[DataProvider]`, `#[Group]`) for PHPUnit 10+, and as a docblock tag (`@dataProvider`, `@group`) for PHPUnit 9, which Laravel 9 needs. Keep both when you add a test.

**Known Laravel security advisories (checked 2026-10-14 with `composer audit`):** the newest release of each legacy version still has these open. They are bugs in Laravel, not in this package.

| Advisory | Severity | Laravel 9 | Laravel 10 | Laravel 11 |
|---|---|---|---|---|
| CRLF injection in the default `email` validation rule (CVE-2026-48019) | High | ✅ affected | ✅ affected | ✅ affected |
| Temporary signed URL path confusion | Medium | ✅ affected | ✅ affected | ✅ affected |
| XSS on the debug error page (CVE-2026-102279) | Low | ✅ affected | ✅ affected | ✅ affected |
| File validation bypass (CVE-2025-27515) | Medium | ✅ affected | — | — |

Laravel 12.70.0 had no open advisories on the same date. New advisories can appear at any time; run `composer audit` in your own app to see the current list.

**Installing on Laravel 9, 10 or 11.** Composer 2.9 and newer refuses to install a package version that has an open security advisory. Because every 9.x, 10.x and 11.x release has one, `composer require` in a Laravel 9–11 app will fail with *"these were not loaded, because they are affected by security advisories"*. This package cannot change that, and it does not turn the check off for you. If your app must stay on Laravel 9–11 for now, your app's owner has to decide to accept the risk and override the check in **the app's own** `composer.json`:

- Prefer ignoring only the advisory IDs that Composer printed, under `config.audit.ignore`, after reading what each one means for your app.
- Avoid turning blocking off completely (`config.audit.block-insecure: false`). It also hides advisories in every other package.

Treat this as temporary and plan the move to Laravel 12.

---

## 5. Installation

### Step 1: Install Java and the ZATCA SDK

The package signs invoices with ZATCA's official Java SDK, so it must be on the server. For local development, the quick version:

```bash
sudo apt-get install -y openjdk-11-jre-headless
java -version
```

The exact SDK version this package is tested with already ships with it, under [`SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/`](SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/) — after Step 2 it's at `vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8`, and you can point `ZATCA_SDK_ROOT`/`ZATCA_SDK_EXECUTABLE` straight at it. **For a real server** — which user needs access, where to put the SDK, and full verification — see [Server Setup & Production Deployment §4](#server-setup-sdk-install).

### Step 2: Install the package

The package name is `zatca/zatca`. It is a private package. If it is not on your Composer registry, add its Git repository to your app's `composer.json` first:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:your-company/zatca-package.git" }
]
```

Then run:

```bash
composer require zatca/zatca
```

Laravel finds the service provider (`Zatca\ZatcaServiceProvider`) automatically. You don't need to register it.

### Step 3: Publish the config file

```bash
php artisan vendor:publish --tag=zatca-config
```

This creates `config/zatca.php`. You normally don't edit it, because every value comes from `.env`.

### Step 4: Run the migrations

The migrations load automatically. You don't need to publish them.

```bash
php artisan migrate
```

This creates these tables:

| Table | What it stores |
|---|---|
| `zatca_egs_credentials` | Your keys, CSRs and certificates (encrypted) per EGS and environment |
| `zatca_egs_states` | The current counter (ICV) and last hash (PIH) per EGS and environment |
| `zatca_invoices` | Every ZATCA document: invoices **and** notes, with XML, QR code and status |
| `zatca_submission_attempts` | One row per send attempt, with the HTTP status and ZATCA response |
| `zatca_compliance_documents` | The test documents sent during onboarding |
| `zatca_credit_notes`, `zatca_credit_note_lines`, `zatca_credit_note_events` | Credit notes (the business record, its lines and an audit log) |
| `zatca_debit_notes`, `zatca_debit_note_lines`, `zatca_debit_note_events` | Debit notes (same structure) |

### Step 5: Add your `.env` values

Copy the block from [Configuration](#6-configuration) and fill in your business details.

### Step 6: Make sure storage is writable

The package writes short-lived signing files to `storage/app/private/zatca`. For local development:

```bash
mkdir -p storage/app/private/zatca
```

That's enough if you're running everything as yourself locally. **On a real server**, don't just `chown` this to `www-data` and move on — the user that needs access here is usually your **queue worker**, not your web server, and they're often not the same account. See [Server Setup & Production Deployment §2–§3](#server-setup-identify-users) for how to find the right user and set this up safely (including when more than one user needs access).

### Step 7: Check the installation

```bash
php artisan config:clear
```

```bash
php artisan zatca:health-check
```

```bash
php artisan zatca:sdk-smoke-test
```

- `zatca:health-check` checks the SDK path, your seller data and onboarding state. Before onboarding, `[PENDING] ... credentials not yet onboarded` is expected.
- `zatca:sdk-smoke-test` runs Java and the SDK locally: CSR, hash, signing and QR. It does not call ZATCA. Every line should say `PASS`.

---

## 6. Configuration

### Your business (B2C EGS)

These values go into your CSR and into every invoice. Get them from your company documents and the Fatoora portal.

```dotenv
# ---- On/off ----
ZATCA_ENABLED=true                     # false = stop all ZATCA work (see "Kill switch")

# ---- ZATCA SDK ----
ZATCA_SDK_EXECUTABLE=/var/www/zatca-sdk/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_ROOT=/var/www/zatca-sdk
ZATCA_SDK_VERSION=3.4.8
ZATCA_SDK_FINGERPRINT=                 # fill after running zatca:generate-credentials
ZATCA_SDK_PROCESS_TIMEOUT=60

# ---- Environment: sandbox | simulation | production ----
ZATCA_ENVIRONMENT=sandbox

# ---- VAT (both optional) ----
ZATCA_VAT_PERCENTAGE=15                # default rate for standard-rated items
ZATCA_VAT_MODE=inclusive               # inclusive: your prices include VAT | exclusive: VAT is added on top

# ---- Your EGS (B2C) ----
ZATCA_EGS_IDENTIFIER=default           # any short name; you pass it to jobs and commands
ZATCA_INVOICE_TYPE=0100                # 0100 = this device issues simplified (B2C) documents

# ---- Seller identity ----
ZATCA_VAT_NUMBER=399999999900003       # 15 digits, starts and ends with 3
ZATCA_ORG_IDENTIFIER=399999999900003   # must be the SAME as ZATCA_VAT_NUMBER
ZATCA_ORG_NAME="Al Noor Trading Co."   # English legal name (used in the CSR)
ZATCA_COMPANY_NAME_AR="شركة النور للتجارة"  # Arabic legal name (used on invoices)
ZATCA_ORG_UNIT_NAME="Riyadh Branch"    # branch name
ZATCA_CRN=1010010000                   # commercial registration, 10 digits
ZATCA_BUSINESS_CATEGORY="Retail"
ZATCA_COUNTRY_CODE=SA

# ---- CSR device fields ----
ZATCA_CSR_COMMON_NAME="AlNoor-POS-1"   # a name for this device
ZATCA_CSR_SERIAL_NUMBER="1-AlNoor|2-WebShop|3-6f4d20e0-6bfe-4a80-9389-7dabe6620f12"  # ZATCA format: 1-solution|2-model|3-serial

# ---- Seller national address ----
ZATCA_STREET="King Fahd Road"
ZATCA_DISTRICT="Al Olaya"
ZATCA_BUILDING_NUMBER=1234             # exactly 4 digits
ZATCA_ADDITIONAL_NUMBER=5678           # exactly 4 digits
ZATCA_CITY=Riyadh
ZATCA_POSTAL_CODE=12211                # exactly 5 digits
ZATCA_LOCATION_ADDRESS=                # old single-line address, only used if the fields above are empty

# ---- Queue ----
ZATCA_QUEUE_CONNECTION=database        # defaults to QUEUE_CONNECTION
ZATCA_QUEUE_NAME=zatca
```

The example values above are fake. `399999999900003` is ZATCA's sample VAT number. Use your own real values.

<a id="vat-settings"></a>
### VAT settings

The package calculates VAT for you. You give it prices and quantities. It works out each line's net amount, its VAT and the invoice totals.

| `.env` key | Default | What it means |
|---|---|---|
| `ZATCA_VAT_PERCENTAGE` | `15` | The rate for standard-rated items. Above 0, at most 100, at most 2 decimals. An item can set its own rate (see [Invoice items](#invoice-items)). |
| `ZATCA_VAT_MODE` | `inclusive` | What your prices mean. `inclusive`: the price already contains VAT. `exclusive`: VAT is added on top of the price. |

Both are optional. Leave them out and you get 15%, with VAT-inclusive prices.

| Item price | `inclusive` (default) | `exclusive` |
|---|---|---|
| 115.00 | net 100.00 + VAT 15.00 = **115.00** | net 115.00 + VAT 17.25 = **132.25** |
| 100.00 | net 86.96 + VAT 13.04 = **100.00** | net 100.00 + VAT 15.00 = **115.00** |

To use VAT-exclusive prices:

```dotenv
ZATCA_VAT_MODE=exclusive
```

Then refresh the cached config and restart the workers (they keep the old config in memory):

```bash
php artisan config:cache
```

```bash
php artisan queue:restart
```

- The package reads these through `config('zatca.vat')`, never `env()`, so `config:cache` works.
- An invalid value (for example `ZATCA_VAT_MODE=gross` or `ZATCA_VAT_PERCENTAGE=15.555`) stops invoice generation with a message naming the key. `zatca:health-check` shows the values in use and fails on an invalid one.
- A change applies to **new** documents only. A signed invoice keeps its rates forever: its credit notes and any regeneration use the rates frozen in it, not the current settings.

### Optional: a second EGS for B2B

B2B documents need their **own EGS**, with its own CSR, certificate, counter and hash chain. Seller details are copied from the B2C EGS automatically. Only these values differ:

```dotenv
ZATCA_B2B_ENABLED=false                # turn on only when B2B is fully onboarded and verified
ZATCA_B2B_EGS_IDENTIFIER=b2b           # must be different from ZATCA_EGS_IDENTIFIER
ZATCA_B2B_CSR_COMMON_NAME="AlNoor-B2B-1"
ZATCA_B2B_CSR_SERIAL_NUMBER="1-AlNoor|2-B2B|3-1c9b1f3e-0f2e-4a47-9a25-1b2c3d4e5f60"
ZATCA_B2B_INVOICE_TYPE=1000            # see the note below
ZATCA_B2B_ORG_UNIT_NAME=               # optional, defaults to ZATCA_ORG_UNIT_NAME
ZATCA_B2B_ENVIRONMENT=                 # optional, defaults to ZATCA_ENVIRONMENT
```

`ZATCA_B2B_INVOICE_TYPE` is the CSR's 4-digit "invoice type". The first digit means "standard" and the second means "simplified". So `1000` means standard only, and `1100` means both. The developer-portal sandbox accepts `1000` for the separate B2B device (the sandbox suite onboards it). The sandbox is lenient, so confirm the value in simulation onboarding too.

### All other options

| `.env` key | Default | What it does |
|---|---|---|
| `ZATCA_API_VERSION` | `V2` | ZATCA API version. Don't change it. |
| `ZATCA_UBL_VERSION` | `2.1` | UBL version. Don't change it. |
| `ZATCA_XML_PROFILE_VERSION` | `V2` | XML profile. Don't change it. |
| `ZATCA_API_BASE_URL_SANDBOX` | ZATCA developer-portal URL | Only change it if ZATCA moves the host. |
| `ZATCA_API_BASE_URL_SIMULATION` | ZATCA simulation URL | Same. |
| `ZATCA_API_BASE_URL_PRODUCTION` | ZATCA core URL | Same. |
| `ZATCA_TIMEOUT` | `30` | HTTP timeout in seconds. |
| `ZATCA_ACCEPT_LANGUAGE` | `ar` | Language of ZATCA's messages (`ar` or `en`). |
| `ZATCA_GENERATION_RETRY_TRIES` | `5` | How many times a generation job may retry (back-off: 5s, 30s, 2m, 10m). |
| `ZATCA_REPORTING_RETRY_ATTEMPTS` | `12` | How many sends before an invoice stops and waits for a human. With a 5-minute sweep, that's about 1 hour of ZATCA downtime. |
| `ZATCA_AUTO_RENEWAL_ENABLED` | `false` | Lets `zatca:certificate:renew` call ZATCA. Leave it `false` (see [Certificates](#certificates)). |
| `ZATCA_CREDENTIAL_ENCRYPTER` | `laravel` | Label only. To use Vault or KMS, bind your own encrypter (see [Custom bindings](#custom-bindings)). |
| `ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS` | `180` | Age after which old attempt rows of accepted invoices are deleted. |
| `ZATCA_PRUNE_SDK_FILES_AFTER_DAYS` | `7` | Age after which leftover SDK temp files are deleted. |

In `config/zatca.php` only (no `.env` key): `certificate.alert_days` (`[60, 30, 7]`; only the largest value is used, as the "expiring soon" threshold) and `retries.generation.backoff_seconds`.

After any `.env` change in production, run `php artisan config:cache` and `php artisan queue:restart`.

<a id="environments"></a>
### Environments

| `ZATCA_ENVIRONMENT` | ZATCA name | Use it for | Legal invoices? |
|---|---|---|---|
| `sandbox` | Developer portal | Building and testing your code. Use the test VAT number `399999999900003` and the test OTP `123345`. It is lenient: it accepts any OTP, issues a production certificate even before the compliance checks, and that certificate is a shared one that does not match your key. | No |
| `simulation` | Simulation portal | A full rehearsal with your real company data and a real OTP from the simulation portal. | No |
| `production` | Core | Real invoices. | **Yes** |

Credentials, counters and hash chains are stored **per environment**. When you switch environments, you onboard again, and nothing from the old environment is reused.

---

## 7. How the package works

```text
Your app                          Package (queue: "zatca")                        ZATCA
────────                          ────────────────────────                        ─────
Order paid
  └─ dispatch GenerateZatcaInvoiceJob
                                  1. load your order (fresh from DB)
                                  2. call YOUR toInvoiceInput()  (items, quantities, prices)
                                  3. calculate VAT and totals, check they add up
                                  4. reserve counter (ICV), UUID, previous hash
                                  5. build XML  → save as "draft"
                                  6. sign with SDK, make QR → "pending_reporting" (B2C) / "pending_clearance" (B2B)
                                     fire ZatcaInvoiceGenerated
                                  7. simplified → ReportZatcaInvoiceJob ───────────►  /invoices/reporting/single
                                     standard   → ClearZatcaInvoiceJob  ───────────►  /invoices/clearance/single
                                  8. save result: reported / cleared / retrying / manual_review …
```

**Done once per EGS and environment:** CSR, compliance CSID, compliance checks, production CSID (see [Onboarding](#8-zatca-onboarding)).

**Done for every invoice:** steps 1 to 8 above. All of them are automatic once you dispatch the job.

**You write only:**
1. A class that implements `Zatca\Contracts\InvoiceSourceContract` (describes your order as a `ZatcaInvoiceInput`: items, quantities and prices. No VAT arithmetic).
2. One line binding it in a service provider.
3. A `dispatch()` call after a sale.

---

## 8. ZATCA onboarding

Onboarding gives your EGS its certificate. You do it **once per EGS, per environment**. Do it in `sandbox` first, then `simulation`, then `production`.

### What you need from the business

- VAT number, CR number, English and Arabic legal names, branch name, business category, national address. Put them in `.env`.
- Access to the **Fatoora portal** (simulation or production) to create an **OTP**. An OTP expires quickly, usually within 1 hour, so create it right before step 2.

### The order of steps

```bash
# 1. Create the private key + CSR (stored encrypted in the database). No ZATCA call.
php artisan zatca:generate-credentials --egs=default
```

It prints the SDK fingerprint. Put that value in `.env` as `ZATCA_SDK_FINGERPRINT`, then run `php artisan config:clear`.

```bash
# 2. Get the compliance (test) certificate. Needs the OTP.
php artisan zatca:onboard compliance --egs=default --otp=123456
```

```bash
# 3. Prove your documents are valid. Run all three, in this order.
php artisan zatca:onboard test-invoice --egs=default
```

```bash
php artisan zatca:onboard test-credit-note --egs=default
```

```bash
php artisan zatca:onboard test-debit-note --egs=default
```

Each one builds a sample document, signs it with the compliance certificate and sends it to ZATCA. You should see `Status: PASS` (or `WARNING`). `ERROR` means fix the problem and run that step again. The credit and debit note checks reference the last test invoice, so run `test-invoice` first.

```bash
# 4. Get the production certificate. Invoices can be signed from now on.
php artisan zatca:onboard production --egs=default
```

```bash
# 5. Confirm.
php artisan zatca:health-check
```

```bash
php artisan zatca:inspect credentials --egs=default
```

Success looks like this: `[OK] [default] compliance credentials present` and `[OK] [default] production credentials present — certificate state: valid`.

### Onboarding the B2B EGS

Set the `ZATCA_B2B_*` values, then run the same five steps with `--egs=b2b`. Because `b2b` is not your primary EGS, the package automatically sends **standard** test documents for it (standard invoice, credit note and debit note).

### Doing the same from PHP

The commands call `Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator`. You can call it from your own admin screen:

```php
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;

$onboarding = app(ComplianceOnboardingOrchestrator::class);

$onboarding->generateCredentials('default');            // ['csr' => ..., 'privateKeyGenerated' => true, 'sdkFingerprint' => ...]
$onboarding->compliance('default', $otpFromAdmin);      // ['requestId' => ..., 'dispositionMessage' => ...]
$onboarding->testInvoice('default');                    // ['status' => 'pass'|'warning'|'error', 'warnings' => [...], 'errors' => [...]]
$onboarding->testCreditNote('default');
$onboarding->testDebitNote('default');
$onboarding->production('default');                     // ['binarySecurityToken' => true]
```

### Moving from testing to production

1. Finish all steps in `sandbox` and test your real code there. Sandbox invoices are not legal.
2. Set `ZATCA_ENVIRONMENT=simulation`, run `config:clear`, and onboard again with an OTP from the **simulation** portal. Send a few real-looking invoices.
3. Set `ZATCA_ENVIRONMENT=production`, run `config:cache`, and onboard again with an OTP from the **production** Fatoora portal.
4. From now on every invoice is real. Never switch a live server back to `sandbox`.

Onboarding never touches invoices. It only adds credentials for the current environment.

---

## 9. Complete usage examples

All names, numbers and responses in this section are **fake example data**.

The examples use one fake business: **Al Noor Trading Co.**, a shop in Riyadh that sells electronics to people (B2C) and office furniture to companies (B2B).

### 9.1 Shared setup (do this once)

#### A. Your own tables

The package doesn't care what your tables look like. These examples assume:

```php
// orders:       id, customer_name, buyer_type ('person'|'company'), company_id (nullable), paid_at
// order_items:  id, order_id, name, quantity (int), price (decimal, the price you charge — incl. VAT by default),
//               vat_rate (nullable: only for an item taxed at a rate other than ZATCA_VAT_PERCENTAGE)
// companies:    id, legal_name, vat_number (nullable), cr_number (nullable),
//               street, building_number, district, city, postal_code, additional_number, country_code
```

#### B. The mapper: describe the order

This is the only real code you write. It works for **both** B2C and B2B. You give the package items, quantities and prices. It calculates the net amounts, VAT and totals itself, with `ZATCA_VAT_PERCENTAGE` and `ZATCA_VAT_MODE` (see [VAT settings](#vat-settings)).

```php
<?php

namespace App\Zatca;

use App\Models\Order;
use DateTimeImmutable;
use DateTimeZone;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\ZatcaComplianceDocumentType;

class OrderInvoiceSource implements InvoiceSourceContract
{
    /**
     * Unique per sale. The package uses it to stop duplicates, and it is printed
     * in the XML as the invoice number.
     */
    public function sourceReference(mixed $source): string
    {
        return 'INV-'.$source->id;
    }

    /** @param  Order  $order */
    public function toInvoiceInput(mixed $order, string $egsIdentifier): ZatcaInvoiceInput
    {
        $items = [];

        foreach ($order->items as $item) {
            $items[] = new ZatcaInvoiceItem(
                description: $item->name,
                quantity: (int) $item->quantity,
                price: $item->price,              // as you charge it; no VAT arithmetic here
                vatRate: $item->vat_rate,         // null → ZATCA_VAT_PERCENTAGE
            );
        }

        // Saudi local time. Use the moment of the sale, not "now", so retries keep the same date.
        $issuedAt = DateTimeImmutable::createFromInterface($order->paid_at)
            ->setTimezone(new DateTimeZone('Asia/Riyadh'));

        $isCompany = $order->buyer_type === 'company';

        return new ZatcaInvoiceInput(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($order),
            buyerName: $isCompany ? $order->company->legal_name : $order->customer_name,
            buyerVatNumber: $isCompany ? $order->company->vat_number : null,
            issueDateTime: $issuedAt,
            currency: 'SAR',
            items: $items,
            documentType: $isCompany
                ? ZatcaComplianceDocumentType::StandardInvoice   // B2B → Clearance
                : ZatcaComplianceDocumentType::Invoice,          // B2C → Reporting
            buyer: $isCompany ? $this->buyerParty($order) : null,
            supplyDate: $isCompany ? $issuedAt : null,           // required for B2B: the delivery date
        );
    }

    private function buyerParty(Order $order): BuyerParty
    {
        $c = $order->company;

        return new BuyerParty(
            registrationName: $c->legal_name,
            // Exactly ONE of these two: a VAT number, or another ID such as a CR number.
            vatNumber: $c->vat_number,
            otherId: $c->vat_number ? null : BuyerOtherId::commercialRegistration($c->cr_number),
            address: new BuyerAddress(
                street: $c->street,
                buildingNumber: $c->building_number,
                district: $c->district,
                city: $c->city,
                postalCode: $c->postal_code,
                countryCode: $c->country_code ?? 'SA',
                additionalNumber: $c->additional_number,
            ),
        );
    }
}
```

#### C. Bind it

```php
// app/Providers/AppServiceProvider.php
use App\Zatca\OrderInvoiceSource;
use Zatca\Contracts\InvoiceSourceContract;

public function register(): void
{
    $this->app->bind(InvoiceSourceContract::class, OrderInvoiceSource::class);
}
```

The package loads your order again inside the queue with `Model::find()` (`EloquentInvoiceSourceResolver`). If your source is not an Eloquent model, see [Custom bindings](#custom-bindings).

#### D. Run a queue worker

```bash
php artisan queue:work --queue=zatca
```

Every ZATCA job runs on the `zatca` queue (`ZATCA_QUEUE_NAME`). Keep this worker running in production under Supervisor or systemd (see [Server Setup & Production Deployment](#server-setup)).

#### E. Pick the right EGS

```php
$b2cEgs = config('zatca.primary_egs');           // e.g. "default"
$b2bEgs = config('zatca.b2b.egs_identifier');    // e.g. "b2b" (null if not configured)
```

### 9.2 Scenario 1: B2C invoice (Reporting)

**Situation:** Sara buys 2 headphones at 172.50 SAR each and 1 charger at 57.50 SAR. These are shelf prices with VAT included (the default `ZATCA_VAT_MODE=inclusive`, 15%). Your mapper passes just these prices; the package calculates the rest:

| Line | Item | Qty | Price you pass | Net (calculated) | VAT (calculated) | Total |
|---|---|---|---|---|---|---|
| 0 | Wireless headphones | 2 | 172.50 | 300.00 | 45.00 | 345.00 |
| 1 | USB-C charger | 1 | 57.50 | 50.00 | 7.50 | 57.50 |
| | **Totals** | | | **350.00** | **52.50** | **402.50** |

**Send it** right after the payment is saved:

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;

// Inside your checkout, after the order is saved and paid:
dispatch(
    GenerateZatcaInvoiceJob::forModel($order, config('zatca.primary_egs'))->afterCommit()
);
```

`afterCommit()` makes sure the job only runs after your order is really saved. Your checkout returns immediately. ZATCA never slows it down.

**What happens next (automatic):** the worker builds, signs and saves the invoice (`pending_reporting`), then `ReportZatcaInvoiceJob` sends it. A few seconds later the status is `reported`.

**Read the result:**

```php
use Zatca\Models\ZatcaInvoice;

$invoice = ZatcaInvoice::where('source_reference', 'INV-1001')->first()?->authoritative();

$invoice->status;                          // ZatcaInvoiceStatus::Reported
$invoice->status->isTerminalSuccess();     // true → accepted by ZATCA, never sent again
$invoice->uuid;                            // "8d2b4c1e-...": package-generated
$invoice->icv;                             // 57: package-generated counter
$invoice->qr_code;                         // base64 TLV QR: print this on the receipt
$invoice->signed_xml_base64;               // the legal signed XML
$invoice->acceptedAt();                    // when ZATCA accepted it
$invoice->last_error;                      // null, or ZATCA's warnings/errors
```

`authoritative()` returns the current document, even if this one was later replaced by a re-issue.

**Expected response (fake example, stored in `last_zatca_response`):**

```json
{
  "status": "PASS",
  "reportingStatus": "REPORTED",
  "alreadyReported": false,
  "errorCodes": [],
  "warningCodes": [],
  "warningMessages": [],
  "validationErrors": [],
  "validationWarnings": []
}
```

**Success or failure:** listen to events instead of polling.

```php
// app/Providers/EventServiceProvider.php
use Zatca\Events\ZatcaInvoiceGenerated;      // signed + QR ready → render your PDF/receipt
use Zatca\Events\ZatcaInvoiceNeedsAttention; // stuck → alert someone

protected $listen = [
    ZatcaInvoiceGenerated::class => [\App\Listeners\SendInvoicePdf::class],
    ZatcaInvoiceNeedsAttention::class => [\App\Listeners\AlertAccounting::class],
];
```

```php
// app/Listeners/AlertAccounting.php
public function handle(\Zatca\Events\ZatcaInvoiceNeedsAttention $event): void
{
    // $event->invoice (ZatcaInvoice), $event->reason (safe, human-readable text)
    logger()->error("ZATCA invoice #{$event->invoice->id} needs attention: {$event->reason}");
}
```

**Optional: a document-level discount.** Say Sara had a 57.50 SAR coupon. Items stay at full price and the discount goes in `discounts`, in the same price mode as the items. The package takes the VAT out of it and calculates the totals after the discount:

```php
use Zatca\DTOs\ZatcaInvoiceDiscount;

// in toInvoiceInput(), next to items:
discounts: [
    new ZatcaInvoiceDiscount(reason: 'Coupon SAVE50', amount: 57.50),   // net 50.00 + VAT 7.50
],
// Totals (calculated): net 300.00, VAT 45.00, total 345.00 (= 402.50 − 57.50).
```

The discount takes the VAT rate and category of the items it covers when they all share one. On an invoice with mixed rates, set `vatRate:` (or `vatCategory:`) on the discount, or list the items it covers with `lineIndexes: [0, 1]`. `lineIndexes` (and an optional `poolKey`) also tell a later credit note how much of the discount belongs to each item.

### 9.3 Scenario 2: B2C credit note (Reporting)

**Situation:** Sara returns the charger (line `1` of invoice `INV-1001`). She gets 57.50 SAR back.

**Rules:**
- The original must be **accepted** by ZATCA first (`reported` or `cleared`).
- Only one credit **or** debit note can be in progress per invoice at a time.
- `issue()` credits **whole lines**. To credit part of a line (for example 1 of 2 headphones), use a price adjustment (see below).
- Line numbers start at `0`, in the same order as the original invoice's lines.

```php
use Illuminate\Support\Str;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteIssuer;

$original = ZatcaInvoice::where('source_reference', 'INV-1001')->firstOrFail();

// Optional: check first (great for showing/hiding a "Refund" button).
$verdict = app(CreditNoteEligibilityPolicy::class)->verdict($original);
if (! $verdict->eligible) {
    return back()->withErrors("Cannot credit: {$verdict->reason}");
}

$creditNote = app(CreditNoteIssuer::class)->issue(
    original: $original,
    type: ZatcaCreditNoteType::Partial,     // Full = every line still available
    lineIndexes: [1],                       // the charger
    reason: 'Customer returned the USB-C charger',   // goes into the signed XML (max 1000 chars)
    idempotencyKey: $refundRequest->zatca_key ??= (string) Str::uuid(),  // save it, reuse it on retry
    requestedBy: ['type' => 'user', 'id' => auth()->id()],
    expectedTotalInclVat: 57.50,            // optional safety check: refuse if the amount differs
);

$creditNote->credit_note_number;   // "CN-000001": package-generated, also the XML invoice number
$creditNote->total_incl_vat;       // 57.50
```

**How it references the original:** the package takes the original's invoice number (`INV-1001`) as the billing reference and copies the credited line from the original's **frozen** data. You never pass amounts for whole-line credits.

**What happens next (automatic):** after your DB transaction commits, `GenerateZatcaCreditNoteJob` builds a simplified credit note (381), signs it and reports it.

**Read the result:**

```php
use Zatca\Services\CreditNote\CreditNoteStatusResolver;

$creditNote->refresh();
$creditNote->isAccepted();                                   // true once ZATCA accepted it
app(CreditNoteStatusResolver::class)->resolve($creditNote);  // ZatcaCreditNoteDisplayStatus::Accepted
$creditNote->latestZatcaInvoice;                             // the ZatcaInvoice row (status, qr_code, XML…)
```

Listen to `Zatca\Events\ZatcaCreditNoteAccepted` to refund money, restock items and so on.

**Crediting part of a line (price adjustment):** Sara keeps both headphones but gets 23.00 SAR back because of a scratch:

```php
use Zatca\Services\CreditNote\CreditSource;

$creditNote = app(CreditNoteIssuer::class)->issuePriceAdjustment(
    original: $original,
    sourceKey: CreditSource::invoiceLineKey(0),   // "line:0" = the headphones line
    amount: 23.00,                                 // net 20.00 + VAT 3.00, split by the package
    description: 'Discount for scratched headphones',
    reason: 'Price reduced after delivery: cosmetic damage',
    idempotencyKey: (string) Str::uuid(),
);
```

`amount` is read in the price mode the line was **invoiced** in (VAT-inclusive here) and split at that line's own VAT rate. Both are frozen in the original invoice, so changing `ZATCA_VAT_PERCENTAGE` or `ZATCA_VAT_MODE` later never changes how an old invoice is credited.

The adjustment must stay **below** what is left of that line. Reducing a line to zero is a return, so use `issue()` for that. To adjust several lines in one note, use `issuePriceAdjustments()` with an array of `new PriceAdjustmentInput($sourceKey, $amount, $description)` (`Zatca\Services\CreditNote\PriceAdjustmentInput`).

### 9.4 Scenario 3: B2C debit note (Reporting)

**Situation:** Al Noor forgot to charge Sara for gift wrapping (23.00 SAR with VAT).

```php
use Illuminate\Support\Str;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;

$original = ZatcaInvoice::where('source_reference', 'INV-1001')->firstOrFail();

$debitNote = app(DebitNoteIssuer::class)->issue(
    original: $original,
    reasonType: ZatcaDebitNoteReason::AdditionalItem,
    lines: [
        new DebitNoteLineInput(
            description: 'Gift wrapping',
            quantity: 1,
            price: 23.00,             // in ZATCA_VAT_MODE, like an invoice item → net 20.00 + VAT 3.00
            itemId: 501,              // optional: your own product id, returned to you on acceptance
        ),
    ],
    reason: 'Gift wrapping was not charged on the original invoice',
    idempotencyKey: (string) Str::uuid(),
    requestedBy: ['type' => 'user', 'id' => auth()->id()],
);

$debitNote->debit_note_number;   // "DN-000001"
$debitNote->total_incl_vat;      // 23.00
```

**Debit note reasons (`ZatcaDebitNoteReason`):**

| Reason | Use it when | Line rules |
|---|---|---|
| `AdditionalItem` | Something was delivered but not invoiced | New line. Set `itemId` if you want it back in your listener. |
| `PriceAdjustment` | A line on the original was under-priced | Set `originalLineIndex` (or `sourceDebitNoteLineId` for an item added by an earlier debit note). The line is taxed like that item (its frozen rate and category) unless you set `vatRate:`. |
| `VatAdjustment` | VAT was under-charged | `DebitNoteLineInput::vatAdjustment('VAT correction', vatAmount: 7.50, originalLineIndex: 0)`: no net amount, only the VAT you name. |
| `Other` | Anything else | Free description |

**What happens next (automatic):** `GenerateZatcaDebitNoteJob` builds a simplified debit note (383), signs it and reports it. Read it the same way as a credit note (`$debitNote->isAccepted()`, `DebitNoteStatusResolver`, `latestZatcaInvoice`). Listen to `Zatca\Events\ZatcaDebitNoteAccepted` to charge the customer.

### 9.5 Scenario 4: B2B invoice (Clearance)

> B2B passes in ZATCA's developer-portal sandbox, but has not been run in simulation yet. Read [Known limitations](#known-limitations) first.

**Situation:** Riyadh Office Supplies Co. (VAT `300000000000003`) buys 20 office chairs at 400.00 and 2 desks at 1,500.00. Company prices are quoted **before VAT**, so the mapper marks company invoices as exclusive (one optional line, shown below).

| Line | Item | Qty | Price you pass (excl. VAT) | Net | VAT 15% |
|---|---|---|---|---|---|
| 0 | Office chair | 20 | 400.00 | 8,000.00 | 1,200.00 |
| 1 | Office desk | 2 | 1,500.00 | 3,000.00 | 450.00 |
| | **Totals** | | | **11,000.00** | **1,650.00** (incl. 12,650.00) |

**What's different from B2C:**
- `documentType` is `StandardInvoice`.
- `buyer` (a `BuyerParty`) is **required**: legal name, a VAT number **or** another ID, and an address. For a Saudi buyer, street, building number, district, city and a 5-digit postal code are all required.
- `supplyDate` is **required**.
- It must use the **B2B EGS**.
- The package does **not** check `ZATCA_B2B_ENABLED` for you, so check readiness yourself.

The mapper from [9.1](#91-shared-setup-do-this-once) already handles all of this when `buyer_type` is `company`. If, like here, company prices exclude VAT while shop prices include it, add one argument to the `ZatcaInvoiceInput` it returns:

```php
use Zatca\Enums\VatPriceMode;

priceMode: $isCompany ? VatPriceMode::Exclusive : null,   // null = ZATCA_VAT_MODE
```

Then dispatch with the B2B EGS:

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Support\B2bReadiness;

if (! app(B2bReadiness::class)->ready()) {
    // B2B switch is off, or the B2B EGS is not onboarded / its certificate is not valid.
    abort(422, 'Company invoices are not available right now.');
}

dispatch(
    GenerateZatcaInvoiceJob::forModel($order, config('zatca.b2b.egs_identifier'))->afterCommit()
);
```

**What happens next (automatic):** the invoice is built and signed, then `ClearZatcaInvoiceJob` sends it to the Clearance API. ZATCA checks it, stamps it and returns its own copy.

**Read the result:**

```php
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\SDK\SignedInvoiceXmlReader;

$invoice = ZatcaInvoice::where('source_reference', 'INV-2001')->first()?->authoritative();

$invoice->status;               // ZatcaInvoiceStatus::Cleared (or ClearedWithWarnings)
$invoice->clearance_status;     // "CLEARED"
$invoice->cleared_at;
$invoice->cleared_xml_base64;   // ZATCA's stamped copy: this is the legal invoice for the buyer

// The QR for the buyer's PDF comes from ZATCA's cleared copy.
$qr = app(SignedInvoiceXmlReader::class)->extractQrCode(base64_decode($invoice->cleared_xml_base64));
```

In the developer-portal sandbox, `cleared_xml_base64` is the same document (same UUID) with a QR code added, as ZATCA describes Clearance. Whether that is exactly what you must give the buyer is still an open item in this project (gate G-06), so confirm it in simulation.

**Expected response (fake example).** The cleaned body goes into `last_zatca_response` with the same keys as Reporting. ZATCA's `clearanceStatus` goes into the `clearance_status` column, and ZATCA's `clearedInvoice` goes into `cleared_xml_base64`:

```json
{
  "status": "PASS",
  "reportingStatus": null,
  "alreadyReported": false,
  "errorCodes": [],
  "warningCodes": [],
  "warningMessages": [],
  "validationErrors": [],
  "validationWarnings": []
}
```

**Rejected:** `status = clearance_rejected`, `clearance_status = "NOT_CLEARED"`, and `last_error` holds ZATCA's messages. That document is **never** sent again. Fix the data and re-issue it (see [Regeneration](#re-issuing-a-rejected-invoice-regeneration)).

### 9.6 Scenario 5: B2B credit note (Clearance)

**Situation:** 5 of the 20 chairs arrived damaged. The buyer sends them back.

The credit note code is **the same** as in B2C. The package sees that the original is a standard invoice and makes a **standard credit note** that goes through **Clearance**. Buyer details and supply date are copied from the original automatically.

The original must be `cleared` first.

Because `issue()` credits whole lines and the chairs line has 20 units, credit 5 chairs by amount with a price adjustment:

```php
use Illuminate\Support\Str;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditSource;

$original = ZatcaInvoice::where('source_reference', 'INV-2001')->firstOrFail();

$creditNote = app(CreditNoteIssuer::class)->issuePriceAdjustment(
    original: $original,
    sourceKey: CreditSource::invoiceLineKey(0),   // the chairs line
    amount: 2000.00,                               // 5 × 400.00, excl. VAT like the invoice → + 300.00 VAT
    description: 'Return of 5 damaged office chairs',
    reason: 'Goods returned: 5 chairs damaged in delivery',
    idempotencyKey: (string) Str::uuid(),
    requestedBy: ['type' => 'user', 'id' => auth()->id()],
);
// → CN-000002, 2,300.00 SAR, sent by Clearance
```

If the whole desks line came back, you would use `issue(type: ZatcaCreditNoteType::Partial, lineIndexes: [1], ...)` exactly like Scenario 2.

Read the result like Scenario 2, plus `->latestZatcaInvoice->cleared_xml_base64` for ZATCA's stamped copy.

### 9.7 Scenario 6: B2B debit note (Clearance)

**Situation:** the desks were invoiced at 1,500.00 but the agreed price was 1,600.00. Charge 2 × 100.00 more.

```php
use Illuminate\Support\Str;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;

$original = ZatcaInvoice::where('source_reference', 'INV-2001')->firstOrFail();

$debitNote = app(DebitNoteIssuer::class)->issue(
    original: $original,
    reasonType: ZatcaDebitNoteReason::PriceAdjustment,
    lines: [
        new DebitNoteLineInput(
            description: 'Price correction: office desk (2 × 100.00)',
            quantity: 2,
            price: 100.00,
            originalLineIndex: 1,       // the desks line on INV-2001: taxed at its frozen 15%
        ),
    ],
    reason: 'Agreed price was 1,600.00 per desk',
    idempotencyKey: (string) Str::uuid(),
    priceMode: VatPriceMode::Exclusive, // company prices exclude VAT; null = ZATCA_VAT_MODE
);
// → DN-000002, 230.00 SAR, standard debit note, sent by Clearance
```

### 9.8 What to change for your project

| In the examples | Replace with |
|---|---|
| `App\Models\Order`, `items`, `company` | Your own models and relations |
| `'INV-'.$source->id` | Your own unique invoice number |
| `paid_at` | The moment the sale happened |
| `price` per item | Your selling price, in `ZATCA_VAT_MODE` (VAT-inclusive by default) |
| `vat_rate` per item | `null` for the normal rate. Set it only for an item taxed differently. For 0% items use `ZatcaInvoiceItem::zeroRated()` / `exempt()` (see [VAT categories](#vat-categories)). |
| `buyer_type === 'company'` | However you know the buyer is a company |
| `auth()->id()` in `requestedBy` | Whoever triggered the note (or leave `null`) |
| `Str::uuid()` idempotency keys | A UUID you **store** on your refund/charge request, so a retry or double-click reuses it |

---

## 10. Invoice fields explained

**You provide** these in `ZatcaInvoiceInput` (through your mapper). No amounts other than prices: the package calculates net amounts, VAT and totals.

| Field | Meaning | Required? | Notes |
|---|---|---|---|
| `egsIdentifier` | Which EGS signs it | Yes | Passed to you. Return it as-is. |
| `sourceReference` | Your unique invoice number | Yes | Used for duplicate protection and printed as the invoice number. Must match `sourceReference()`. |
| `buyerName` | Buyer's name | B2C: optional | B2C: printed if set. B2B: use `buyer` instead. |
| `buyerVatNumber` | Buyer's VAT number | No | Kept for your records. B2B uses `buyer->vatNumber`. |
| `issueDateTime` | Invoice date and time | Yes | Written exactly as given, so use Saudi time (`Asia/Riyadh`). |
| `currency` | Currency code | Yes | Must be `SAR`. Anything else is refused. |
| `items` | Array of `ZatcaInvoiceItem` | Yes, at least 1 | See below |
| `discounts` | Document-level discounts | No | Array of `ZatcaInvoiceDiscount` |
| `priceMode` | What the prices on this invoice mean | No | `VatPriceMode::Inclusive` / `Exclusive`. `null` = `ZATCA_VAT_MODE` |
| `documentType` | `Invoice` (B2C) or `StandardInvoice` (B2B) | Default `Invoice` | Decides Reporting or Clearance |
| `buyer` | Company buyer (`BuyerParty`) | **B2B: yes** | Ignored for B2C |
| `supplyDate` | Delivery or supply date | **B2B: yes** | Ignored for B2C |

<a id="invoice-items"></a>
**Each item (`ZatcaInvoiceItem`):**

| Field | Meaning | Rule |
|---|---|---|
| `description` | Item name | Any text (escaped for XML for you) |
| `quantity` | How many | Whole number, at least 1 |
| `price` | Price of ONE unit, as you charge it | Not negative. VAT-inclusive or exclusive per the price mode. Up to 6 decimals. |
| `vatRate` | VAT % for this item only, e.g. `5` | Optional. `null` = `ZATCA_VAT_PERCENTAGE`. 0–100, at most 2 decimals. Standard-rated items only. |
| `vatCategory` | VAT category | Optional. `ZatcaVatCategory::Standard` (default). Use `ZatcaInvoiceItem::zeroRated()` / `exempt()` for 0% items. |
| `vatExemptionReasonCode`, `vatExemptionReason` | ZATCA reason code and text | Required for zero-rated and exempt items |

**Each discount (`ZatcaInvoiceDiscount`):** `reason`, `amount` (in the same price mode as the items), and optionally `vatRate`, `vatCategory` with its exemption code and text, `lineIndexes` (the items it covers) and `poolKey` (your own name for that group, e.g. `bundle:7`).

<a id="how-vat-is-calculated"></a>
#### How VAT is calculated

All in exact decimal arithmetic (no floating point), rounding half-up to the halala:

1. **Per line, not per unit:** line amount = quantity × price, rounded once. Ten items at 100.00 are 1,000.00.
2. **Exclusive:** net = line amount; VAT = net × rate, rounded.
   **Inclusive:** total = line amount; net = total ÷ (1 + rate), rounded; VAT = total − net. So net + VAT is always exactly what the customer paid.
3. **Zero-rated / exempt:** no VAT, net = line amount, in either mode.
4. **The XML unit price** (`PriceAmount`) is the NET unit price, line net ÷ quantity, with up to 6 decimals when needed, so that quantity × price matches the line net (ZATCA rule BR-KSA-EN16931-11). A price that fits 2 decimals is written with 2.
5. **A discount** is calculated like a one-unit line, at its own rate.
6. **Totals** are the sums of the rounded lines and discounts.

| Items (15%) | Mode | Net | VAT | Total | XML unit price |
|---|---|---|---|---|---|
| 1 × 115.00 | inclusive | 100.00 | 15.00 | 115.00 | 100.00 |
| 1 × 100.00 | exclusive | 100.00 | 15.00 | 115.00 | 100.00 |
| 1 × 100.00 | inclusive | 86.96 | 13.04 | 100.00 | 86.96 |
| 10 × 100.00 | inclusive | 869.57 | 130.43 | 1,000.00 | 86.957 |
| 3 × 33.33 | inclusive | 86.95 | 13.04 | 99.99 | 28.983333 |
| 3 × 33.33 | exclusive | 99.99 | 15.00 | 114.99 | 33.33 |

With inclusive prices the VAT is what remains after the net is rounded, so it can differ by one halala from net × rate. ZATCA's rules allow this; the package's own check before signing does too.

**Before a counter number is used,** the package checks the calculated invoice: lines and totals reconcile (BR-CO-10/14/15), each VAT breakdown matches its taxable amount × rate within ZATCA's tolerance (BR-S-09), nothing is negative, and every VAT category has what it needs. You can also call the calculator yourself, for example to show totals before checkout:

```php
use Zatca\Services\Tax\InvoiceCalculator;

// $input = your mapper's ZatcaInvoiceInput, e.g. Sara's order from Scenario 1
$calculated = app(InvoiceCalculator::class)->calculate($input);   // ZatcaInvoiceData
$calculated->totalExclVat;             // 350.00
$calculated->totalVat;                 // 52.50
$calculated->totalInclVat;             // 402.50
$calculated->lines[0]->netUnitPrice;   // 150.0 (the headphones' net unit price, as in the XML)
$calculated->lines[0]->suppliedPrice;  // "172.50" (what you passed)
```

`ZatcaInvoiceData` and `ZatcaInvoiceLine` are the **calculated** form the package works with internally (validation, XML, snapshots, notes). You don't build them.

<a id="vat-categories"></a>
#### VAT categories, mixed rates and per-item rates

Most items are standard-rated at `ZATCA_VAT_PERCENTAGE`, and you don't need to do anything for those. One invoice can mix rates and categories; the XML gets one VAT breakdown per rate and category automatically.

```php
use Zatca\DTOs\ZatcaInvoiceItem;

$items = [
    // Standard, at ZATCA_VAT_PERCENTAGE (15%)
    new ZatcaInvoiceItem('Headphones', 1, 172.50),

    // Standard, at its own rate (overrides the default for this item only)
    new ZatcaInvoiceItem('Reduced-rate item', 2, 52.50, vatRate: 5),

    // Zero-rated: never gets VAT, needs ZATCA's reason
    ZatcaInvoiceItem::zeroRated('Export shipment', 1, 200.00, 'VATEX-SA-32', 'Export of goods'),

    // Exempt: never gets VAT, needs ZATCA's reason
    ZatcaInvoiceItem::exempt('Life insurance', 1, 500.00, 'VATEX-SA-29', 'Financial services'),
];
```

| Category | Rate | Needs a reason? | Example codes |
|---|---|---|---|
| `Standard` (`S`) | `ZATCA_VAT_PERCENTAGE`, or the item's `vatRate` | No | – |
| `ZeroRated` (`Z`) | always 0 | Yes | `VATEX-SA-32` export of goods, `VATEX-SA-33` export of services, `VATEX-SA-35` medicines |
| `Exempt` (`E`) | always 0 | Yes | `VATEX-SA-29` financial services, `VATEX-SA-30` residential rent |

Use ZATCA's official list of reason codes. The package checks the `VATEX-SA-...` format and that the reason text is filled in. A zero-rated or exempt item given a rate is refused, and so is a standard item at 0%. Discounts (`ZatcaInvoiceDiscount`) and debit note lines (`DebitNoteLineInput`) accept the same category fields. Credit notes always keep the rate and category of the line they credit, and a debit note line that adjusts an item keeps that item's rate and category unless you set its own.

**The package generates these. Never set them yourself:**

| Field | Where you find it |
|---|---|
| UUID | `zatca_invoices.uuid` |
| Invoice counter (ICV) | `zatca_invoices.icv` |
| Previous invoice hash (PIH) | `zatca_invoices.previous_invoice_hash` |
| Invoice hash | `zatca_invoices.invoice_hash` |
| Unsigned / signed XML | `unsigned_xml`, `signed_xml_base64` |
| Digital signature | inside `signed_xml_base64` |
| QR code | `qr_code` (B2C). For B2B see Scenario 4. |
| Credit / debit note number | `CN-000001` / `DN-000001` on the note record |
| Original invoice reference and reason in notes | taken from the original and from your `reason` |
| Seller name, VAT, CR, address | from `.env` |

---

## 11. Understanding ZATCA responses

### HTTP 200 is not the same as "accepted"

A request can succeed while ZATCA still refuses the invoice. Always check the **status** on the `ZatcaInvoice` row, never the HTTP call.

The simple rule is that an invoice is done only when `$invoice->status->isTerminalSuccess()` is `true`.

### All statuses

| Status | Flow | Meaning | What you do |
|---|---|---|---|
| `draft` | both | Built, not signed yet | Nothing. It finishes on retry. |
| `pending_reporting` | B2C | Signed, waiting to be reported | Nothing. The queue or sweep sends it. |
| `reported` | B2C | **Accepted** | Done |
| `reported_with_warnings` | B2C | **Accepted**, with warnings | Done. Read `last_error` and fix the data for next time. |
| `retrying` | B2C | ZATCA down (network or 5xx). Will retry. | Nothing |
| `manual_review` | B2C | Rejected (4xx) or retries used up | A human reads `last_error` |
| `cleared` | B2B | **Accepted and stamped** | Give `cleared_xml_base64` to the buyer |
| `cleared_with_warnings` | B2B | **Accepted**, with warnings | Same |
| `clearance_retrying` | B2B | ZATCA 5xx, will retry | Nothing |
| `clearance_in_doubt` | B2B | Network failure: unknown if ZATCA got it | Wait. The sweep retries it. |
| `clearance_rejected` | B2B | ZATCA said `NOT_CLEARED` | Fix and re-issue. Never resend. |
| `clearance_manual_review` | B2B | Other failure or retries used up | A human looks |
| `pending_clearance` | B2B | Signed, waiting to be cleared | Nothing. The queue or sweep sends it. |
| `failed`, `generated` | – | Defined but not written by the package today | – |

### Error types and where they end up

| Problem | HTTP | Result |
|---|---|---|
| Validation errors (bad totals, missing field…) | 400 | `manual_review` / `clearance_rejected`, with ZATCA's codes in `last_zatca_response.validationErrors` |
| Accepted with warnings | 200/202 | `reported_with_warnings` / `cleared_with_warnings` |
| Authentication (wrong or expired token) | 401 | `manual_review`. Check credentials and certificate. |
| Already reported (same invoice sent again) | 409 | Treated as success **only** if ZATCA's body confirms it |
| ZATCA server error | 5xx | `retrying` / `clearance_retrying`, retried up to `ZATCA_REPORTING_RETRY_ATTEMPTS` |
| Network failure, timeout, connection refused, DNS failure | 0 | B2C: `retrying`. B2B: `clearance_in_doubt`. An attempt row is saved with HTTP status 0. |
| Bad invoice data (your mapper): totals, line math, VAT category, currency, B2B buyer | – | Refused **before** a counter number is used. `ZatcaException` with a clear message, and the job retries then fails. |
| Certificate or signing problem (SDK) | – | `ZatcaSdkProcessException`. The invoice stays `draft` and the retry resumes it. |
| Expired production certificate | – | Sending is paused. Invoices wait in `pending_reporting`. |

### Fake example of a rejected B2C invoice (`last_zatca_response`)

```json
{
  "status": "ERROR",
  "reportingStatus": "NOT_REPORTED",
  "alreadyReported": false,
  "errorCodes": ["BR-KSA-EN16931-11"],
  "validationErrors": [
    { "code": "BR-KSA-EN16931-11", "message": "Invoice line net amount must equal quantity × price." }
  ],
  "validationWarnings": []
}
```

### What is saved for you

Everything you need is already in your database. You don't need to copy it anywhere:

- `zatca_invoices`: status, UUID, ICV, hashes, signed XML, QR, `last_zatca_response`, `last_error`, `reported_at` / `cleared_at`, `cleared_xml_base64`, and a frozen copy of the invoice data.
- `zatca_submission_attempts`: every attempt, with HTTP status, error code and the cleaned response. Secrets are removed before saving.

On **your** order, saving `source_reference` (for example `INV-1001`) is enough to find the document later.

### Retries and duplicates

- A retry **never** signs again. It sends the exact same signed XML, UUID and counter.
- Generating twice for the same `sourceReference` returns the existing document instead of making a new one.
- Accepted documents (`isTerminalSuccess()`) are never sent again.
- Use the same `idempotencyKey` when retrying a credit or debit note request, and you get the same note back.
- A rejected document is fixed by **re-issuing** (a new document with a new UUID and counter), not by resending.

### Exceptions you may catch

All extend `Zatca\Exceptions\ZatcaException`:

| Exception | When |
|---|---|
| `ZatcaException` | Invalid invoice data, missing CSR, a ZATCA onboarding call failed, bad config |
| `ZatcaCredentialsMissingException` | The EGS has no production certificate yet (onboarding not finished) |
| `ZatcaDisabledException` | You called the orchestrator directly while `ZATCA_ENABLED=false` |
| `CrossEgsDuplicateException` | This sale already has a document on another EGS (for example B2C and B2B) |
| `CreditNoteException` | Credit note refused (not eligible, nothing selected, amount changed, zero value…) |
| `DebitNoteException` | Debit note refused (not eligible, no lines, bad line reference…) |
| `ZatcaSdkProcessException` | Java or SDK failed, timed out, or produced no output |
| `ZatcaSdkOutputChangedException` | The SDK printed something the package can't read (different SDK version) |
| `ZatcaSdkFingerprintMismatchException` | The SDK jar changed |
| `ZatcaContractChangedException` | ZATCA returned an unexpected response shape |

---

## 12. More features

### Events

| Event | Fired when | Properties |
|---|---|---|
| `Zatca\Events\ZatcaInvoiceGenerated` | A document was signed (QR ready) | `invoice` |
| `Zatca\Events\ZatcaInvoiceNeedsAttention` | A document needs a human (`manual_review`, `clearance_*` problems) | `invoice`, `reason` |
| `Zatca\Events\ZatcaCreditNoteAccepted` | ZATCA accepted a credit note | `creditNote` |
| `Zatca\Events\ZatcaDebitNoteAccepted` | ZATCA accepted a debit note | `debitNote` |
| `Zatca\Events\ZatcaCertificateExpiring` | A certificate moved to "expiring soon" or "expired" | `egsIdentifier`, `credentialType`, `state`, `expiresAt`, `daysRemaining`, `hasExpired()` |

Acceptance means "ZATCA accepted the document". It does not mean money moved. Never mark anything paid from these events.

Helpers on the note reasons let your listener decide what to do:

```php
$event->creditNote->creditReason()->cancelsItems();   // true = items were returned/cancelled
$event->debitNote->reason_type->addsItems();          // true = a new item was added
$event->debitNote->lines->pluck('item_id');           // your own ids from DebitNoteLineInput::itemId
```

<a id="kill-switch"></a>
### Kill switch (`ZATCA_ENABLED`)

Set `ZATCA_ENABLED=false` to stop **all** ZATCA work without losing anything. That means no new invoices, no counters used, no SDK calls and no requests to ZATCA. Jobs already in the queue exit quietly, and statuses stay as they are. Onboarding and read-only commands still work. Only `false`, `0`, `off` or `no` turns it off. An empty value keeps it on.

```bash
php artisan config:cache
```

```bash
php artisan queue:restart
```

In code, ask the gate (never read the config directly):

```php
use Zatca\Support\ZatcaGate;

app(ZatcaGate::class)->enabled();      // global switch
app(ZatcaGate::class)->b2bEnabled();   // global switch AND ZATCA_B2B_ENABLED
```

Sales made while ZATCA is off are **not** invoiced automatically later. Dispatch their jobs again when you turn it back on.

### B2B switch and readiness

`ZATCA_B2B_ENABLED` is **off unless set** to `true`, `1`, `on` or `yes`. `Zatca\Support\B2bReadiness::ready()` returns `true` only when the switch is on **and** the B2B EGS is configured, passed compliance, and has a valid production certificate. Use it to show or hide a "Buy as a company" option. Turning B2B off stops new company sales only, and B2B documents already signed still get cleared.

### Eligibility checks for notes

```php
app(\Zatca\Services\CreditNote\CreditNoteEligibilityPolicy::class)->allows($invoice);  // bool
app(\Zatca\Services\DebitNote\DebitNoteEligibilityPolicy::class)->allows($invoice);    // bool
```

`verdict($invoice)` gives you the reason, one of: ZATCA disabled, no document, not an invoice, not accepted yet, no snapshot, certificate expired, another note in progress, fully credited.

To see which lines can still be credited:

```php
$ledger = app(\Zatca\Services\CreditNote\CreditNoteLedger::class)->forOriginal($invoice);
$ledger->availableIndexes;   // e.g. [0] after the charger was returned
```

### Cancelling a note that never reached ZATCA

A note stuck before ZATCA accepted it (for example its generation failed) can be cancelled. That frees its lines for a new note:

```php
use Zatca\Services\CreditNote\CreditNoteCanceller;

$canceller = app(CreditNoteCanceller::class);
if ($canceller->isCancellable($creditNote)) {
    $canceller->cancel($creditNote, ['type' => 'user', 'id' => auth()->id()]);
}
```

`Zatca\Services\DebitNote\DebitNoteCanceller` works the same way. A note that might already be at ZATCA (in flight) cannot be cancelled.

### Note display status

`CreditNoteStatusResolver::resolve()` and `DebitNoteStatusResolver::resolve()` return one simple status for your admin screen: `pending`, `processing`, `accepted`, `rejected`, `failed`, `generation_failed` or `cancelled`.

<a id="re-issuing-a-rejected-invoice-regeneration"></a>
### Re-issuing a rejected invoice (regeneration)

If ZATCA **rejected** a document (`manual_review` with a 4xx, `clearance_rejected`, `clearance_manual_review`), fix the cause (config or code), then re-issue it. You get a **new** document (new UUID and counter) built from the original's frozen data. The old one is marked as replaced.

```php
use Zatca\Jobs\RegenerateZatcaInvoiceJob;
use Zatca\Services\Regeneration\RegenerationPolicy;

if (app(RegenerationPolicy::class)->allows($invoice)) {
    dispatch(new RegenerateZatcaInvoiceJob($invoice->id, ['type' => 'user', 'id' => auth()->id()]));
}
```

The replacement's `source_reference` gets a suffix (`INV-1001#r1`), and `$original->authoritative()` returns it. Transient failures (5xx or network) are **not** regenerable. Those are retried instead.

### Finishing a stuck draft

If signing failed (for example Java was missing), the document stays `draft` with its counter reserved. The next generation retry resumes it automatically. To resume it by hand:

```php
use Zatca\Jobs\CompleteZatcaInvoiceGenerationJob;
use Zatca\Services\Generation\DraftCompletionPolicy;

if (app(DraftCompletionPolicy::class)->allows($draft)) {
    dispatch(new CompleteZatcaInvoiceGenerationJob($draft->id, ['type' => 'user', 'id' => auth()->id()]));
}
```

<a id="certificates"></a>
### Certificates

- `zatca:certificate:check` (daily) updates each certificate's state (`valid`, `expiring_soon` from 60 days before expiry, or `expired`) and fires `ZatcaCertificateExpiring` once per change (so once at "expiring soon" and once at "expired").
- When the **production** certificate is expired, sending stops for that EGS, and invoices wait safely.
- **Renewal is turned off.** ZATCA's renewal API has not been verified in this package, so `zatca:certificate:renew` refuses to call ZATCA while `ZATCA_AUTO_RENEWAL_ENABLED=false`. Until then, renew by onboarding again with a new OTP (steps 1 to 4 of [Onboarding](#8-zatca-onboarding)) before the certificate expires. **Test this in simulation first.**

<a id="custom-bindings"></a>
### Custom bindings

| Interface | Default | Bind your own when… |
|---|---|---|
| `Zatca\Contracts\InvoiceSourceContract` | **none, you must bind it** | Always (your mapper) |
| `Zatca\Contracts\InvoiceSourceResolverContract` | `EloquentInvoiceSourceResolver` (`Model::find`) | Your source isn't a plain Eloquent model |
| `Zatca\Contracts\CredentialEncrypterInterface` | `LaravelCredentialEncrypter` (`APP_KEY`) | You want Vault/KMS |
| `Zatca\Contracts\OwnerResolverInterface` | `NullOwnerResolver` (returns `null`) | Multi-tenant: fill `zatca_invoices.owner_id` per EGS |

```php
$this->app->bind(\Zatca\Contracts\CredentialEncrypterInterface::class, \App\Zatca\KmsEncrypter::class);
```

### Reading state in code

```php
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\State\EgsRegistry;

app(ZatcaInspector::class)->credentials('default'); // credential types, cert state, expiry (no secrets)
app(ZatcaInspector::class)->state('default');       // current ICV and a hash preview
app(ZatcaInspector::class)->invoice('42');          // by id or UUID (no XML)

app(EgsRegistry::class)->identifiers();             // ['default', 'b2b']
app(EgsRegistry::class)->primary();                 // EgsConfig of the B2C EGS
```

`Zatca\Services\Security\ZatcaSanitizer` is used internally to remove tokens, secrets and keys from logs and stored responses. You can use it for your own logs.

---

## 13. Console commands

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

Run the steps in the order shown in [Onboarding](#8-zatca-onboarding). Test steps print `Status: PASS/WARNING/ERROR` and exit with code 1 on `ERROR`. Each test step uses up a compliance counter number, which is normal.

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
- the credentials and certificate state of every EGS.

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

It takes no options. It re-dispatches every document in `pending_reporting`, `retrying`, `pending_clearance`, `clearance_retrying` or `clearance_in_doubt`. It never touches `manual_review` or rejected documents, and it never signs again. Schedule it every 5 minutes. When ZATCA is disabled, it does nothing.

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

It sends stuck documents again with the same signed XML. B2C documents go to Reporting and B2B documents go to Clearance. The package picks the route from the document itself. Accepted documents (`reported`, `cleared`…) and `clearance_rejected` are never resent, even with `--id`. Read `last_error` before requeueing a validation failure, because a rejected document will just be rejected again (use [regeneration](#re-issuing-a-rejected-invoice-regeneration) for those).

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
| Rejected by ZATCA (`manual_review` / `clearance_manual_review` with a 4xx, or `clearance_rejected`) | **Stops.** The same document would be rejected again. Fix the cause and [regenerate](#re-issuing-a-rejected-invoice-regeneration) it. |
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

It checks for documents waiting for a human (`manual_review`, `clearance_manual_review`, `clearance_rejected`), documents stuck on their way to ZATCA, certificate expiry and failed jobs. It exits with code 1 only on failures (or on warnings with `--strict`).

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

---

<a id="server-setup"></a>
## 14. Server Setup & Production Deployment

Everything about preparing a real server — not just installing the Composer package — lives here: who needs access to what, the SDK, the queue worker, the scheduler, and a checklist before you flip `ZATCA_ENVIRONMENT=production`. If you only just want the package running locally for development, you don't need this section yet — see [Installation](#5-installation).

1. [Server requirements](#server-setup-requirements)
2. [Identify your server's users](#server-setup-identify-users)
3. [Configure storage permissions](#server-setup-storage-permissions)
4. [ZATCA SDK installation & server paths](#server-setup-sdk-install)
5. [Configure the queue worker](#server-setup-supervisor)
6. [Configure the scheduler (cron)](#server-setup-cron)
7. [First deploy and every redeploy](#server-setup-deploying)
8. [Production deployment checklist](#server-setup-checklist)
9. [Troubleshooting: server & infrastructure](#server-setup-troubleshooting)

<a id="server-setup-requirements"></a>
### 1. Server requirements

| What | Needed for | Detail |
|---|---|---|
| PHP 8.1+ (8.2+ for Laravel 11/12) with `openssl`, `dom`, `mbstring`, `ctype`, `json`, your PDO driver | The app itself | See [Requirements](#4-requirements) for the full compatibility matrix |
| Java 11–15 | Signing invoices (the ZATCA SDK is a Java program) | [§4 below](#server-setup-sdk-install) |
| The ZATCA SDK folder (`Apps/`, `Configuration/`, `Data/`) | Same | [§4 below](#server-setup-sdk-install) |
| A persistent queue worker (Supervisor or systemd) | Running `queue:work --queue=zatca` continuously — invoice generation and signing happen here, not in the web request | [§5 below](#server-setup-supervisor) |
| The Laravel scheduler (cron, once a minute) | The retry sweep, certificate checks, and cleanup commands | [§6 below](#server-setup-cron) |
| A writable `storage/app/private/zatca` directory | Short-lived signing files | [§3 below](#server-setup-storage-permissions) |
| Outbound HTTPS to `https://gw-fatoora.zatca.gov.sa` on port 443 | Reaching ZATCA at all | Allow it in your firewall/proxy |

<a id="server-setup-identify-users"></a>
### 2. Identify your server's users

Don't assume one `www-data` user runs everything — on most real servers there are at least two or three different system users involved, and the default on your distro may not be any of them:

| Role | What it needs for this package | Usually the user that... |
|---|---|---|
| **`WEB_USER`** | Normal Laravel `storage`/`bootstrap/cache` write access. **Does not need Java or SDK access**, unless `QUEUE_CONNECTION` (or `ZATCA_QUEUE_CONNECTION`) is set to `sync` — not recommended beyond quick local tests | Runs PHP-FPM / Apache for this specific app |
| **`QUEUE_USER`** | Java on its `PATH`, read access to the SDK folder, and write access to `storage/app/private/zatca` — because `GenerateZatcaInvoiceJob` runs the SDK **inside the queue worker**, not the web request | Runs `php artisan queue:work --queue=zatca` (via Supervisor/systemd) |
| **`CLI_USER`** | Same needs as `QUEUE_USER` (Java, SDK, `storage/app/private/zatca`) for as long as a command runs | Whoever SSHes in to run `php artisan zatca:*` by hand — onboarding, `zatca:recover`, etc. Often a third, separate "deploy" account |

These can be the same user on a small server, or three different ones on a shared host with multiple sites. Find each one for real instead of guessing.

**Find `WEB_USER`** (watch out for more than one PHP-FPM pool on the same server):

```bash
# Lists every php-fpm/apache worker user on the box — there may be several if the
# server hosts more than one site, so don't just take the first line blindly.
ps -eo user,comm | grep -E 'php-fpm|apache2|httpd' | grep -v root | sort -u
```

If more than one user shows up, confirm which one is *this* app, not someone else's site on the same box:

```bash
# Nginx: match this app's server_name to its fastcgi_pass, then find that socket's
# "user = " line in the matching PHP-FPM pool file.
sudo nginx -T 2>/dev/null | grep -nE 'server_name|fastcgi_pass'
sudo grep -nE '^\s*(user|listen)\s*=' /etc/php/*/fpm/pool.d/*.conf
```

(Apache with `mod_php` instead of FPM: the worker user is whatever Apache itself runs as — check `apache2ctl -S` or your distro's `apache2`/`httpd` service user.)

**Find `QUEUE_USER`** (from whichever process manager actually runs your queue worker):

```bash
# Supervisor:
sudo grep -RnE '^\s*(\[program:|command=|user=)' /etc/supervisor/conf.d/
```

```bash
# systemd, if you run the worker as a unit instead:
systemctl show your-queue-worker.service -p User
```

**`CLI_USER`** is simply whoever you are when you SSH in and run a command — `whoami` tells you. The only thing to check is whether it's the *same* account as `QUEUE_USER`; if not, it needs the same Java/SDK/storage access whenever you run `php artisan zatca:*` by hand.

**You do not have to make these the same user.** What matters is that each one can reach what it actually needs — see the next section for `storage/app/private/zatca` specifically, and [§4](#server-setup-sdk-install) for the SDK folder.

<a id="server-setup-storage-permissions"></a>
### 3. Configure storage permissions

This is about one folder specifically: `storage/app/private/zatca`, where the package writes short-lived signing files. (The rest of Laravel's `storage/` follows ordinary Laravel permission guidance — this package doesn't change that.)

```bash
mkdir -p storage/app/private/zatca
```

**Single-user server** (`WEB_USER`, `QUEUE_USER` and `CLI_USER` are all the same account):

```bash
QUEUE_USER=www-data   # ← whatever you found in §2 — replace this
chown -R "$QUEUE_USER" storage/app/private/zatca
chmod 700 storage/app/private/zatca
```

**Separate web and queue users**, where only `QUEUE_USER` ever touches this folder (the normal case — signing happens in the queue worker, not the web request):

```bash
QUEUE_USER=deploy   # ← whatever you found in §2
chown -R "$QUEUE_USER" storage/app/private/zatca
chmod 700 storage/app/private/zatca
```

`WEB_USER` needs nothing here in this case — leave it out entirely rather than widening permissions "just in case".

**Multiple users genuinely need access** (for example `QUEUE_USER` and a separate `CLI_USER` both sign invoices, or you sometimes run the queue `sync` so `WEB_USER` needs it too) — use a shared group instead of `chown`-ing everyone to one account or opening the folder up:

```bash
QUEUE_USER=deploy        # ← yours
CLI_USER=ops             # ← yours, if different from QUEUE_USER
sudo groupadd zatca-writers 2>/dev/null
sudo usermod -aG zatca-writers "$QUEUE_USER"
sudo usermod -aG zatca-writers "$CLI_USER"
sudo chown -R "$QUEUE_USER:zatca-writers" storage/app/private/zatca
sudo chmod -R 770 storage/app/private/zatca
sudo chmod g+s storage/app/private/zatca   # new files/folders inherit the group automatically
```

If your distro has `setfacl`/ACLs available and you'd rather not create a shared group, this reaches the same result without changing anyone's primary group:

```bash
setfacl -R -m u:"$QUEUE_USER":rwx,u:"$CLI_USER":rwx storage/app/private/zatca
setfacl -R -d -m u:"$QUEUE_USER":rwx,u:"$CLI_USER":rwx storage/app/private/zatca   # inherited by new files too
```

**Never `chmod 777` this folder, and never `chown -R` the whole `storage/` tree for ZATCA's sake.** It briefly holds certificate and private-key material while a signing process is running, before the package cleans it up (`zatca:prune-sdk-files` — see [§13](#13-console-commands)).

**Confirm it actually worked**, as whichever user(s) need it:

```bash
sudo -u "$QUEUE_USER" -- test -w storage/app/private/zatca && echo "QUEUE_USER can write OK"
```

<a id="server-setup-sdk-install"></a>
### 4. ZATCA SDK installation & server paths

**Recommended: use the SDK that already ships with the package.** The exact version this package is built and tested against — Java SDK 3.4.8 (`238-R3.4.8`) — is bundled at [`SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/`](SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/) (about 58 MB). After `composer require`, it's already on your server at:

```
vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/
```

You don't need to download anything from ZATCA, and the package never writes into this folder (it writes its temporary signing files to `storage/app/private/zatca` instead, covered in §3) — so there's nothing to make writable here. Point `.env` straight at it:

```dotenv
ZATCA_SDK_ROOT=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
ZATCA_SDK_EXECUTABLE=/var/www/your-app/vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_VERSION=3.4.8
```

**Trade-off:** this is the simplest option — nothing extra to install or keep in sync — but the path lives under `vendor/`, which some deploy tools replace wholesale on every release (fine, since `composer install` always restores the same bundled copy) or route through a changing release directory (e.g. Capistrano/Deployer-style `releases/<timestamp>/`), which can make `.env`'s absolute path point at a now-deleted release if you don't update it.

**Alternative: copy it to a stable, version-independent path** such as `/opt/zatca-sdk`, if your deploy layout changes the `vendor/` path on every release and you'd rather not update `.env` each time:

```bash
sudo mkdir -p /opt/zatca-sdk
sudo cp -r vendor/zatca/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/. /opt/zatca-sdk/
```

```dotenv
ZATCA_SDK_ROOT=/opt/zatca-sdk
ZATCA_SDK_EXECUTABLE=/opt/zatca-sdk/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_VERSION=3.4.8
```

**Trade-off:** one extra manual copy step per SDK version, and it's now your responsibility to re-copy when you ever change SDK version — but `.env` never needs to change on a routine deploy. **For a typical single-server setup, use the bundled path directly** (the first option) — only reach for a separate copy if your release process genuinely moves `vendor/` around. Either way: keep it outside `public/`, and never download the SDK twice just because you're unsure which copy to use — the bundled one is already sourced and SHA-256-verified in [`SDKs/README.md`](SDKs/README.md).

**Required folder structure**, either location: `Apps/` (contains the jar), `Configuration/`, `Data/`. Verify it's intact:

```bash
ls "$ZATCA_SDK_ROOT"/{Apps,Configuration,Data}   # all three must exist
```

**Permissions:** the SDK folder only needs to be **readable** by `QUEUE_USER` (and `CLI_USER` — see §2) — it never needs to be writable by anyone, since the package writes nothing into it:

```bash
sudo chown -R root:root "$ZATCA_SDK_ROOT"   # or your deploy user — ownership doesn't matter much
sudo chmod -R a+rX "$ZATCA_SDK_ROOT"         # readable, never writable
```

**Install Java** (11 through 15 — the SDK supports that range):

```bash
sudo apt-get install -y openjdk-11-jre-headless
```

**Verify Java specifically as `QUEUE_USER`** — not as whoever happens to be running the install command:

```bash
QUEUE_USER=deploy   # ← from §2
sudo -u "$QUEUE_USER" -- java -version
```

If you ever dispatch ZATCA jobs on a `sync` queue connection (not recommended beyond local testing — see §2), `WEB_USER` needs this same check. If you run onboarding or recovery commands by hand, `CLI_USER` does too.

**SDK version changes and the fingerprint check:** the package records a SHA-256 fingerprint of the jar (`ZATCA_SDK_FINGERPRINT`) and refuses to run if the file on disk doesn't match — this catches both an accidental mismatch and a tampered file. `composer install`/`composer update` never touch this SDK folder (it's not a Composer-managed path), so the fingerprint only needs to change when **you** deliberately swap in a different SDK release:

```bash
php artisan zatca:generate-credentials   # prints the current fingerprint
```

Copy that value into `ZATCA_SDK_FINGERPRINT`, then `php artisan config:cache`. See [§17](#17-renamed-classes-and-methods) and [Running the package tests](#18-running-the-package-tests) if you're adding a *new* SDK version rather than just verifying the existing one.

**Final verification** — this is the package's own smoke test, and it checks everything above in one go without contacting ZATCA or needing any certificate:

```bash
php artisan zatca:sdk-smoke-test
```

A successful run prints **nine `[OK]` lines**: Java, SDK executable, SDK root, fingerprint, CSR generation, hash, signing, QR extraction, validation. If any line says `SKIPPED` instead, `ZATCA_SDK_EXECUTABLE`/`ZATCA_SDK_ROOT` are wrong or unset — note that the command still **exits successfully** either way, so read the printed text, not just the exit code.

<a id="server-setup-supervisor"></a>
### 5. Configure the queue worker (Supervisor or systemd)

Invoice generation and signing happen inside a queued job, so **nothing is ever actually sent to ZATCA unless a queue worker is running continuously.** Supervisor is the most common choice on a traditional Linux server; systemd works just as well if you'd rather not run a second process manager — you only need one of the two, not both.

**Install Supervisor**, if it isn't already present:

```bash
sudo apt-get install -y supervisor
```

**Create `/etc/supervisor/conf.d/zatca-worker.conf`:**

```ini
[program:zatca-worker]
; Replace with this app's real path.
command=php /var/www/my-shop/artisan queue:work --queue=zatca --sleep=3 --max-time=3600
; This IS your QUEUE_USER from §2 — whatever you put here is what §3's chown must match.
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/my-shop/storage/logs/zatca-worker.log
```

Two things to check against *your own* `.env`, not just copy blindly:
- **`--queue=zatca`** must match `ZATCA_QUEUE_NAME` (the package's default is `zatca`, but you may have changed it).
- **The queue connection** comes from `ZATCA_QUEUE_CONNECTION` (falling back to your app's own `QUEUE_CONNECTION`). If that differs from your app's main queue connection, pass it explicitly as `queue:work`'s first argument, e.g. `queue:work redis --queue=zatca`.

Start with **one** worker process (`numprocs=1`). The package handles concurrency safely, but one process keeps documents in order and is enough for most shops.

**Activate it:**

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start zatca-worker:*
```

`autostart=true`/`autorestart=true` are what make it survive a server reboot and restart itself if it crashes — Supervisor itself needs to be enabled as a system service (`systemctl enable supervisor`, already the default on most distro packages) for that to apply across reboots.

**Check status and logs:**

```bash
sudo supervisorctl status zatca-worker:*
tail -f /var/www/my-shop/storage/logs/zatca-worker.log
```

If it shows `FATAL` or keeps restarting in a loop, check the log file above first — a missing Java/SDK path or a storage permission error (§3/§4) is the most common cause of a worker that starts and immediately exits.

**systemd alternative**, if you'd rather not use Supervisor — a minimal unit file (`/etc/systemd/system/zatca-worker.service`):

```ini
[Unit]
Description=ZATCA queue worker
After=network.target

[Service]
User=deploy
WorkingDirectory=/var/www/my-shop
ExecStart=/usr/bin/php /var/www/my-shop/artisan queue:work --queue=zatca --sleep=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now zatca-worker
sudo systemctl status zatca-worker
```

<a id="server-setup-cron"></a>
### 6. Configure the scheduler (cron)

**Supervisor and cron do two different jobs, and you need both:**

| | Supervisor (or systemd) | Cron |
|---|---|---|
| Keeps running | `php artisan queue:work --queue=zatca`, continuously | Nothing by itself — it fires `php artisan schedule:run` once a minute |
| What actually happens | New invoices are generated, signed and sent as your app dispatches jobs | Laravel's scheduler checks which of *its own* scheduled commands are due, and runs them |
| Missing it means | Nothing is ever signed or sent — jobs just pile up on the queue | The retry sweep, certificate checks and cleanup commands never run, even though the queue worker is fine |

Configuring only one of these is **not** a working production setup — you need the queue worker running continuously *and* the scheduler ticking every minute.

**The cron entry** (one line, added for whichever user should own it — see below):

```cron
* * * * * cd /path/to/laravel-project && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

- **PHP binary:** confirm the absolute path with `which php` (or `which php8.3`, etc. — it must be the *same* PHP version your app actually runs on, not necessarily the system default).
- **Project path:** the absolute path to this Laravel app's root (where `artisan` lives).
- **Which user:** this just runs `artisan` like any other CLI command — it does **not** need Java or SDK access itself (see why, below). Install it under whichever account normally deploys/maintains this app (often the same as `CLI_USER` from §2), using `crontab -e` as that user, or `sudo crontab -e -u deploy` to edit another user's crontab directly.

**Why the scheduler doesn't need Java:** none of the six commands this package expects you to schedule actually sign a new invoice — signing only happens inside `GenerateZatcaInvoiceJob`, which runs on the **queue worker** (§5), never from the scheduler. So the cron user only needs ordinary Laravel CLI access, not SDK/Java access.

Add these lines to your schedule (`routes/console.php` on newer Laravel, `app/Console/Kernel.php` on older):

```php
$schedule->command('zatca:report-pending')->everyFiveMinutes()->withoutOverlapping();
$schedule->command('zatca:requeue --only-transient')->hourly()->withoutOverlapping();
$schedule->command('zatca:certificate:check')->daily();
$schedule->command('zatca:status')->hourly();
$schedule->command('zatca:prune-submission-attempts')->weekly();
$schedule->command('zatca:prune-sdk-files')->daily();
```

| Command | What it does |
|---|---|
| `zatca:report-pending` | Sends B2C invoices still waiting to be reported — the normal "push what's due" sweep |
| `zatca:requeue --only-transient` | Retries documents stuck because of a ZATCA outage only — never retries a genuine rejection |
| `zatca:certificate:check` | Updates each EGS's certificate state (`valid`/`expiring_soon`/`expired`) and logs a warning as expiry approaches |
| `zatca:status` | One command your monitoring can read (`--json`, `--strict`); exits non-zero when something needs a human |
| `zatca:prune-submission-attempts` | Deletes old attempt-log rows for invoices that already succeeded (default: older than 180 days) |
| `zatca:prune-sdk-files` | Deletes leftover SDK temp files from `storage/app/private/zatca` (default: older than 7 days) — never touches the database |

Full flags and behavior for each are in [§13 Console commands](#13-console-commands).

**Verify it's actually installed and running:**

```bash
crontab -l -u deploy                        # confirm the line is there, for the right user
php artisan schedule:list                   # confirm Laravel itself sees these six commands registered
grep CRON /var/log/syslog | tail -20         # (Debian/Ubuntu) confirm cron is actually firing schedule:run
```

**Running on more than one server?** `withoutOverlapping()` (already on the two retry commands above) only prevents a command from overlapping *itself* on the same machine — it does **not** stop two servers from both running the same scheduled command at the same moment. If this app runs on multiple web/cron servers, add Laravel's own `->onOneServer()` to each line (this is a core Laravel scheduler feature, not something this package adds):

```php
$schedule->command('zatca:report-pending')->everyFiveMinutes()->onOneServer();
```

This needs a cache driver that supports atomic locks (`database`, `redis`, `memcached`, `dynamodb` — not `file` or `array`) configured as your app's default cache store.

<a id="server-setup-deploying"></a>
### 7. First deploy and every redeploy

**First time on a new server**, after §1–§6 above:

```bash
composer install --no-dev --optimize-autoloader
php artisan vendor:publish --tag=zatca-config
php artisan migrate --force
```

Set `.env`: `ZATCA_ENVIRONMENT=production` and all seller values (see [Configuration](#6-configuration)). Then onboard with a production OTP (see [Onboarding](#8-zatca-onboarding)), run `zatca:health-check` to confirm, and make **one small, real sale** — it **is** a legal invoice — confirming `zatca:inspect invoice --id=…` shows `reported`. Never send fake test invoices to production.

**Every subsequent deployment:**

```bash
php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
php artisan up
php artisan zatca:health-check
```

`queue:restart` is required — workers keep old code and config in memory until told to restart.

**Keep your data safe:**
- **Never lose `APP_KEY`.** Credentials are encrypted with it. A new `APP_KEY` makes the stored keys unreadable, and you would have to onboard again.
- **Never delete or edit** rows in `zatca_egs_credentials`, `zatca_egs_states` or `zatca_invoices`. The counter and hash chain must continue forever. Resetting them breaks your chain with ZATCA.
- **Never run `migrate:fresh` / `migrate:refresh`** on a production database.
- **Back up** the database often, especially these tables. Restoring an old backup rewinds the counter and the chain. If you ever must restore, contact ZATCA support before sending new invoices.
- **Never** put the private key, tokens or certificates in Git, logs or `.env` files. The package keeps them encrypted in the database only.
- Keep the SDK folder and `storage/app/private` out of `public/`.

**Logging and monitoring:**
- The package logs to your normal Laravel log with a `[ZATCA]` prefix. Secrets are removed.
- Hook `zatca:status --json` into your monitoring, or alert from `ZatcaInvoiceNeedsAttention` and `ZatcaCertificateExpiring` listeners.

**Updating the package later:**

```bash
composer update zatca/zatca
```

Read the changelog. If a release adds migrations, run `zatca:check-source-uniqueness`, then use the "every deployment" steps above. If you replace the ZATCA SDK jar itself, see [§4](#server-setup-sdk-install) again and update `ZATCA_SDK_FINGERPRINT`.

<a id="server-setup-checklist"></a>
### 8. Production deployment checklist

- [ ] `ZATCA_ENVIRONMENT=production`, and the seller data matches the Fatoora portal
- [ ] `ZATCA_VAT_NUMBER` = `ZATCA_ORG_IDENTIFIER`
- [ ] SDK located and readable: `ls "$ZATCA_SDK_ROOT"/{Apps,Configuration,Data}` succeeds for `QUEUE_USER` (see [§4](#server-setup-sdk-install))
- [ ] Java confirmed under `QUEUE_USER` (`sudo -u "$QUEUE_USER" java -version`) — and under `WEB_USER` too only if you ever run the queue synchronously
- [ ] `storage/app/private/zatca` is owned/group-writable by `QUEUE_USER` (and `CLI_USER` if different) — never `chmod 777` (see [§3](#server-setup-storage-permissions))
- [ ] `zatca:sdk-smoke-test` — all nine lines `[OK]`, and `ZATCA_SDK_FINGERPRINT` is set
- [ ] Onboarded in production: `zatca:health-check` shows production credentials `valid`
- [ ] The queue worker is running continuously under Supervisor or systemd (see [§5](#server-setup-supervisor))
- [ ] The scheduler cron is installed for the right user, and `schedule:list` shows all six commands (see [§6](#server-setup-cron))
- [ ] `APP_KEY` is backed up somewhere safe, and database backups are on
- [ ] Alerts are wired for `ZatcaInvoiceNeedsAttention` / `zatca:status`
- [ ] A calendar reminder to re-onboard before the certificate expires
- [ ] `ZATCA_B2B_ENABLED=false` unless B2B has passed `composer test:sandbox` and a simulation rehearsal (see [Known limitations](#known-limitations))
- [ ] One real sale reported successfully

<a id="server-setup-troubleshooting"></a>
### 9. Troubleshooting: server & infrastructure

Business-rule rejections, onboarding/CSID errors and VAT/data validation errors are in the main [Troubleshooting](#15-troubleshooting) section below. These are specifically the server/infrastructure problems that show up while getting a box ready:

| Problem | Reason | Fix |
|---|---|---|
| `java: not found` or SDK errors when signing | Java is missing, or not on the **queue worker's** `PATH` — signing runs inside `queue:work`, not the web request | Install Java 11–15 and test with `sudo -u "$QUEUE_USER" java -version` (find `$QUEUE_USER` as in [§2](#server-setup-identify-users)) |
| `ZATCA_SDK_EXECUTABLE [...] does not point to an existing SDK jar file` / `ZATCA_SDK_ROOT [...] does not point to an existing directory` | Wrong, relative, or stale path (common after a deploy tool moves `vendor/` to a new release folder) | Use the exact absolute path — see [§4](#server-setup-sdk-install) |
| `[FAIL] ... fingerprint` | The SDK jar on disk changed | If you upgraded it on purpose, run `zatca:generate-credentials` for the new fingerprint and update `ZATCA_SDK_FINGERPRINT`. If you didn't change it, **stop and investigate** — the file may be corrupted or tampered with. |
| Permission denied in `storage/app/private/zatca` | Wrong owner — this folder needs to be writable by `QUEUE_USER`, not necessarily `WEB_USER` | `chown -R "$QUEUE_USER" storage/app/private/zatca` (or the shared-group setup if more than one user needs it) — see [§3](#server-setup-storage-permissions) |
| Invoices stuck in `pending_reporting`/queue not processing | No queue worker running, or it crashed | Check `sudo supervisorctl status zatca-worker:*` (or `systemctl status zatca-worker`); see its log for the real cause — usually a Java/SDK/permission problem from the rows above |
| Supervisor shows the worker `FATAL` or stuck restarting | The underlying `artisan queue:work` command is failing immediately — almost always one of: wrong project path in `command=`, wrong `user=`, or a Java/SDK/storage problem | Read `stdout_logfile` first (configured in [§5](#server-setup-supervisor)); fix the underlying cause, then `sudo supervisorctl restart zatca-worker:*` |
| Scheduled commands never seem to run | Cron isn't installed, installed for the wrong user, or `schedule:run` itself is failing | `crontab -l -u <user>` to confirm the line exists; `php artisan schedule:list` to confirm Laravel sees the six commands; check your system's cron log (e.g. `grep CRON /var/log/syslog`) |
| Many `manual_review` after a ZATCA outage, but they never clear | `zatca:requeue --only-transient` isn't actually scheduled, or the scheduler isn't running (see the row above) | Confirm the line is in your schedule (see [§6](#server-setup-cron)) and that cron is firing |
| `.env` change has no effect | Config cache or old workers | `php artisan config:cache` and `php artisan queue:restart` |

---

## 15. Troubleshooting

Server and infrastructure problems (Java, the SDK, storage permissions, the queue worker, cron) have their own table in [§14.9](#server-setup-troubleshooting). These are the rest: business-rule rejections, onboarding, VAT/data validation, and the data chain itself.

| Problem | Reason | Fix |
|---|---|---|
| `composer require` fails on Laravel 9, 10 or 11 with "affected by security advisories" | Every release of those Laravel versions has open security advisories, and Composer blocks them by default | Upgrade the app to Laravel 12 (recommended), or have the app's owner accept the risk as described in [Laravel compatibility](#laravel-compatibility) |
| `composer require` fails on Laravel 11 or 12 with a PHP version error | Laravel 11 and 12 need PHP 8.2 or newer | Upgrade PHP to 8.2+ |
| `Call to undefined function openssl_x509_parse` (or similar) | Missing PHP extension | Install or enable `openssl` / `dom` / `mbstring` |
| `Unknown ZATCA EGS identifier` | The EGS name doesn't match `.env` | Use `config('zatca.primary_egs')` / `config('zatca.b2b.egs_identifier')` |
| `No CSR on record ... Run zatca:generate-credentials first` | Step 1 skipped | Run `zatca:generate-credentials` |
| CSR generation fails | Bad or empty CSR fields, or non-English characters in `ZATCA_ORG_NAME` | Fill every CSR field, and keep the English name in `ZATCA_ORG_NAME` |
| `Compliance CSID request failed` / invalid OTP | The OTP expired, is from the wrong portal, or was already used | Create a new OTP in the portal for the **same** environment |
| Compliance test shows `ERROR` | Seller data or address is wrong | Read the printed errors, fix `.env`, run `zatca:health-check`, retry the step |
| `No Compliance CSID on record ... Run zatca:onboard compliance first` | A `test-*` step was run before the compliance step | Run `zatca:onboard compliance --otp=...` first. No counter value was used. |
| `No compliant ... invoice on record ... Run zatca:onboard test-invoice first` | A note check was run before the invoice check | Run `zatca:onboard test-invoice` first |
| Production CSID fails | Compliance steps incomplete | Run all three `test-*` steps with PASS first |
| `ZatcaCredentialsMissingException` / job fails at once | No production certificate for this EGS and environment | Finish onboarding in the current environment |
| `ZATCA_VAT_PERCENTAGE must be a number` / `ZATCA_VAT_MODE must be "inclusive" or "exclusive"` | Invalid VAT setting in `.env` | Fix it (or remove it for the default), then `php artisan config:cache` |
| `does not reconcile — BR-S-09` | Many discounts on few items push a VAT breakdown outside ZATCA's rounding tolerance | Merge the discounts into one, or give each its own `lineIndexes` |
| `item N has quantity 0` / `negative price` | Bad item data | Quantity at least 1, price not negative. Use credit notes for refunds. |
| `has more than two decimals (BR-KSA-DEC-02)` / `must be between 0 and 100` | An item's `vatRate` is not a valid ZATCA rate | Use a rate like `15` or `5` |
| `standard-rated (S) with a 0% rate` | A 0% item without a VAT category | Use `ZatcaInvoiceItem::zeroRated()` or `exempt()` with a `VATEX-SA-...` code and reason |
| `cannot have a 15% rate. Zero-rated and exempt items are always 0%` | A zero-rated/exempt item with a `vatRate` | Remove `vatRate` from that item |
| `needs a ZATCA exemption reason code` / `reason text` | A `Z`/`E` item without its reason | Pass the code and reason to `zeroRated()` / `exempt()` |
| `only SAR is supported` | `currency` is not `SAR` | Invoice in SAR |
| `has no items` / `has no lines` / `negative` | Empty or negative invoice data | Don't invoice orders without items. Use credit notes for refunds. |
| Invoice in `manual_review` with BR-KSA codes | ZATCA rejected the content (for example line math, VAT number) | Fix the mapper or data, then [regenerate](#re-issuing-a-rejected-invoice-regeneration) |
| `buyer VAT number must be 15 digits...` | Invalid buyer VAT | Ask the buyer for the correct VAT, or use `BuyerOtherId` (CR number) |
| Warning `BR-KSA-F-13` ("Other Seller/Buyer ID ... invalid value") | A seller or buyer ID (for example a CR number) contains 8 equal digits (`00000000`) or 8 sequential digits (`12345678`). ZATCA treats it as a fake ID. | Use the real number. The invoice is accepted with a warning, but fix the data. |
| `Standard document ... has no buyer / no supply date` | B2B data missing | Set `buyer` and `supplyDate` in the mapper |
| Credit note: `not eligible` | Original not accepted yet, another note is in progress, or the original is fully credited | Check `CreditNoteEligibilityPolicy::verdict()->reason` |
| Missing original invoice reference | Not possible from this API | Notes always take the reference from `$original`, so pass the right `ZatcaInvoice` |
| Wrong previous hash / counter problems | The database was restored or edited, or two environments are mixed | Never edit `zatca_egs_states`. Check `zatca:inspect state`. Contact ZATCA if the chain broke. |
| Signing works in sandbox, fails in production | Production not onboarded, a different OTP or portal, or wrong environment value. Also, the sandbox does not check the production signature (its production certificate is shared), so a signing problem can stay hidden there. | Onboard again in production. Credentials are per environment. Rehearse in simulation first. |
| `composer test:sandbox` skips every test | `ZATCA_SDK_ROOT` / `ZATCA_SDK_EXECUTABLE` not set or wrong | Set both (see [Sandbox tests](#sandbox-tests)) |
| `composer test:sandbox` fails at `generate-credentials` (the first SDK step) | Java is missing from the PATH, or the version is outside what the SDK supports (11 up to, not including, 15) | Install Java 11 and put it first on the PATH |

---

<a id="known-limitations"></a>
## 16. Known limitations

These come from the current code. They are not documentation mistakes.

**What the developer-portal sandbox proved, and what it cannot**

`composer test:sandbox` (see [Sandbox tests](#sandbox-tests)) runs onboarding and every B2C and B2B document against ZATCA's real developer-portal sandbox. All of them are accepted with no warnings. That confirms:
- The API contract: endpoints, `Accept-Version: V2`, `Clearance-Status`, request bodies (including `uuid`), and Basic auth.
- The XML passes ZATCA's checks: XSD, EN 16931 and KSA rules, for all six documents.
- The B2B EGS onboards with `ZATCA_B2B_INVOICE_TYPE=1000`, and its compliance documents come back `CLEARED`.
- ZATCA's cleared copy keeps the same UUID and adds a QR code.

The sandbox is lenient, so these still need ZATCA's **simulation** environment, with your real company data and OTP (project gates G-02 to G-07):
1. **The production signature.** The sandbox's production certificate is shared and does not match your key, yet the sandbox accepts the invoice anyway. So the sandbox does not check it.
2. **Refusals.** The sandbox accepts any OTP and issues a production certificate even before the compliance checks. These refusals are covered by local tests instead (`OnboardCommandFailuresTest`).
3. Whether ZATCA really accepts `1000` (or wants `1100`) for a separate B2B device.
4. Which hash continues the chain after a cleared invoice, and rejected documents in the chain. The sandbox accepted the package's chain, but may not check it strictly.
5. Real `NOT_CLEARED` and warning responses for Clearance.
6. Which XML and QR to give the B2B buyer (the package stores ZATCA's `cleared_xml_base64`).
7. What ZATCA returns when a timed-out (`clearance_in_doubt`) document is sent again.

Run `composer test:sandbox`, then onboard both EGSs in simulation and clear a few invoices, credit notes and debit notes there, before you set `ZATCA_B2B_ENABLED=true` in production.

The package does not check that the three compliance checks passed before it asks for the production certificate. It relies on ZATCA to refuse. Real ZATCA does refuse, but the sandbox does not.

**Tax and amounts**
- `Z` and `E` are supported. Out-of-scope (`O`) is not, because ZATCA limits how it can be mixed with other categories on one document.
- Every amount is in **SAR**. Other currencies are refused.
- `quantity` is a whole number. Fractions (1.5 kg) are not supported.
- No line-level discounts. Only document-level discounts (`ZatcaInvoiceDiscount`).
- One price mode per invoice (`priceMode`), not per item.
- The net unit price in the XML (`PriceAmount`) can have up to 6 decimals, so that quantity × price matches the line net. ZATCA's SDK 3.4.8 rules accept this (tested with the SDK's own validator), and so does the developer-portal sandbox (for example 2 × 19.99 VAT-inclusive, a net unit price of 17.385). Simulation and production have not been tried with it yet.
- Payment means is always code `10` (cash).

**Notes**
- `CreditNoteIssuer::issue()` credits whole lines. Use price adjustments to credit part of a line.
- One credit or debit note in progress per invoice at a time.

**Other**
- Laravel 12 is recommended. Laravel 9, 10 and 11 work but no longer get security fixes from Laravel, and need an explicit Composer override to install. See [Laravel compatibility](#laravel-compatibility).
- Real ZATCA SDK signing is not covered by the default automated suite (the 7 real-SDK tests skip unless an SDK is configured, and the sandbox tests are opt-in). It was checked with SDK 3.4.8 on Laravel 10 only (PHP 8.3 for the real-SDK tests, PHP 8.2 for the sandbox tests), not on every Laravel version.
- Certificate renewal through the API is turned off. Renew by onboarding again.
- No PDF generation.

---

## 17. Renamed classes and methods

Some names used to be specific to one kind of business. They now have generic names. The old **PHP** names still work as deprecated aliases.

| Old name (deprecated) | New name |
|---|---|
| `ZatcaCreditNoteReason::CourseCancellation` | `ZatcaCreditNoteReason::ItemCancellation` |
| `ZatcaCreditNoteReason::revokesCourseAccess()` | `ZatcaCreditNoteReason::cancelsItems()` |
| `ZatcaDebitNoteReason::AdditionalCourse` | `ZatcaDebitNoteReason::AdditionalItem` |
| `ZatcaDebitNoteReason::grantsCourseAccess()` | `ZatcaDebitNoteReason::addsItems()` |
| `DebitNoteLineInput` `courseId:` argument and `->courseId` | `itemId:` argument and `->itemId` |
| `CreditSource` `courseId` | `itemId` |
| `$line->course_id` on note lines (read-only) | `$line->item_id` |

The database uses the new names too:

| Old | New |
|---|---|
| `reason_type` value `course_cancellation` | `item_cancellation` |
| `reason_type` value `additional_course` | `additional_item` |
| `course_id` column on `zatca_credit_note_lines` / `zatca_debit_note_lines` | `item_id` |
| `course_ids` in the debit note "requested" event detail | `item_ids` |

These were changed inside the original migrations, because the package had not run in production. **Any database that already ran the old migrations (local, staging) must be rebuilt** with `php artisan migrate:fresh`. Never do that on production.

### VAT calculation moved into the package (breaking, before the first release)

The package now calculates VAT from prices (see [VAT settings](#vat-settings)). There are no aliases for these: the old signatures took amounts the package now calculates.

| Before | Now |
|---|---|
| `InvoiceSourceContract::toInvoiceData(): ZatcaInvoiceData` | `toInvoiceInput(): ZatcaInvoiceInput` |
| `ZatcaInvoiceLine` / `ZatcaAllowanceCharge` built by your app, with net, VAT and totals | `ZatcaInvoiceItem` / `ZatcaInvoiceDiscount` with prices. The package builds the calculated DTOs. |
| `ZatcaInvoiceLine::$unitPrice` | `$netUnitPrice` (plus `$suppliedPrice` and `$priceMode`) |
| `new DebitNoteLineInput($desc, $qty, $unitPrice, $vatRate, $net, $vat, ...)` | `new DebitNoteLineInput($desc, $qty, $price, vatRate: ..., originalLineIndex: ...)`, and `DebitNoteLineInput::vatAdjustment()` for VAT-only lines. `fromArray()` reads `price` (and `vat_only`). |
| `issuePriceAdjustment(..., $netAmount, $vatAmount, ...)` and `new PriceAdjustmentInput($key, $net, $vat, $desc)` | one `$amount`: `issuePriceAdjustment(..., $amount, ...)`, `new PriceAdjustmentInput($key, $amount, $desc)` |
| `config('zatca.egs.*.vat_percentage')`, `EgsConfig::$vatPercentage` | `config('zatca.vat.percentage')` and `config('zatca.vat.mode')` |

The `unit_price` column of `zatca_credit_note_lines` and `zatca_debit_note_lines` now has 6 decimals (`decimal(18,6)`), also changed in the original migrations: rebuild local and staging databases as above.

---

## 18. Running the package tests

```bash
composer install
```

```bash
composer test
```

The suite uses Orchestra Testbench and SQLite and never calls ZATCA or the real SDK. It has 847 tests, 8 of them skipped (7 real-SDK tests plus a Unix-only test). The 13 [sandbox tests](#sandbox-tests) are separate. On PHP 8.2+, `composer install` picks Laravel 12. To run the suite against an older Laravel version, install into a separate copy of the package with `composer update --with "orchestra/testbench:^9.0" -W` (Laravel 11; use `^8.0` for 10 and `^7.0` for 9). Laravel 9–11 need the security override described in [Laravel compatibility](#laravel-compatibility), so keep that copy throwaway and never ship its settings.

To also test the real SDK on your machine, set `ZATCA_SDK_EXECUTABLE` and `ZATCA_SDK_ROOT` and run `composer smoke` and `composer test`. To use the SDK bundled in this repository:

```bash
export ZATCA_SDK_ROOT="$PWD/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8"
```

```bash
export ZATCA_SDK_EXECUTABLE="$ZATCA_SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar"
```

```bash
composer smoke
```

```bash
composer test
```

<a id="sandbox-tests"></a>
### Sandbox tests (`composer test:sandbox`)

These tests send real requests to **ZATCA's developer-portal sandbox** (`https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal`). They use the package exactly as an app does:
- the `zatca:*` artisan commands for onboarding
- an Eloquent order with its own `InvoiceSourceContract`
- `GenerateZatcaInvoiceJob`, `CreditNoteIssuer` and `DebitNoteIssuer` on a sync queue

Nothing is mocked. They are **opt-in** and are not part of `composer test`.

**What you need**
- Java 11 (the SDK supports 11 up to, not including, 15) on the PATH: `java -version`.
- The ZATCA SDK. The copy bundled in [`SDKs/`](SDKs/) works.
- Internet access to `gw-fatoora.zatca.gov.sa`.
- Nothing from your company. The tests use ZATCA's test VAT number `399999999900003` and test OTP `123345`, and create fresh EGSs every time.

**Run them**

```bash
export ZATCA_SDK_ROOT="$PWD/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8"
```

```bash
export ZATCA_SDK_EXECUTABLE="$ZATCA_SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar"
```

```bash
composer test:sandbox
```

`composer test:sandbox` sets `ZATCA_RUN_SANDBOX_TESTS=1` for you. Without it, or without the SDK paths, every sandbox test is skipped. A full run takes about 3 to 4 minutes, because each test onboards new EGSs.

**What they check** (13 tests, in [`tests/Sandbox/`](tests/Sandbox/))

| | B2C (Reporting) | B2B (Clearance) |
|---|---|---|
| Onboarding, step by step | `B2c/OnboardingTest` | `B2b/OnboardingTest` |
| Repeating a compliance step | `B2c/OnboardingTest` | `B2b/OnboardingTest` |
| Both EGSs side by side | | `B2b/OnboardingTest` |
| Invoices (ICV and hash chain) | `B2c/InvoiceReportingTest` | `B2b/InvoiceClearanceTest` |
| Full and partial credit notes | `B2c/CreditNoteTest` | `B2b/CreditNoteTest` |
| Debit note | `B2c/DebitNoteTest` | `B2b/DebitNoteTest` |

- **Onboarding** is checked after each of the six steps:
  - the CSR contents
  - the key, encrypted at rest
  - the certificate issued for that key
  - the OTP and auth headers
  - each compliance document's ICV, hash chain and type code
  - the production certificate reusing the same key
- **Every document** must come back `REPORTED` or `CLEARED` with **no warnings**. A warning fails the test, because it marks data the package got wrong. The `BR-KSA-F-13` bug was found this way.
- They also check:
  - the endpoint and `Clearance-Status` header (B2C never goes to Clearance, and B2B never goes to Reporting)
  - the totals
  - the `BillingReference` on notes
  - ZATCA's cleared copy of B2B documents

**Safety.** The HTTP client in these tests refuses any address other than the developer-portal sandbox, so a test can never reach simulation or production. Tokens, secrets, CSRs and invoices are never printed.

**Not covered by the sandbox.** The sandbox accepts any OTP. It also issues a production certificate even before the compliance checks, and that certificate is a shared one that does not match your key. So these tests do not prove the production signature, and they cannot test refusals. The refusals and wrong-order cases are covered by `tests/Feature/OnboardCommandFailuresTest.php` in the normal suite instead. Proving the signature needs a rehearsal in ZATCA's **simulation** environment (see [Known limitations](#known-limitations)).

More operational detail is in [`docs/OPERATIONS.md`](docs/OPERATIONS.md), [`docs/SERVER_REQUIREMENTS.md`](docs/SERVER_REQUIREMENTS.md), [`docs/DATABASE.md`](docs/DATABASE.md) and [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md).

---

## License

Proprietary. Internal use only.
