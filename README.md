# 🧾 Asas ZATCA Integration Package

[![PHP Version](https://img.shields.io/badge/php-%2B8.1-777BB4?style=flat-square&logo=php)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-9.x%20%7C%2010.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![ZATCA API](https://img.shields.io/badge/ZATCA%20API-V2%20(FATOORA)-008080?style=flat-square)](https://zatca.gov.sa)
[![SDK Version](https://img.shields.io/badge/ZATCA%20SDK-3.4.8-blue?style=flat-square&logo=java)](https://zatca.gov.sa)
[![License](https://img.shields.io/badge/license-Proprietary-red?style=flat-square)](#license)

A high-performance Laravel package to seamlessly connect your application to **ZATCA** (Saudi Arabia's Zakat, Tax and Customs Authority — **FATOORA** Phase 2). Handles electronic invoice generation, XML signing via ZATCA Java SDK, QR code embedding, and automated reporting through background Laravel Queue Jobs.

---

> [!IMPORTANT]
> **Version 1.0.0 — B2C Simplified Invoices Only**  
> Supports **Simplified Tax Invoices** for individual customers (B2C) via the **Reporting model**. See [Roadmap](#roadmap) for upcoming features (B2B, Credit/Debit Notes).

> [!WARNING]
> **Production Hardening Notice**
> - **Job Dispatching:** `GenerateZatcaInvoiceJob` expects scalar identifiers `(string $sourceType, string $sourceId, string $egsIdentifier = 'default')` or `GenerateZatcaInvoiceJob::forModel($order)`.
> - **Automatic Renewal:** Disabled by default (`ZATCA_AUTO_RENEWAL_ENABLED=false`). Do not enable until official ZATCA renewal contracts are verified.

---

## 📌 Table of Contents

- [Package Overview](#-package-overview)
- [Supported Versions](#-supported-versions)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Architecture Overview](#-architecture-overview)
- [Quick Start](#-quick-start)
- [Console Commands](#-console-commands)
- [Public API & Developer Guide](#-public-api--developer-guide)
- [Invoice Generation Workflow](#-invoice-generation-workflow)
- [Onboarding Guide](#-onboarding-guide)
- [Production Reporting & Retries](#-production-reporting--retries)
- [Certificate Lifecycle](#-certificate-lifecycle)
- [Error Handling & Security](#-error-handling--security)
- [Health Check & Diagnostics](#-health-check--diagnostics)
- [Testing & Quality Assurance](#-testing--quality-assurance)
- [Troubleshooting & FAQ](#-troubleshooting--faq)
- [Roadmap & License](#-roadmap--license)

---

## ⚡ Package Overview

### Key Capabilities
- **Automated End-to-End Pipeline:** Generates compliant XML, executes SDK signing, embeds QR codes, and submits reports to ZATCA.
- **Queue Worker Isolation:** Signing and network operations run completely out-of-band in background workers (`zatca` queue).
- **Idempotency & Resilience:** Safe retry policies ensure invoices are signed exactly once and never duplicated.
- **Multi-EGS Ready:** Scalable design allowing multiple business devices/branches without code modification.
- **Enterprise Security:** Tokens, private keys, and secrets are encrypted at rest with automatic log sanitization.

### Supported Features Matrix

| Feature / Model | Status |
| :--- | :---: |
| Onboarding (Compliance CSID, Invoice Check, Production CSID) | ✅ Supported |
| Simplified Tax Invoice (B2C) | ✅ Supported |
| Reporting Model (`POST /invoices/reporting/single`) | ✅ Supported |
| Standard Tax Invoice (B2B) | 🔄 Planned (v2) |
| Credit & Debit Notes | 🔄 Planned (v2) |
| Clearance Model | 🔄 Planned (v2) |

---

## ⚙️ Supported Versions

| Component | Target Version | Notes |
| :--- | :--- | :--- |
| **PHP** | `>= 8.1` | Required |
| **Laravel** | `9.x` / `10.x` | Tested with Orchestra Testbench |
| **ZATCA API** | `V2` | Transport layer |
| **ZATCA SDK (Java)** | `3.4.8` | CLI jar tool |
| **UBL Schema** | `2.1` | OASIS Standard |

---

## 🚀 Installation

### 1. Require via Composer
Add the package to your `composer.json` or install via local repository:
```bash
composer require asas/zatca
```

### 2. Discover Package & Publish Configuration
```bash
php artisan package:discover
php artisan vendor:publish --tag=zatca-config
```

### 3. Run Database Migrations
Creates 5 core tables (`zatca_egs_credentials`, `zatca_egs_states`, `zatca_compliance_documents`, `zatca_invoices`, `zatca_submission_attempts`):
```bash
php artisan migrate
```

### 4. System Prerequisites (Server-Side)
- **Java Runtime:** JRE/JDK 11 to 15 (`java -version`).
- **ZATCA SDK Directory:** Unzip the official SDK on the server (e.g. `/opt/zatca-sdk`).
- **Storage Permissions:** Ensure write access to `storage/app/zatca/` for worker processes.

### 5. Environment Configuration
Add the following to your `.env` file:

```env
# Version Pins
ZATCA_API_VERSION=V2
ZATCA_UBL_VERSION=2.1
ZATCA_XML_PROFILE_VERSION=V2

# SDK Settings
ZATCA_SDK_EXECUTABLE=/opt/zatca-sdk/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_ROOT=/opt/zatca-sdk
ZATCA_SDK_VERSION=3.4.8
ZATCA_SDK_FINGERPRINT=
ZATCA_SDK_PROCESS_TIMEOUT=60

# Environment (sandbox | simulation | production)
ZATCA_ENVIRONMENT=sandbox

# EGS & Business Profile
ZATCA_EGS_IDENTIFIER=default
ZATCA_VAT_NUMBER=399999999900003
ZATCA_ORG_NAME="Your Legal Org Name"
ZATCA_ORG_UNIT_NAME="Your Unit Name"
ZATCA_CSR_COMMON_NAME=TST-YOURORG-EGS-01
ZATCA_CSR_SERIAL_NUMBER="1-YOURORG|2-EGS01|3-uuid"
ZATCA_ORG_IDENTIFIER=399999999900003
ZATCA_COUNTRY_CODE=SA
ZATCA_INVOICE_TYPE=0100
ZATCA_LOCATION_ADDRESS="Riyadh, Saudi Arabia"
ZATCA_BUSINESS_CATEGORY="Education"
ZATCA_CRN=1010010000

# Retries & Queue
ZATCA_GENERATION_RETRY_TRIES=5
ZATCA_REPORTING_RETRY_ATTEMPTS=4
ZATCA_AUTO_RENEWAL_ENABLED=false
ZATCA_QUEUE_CONNECTION=database
ZATCA_QUEUE_NAME=zatca
ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS=180
```

> [!CAUTION]
> Never store raw ZATCA tokens, OTPs, or private keys directly in `.env`. Credentials generated during onboarding are encrypted automatically in the database using your application's `APP_KEY`.

---

## 🛠️ Configuration Reference

All settings reside in `config/zatca.php`.

### Key Settings Overview

| Area | Option | Default | Description |
| :--- | :--- | :--- | :--- |
| **API** | `api_version` | `V2` | Target ZATCA API version |
| **SDK** | `sdk.executable` | `null` | Absolute path to SDK `.jar` file |
| | `sdk.process_timeout` | `60` | Execution timeout in seconds |
| **HTTP** | `http.timeout` | `30` | Network request timeout in seconds |
| | `http.accept_language`| `ar` | Error response language (`ar` / `en`) |
| **Queue** | `queue.queue` | `zatca` | Dedicated background queue name |
| **Prune** | `submission_attempts.prune_after_days` | `180` | History retention period in days |

---

## 🏗️ Architecture Overview

The package follows strict domain separation:

```text
src/
├── Console/Commands/      # Artisan commands (Onboarding, Health, Inspection)
├── Contracts/             # Core interfaces for extensibility
├── DTOs/                  # Strongly-typed Data Transfer Objects
├── Enums/                 # Domain Enums (Environment, Statuses, States)
├── Events/                # Package events (ZatcaInvoiceGenerated)
├── Exceptions/            # Custom domain exceptions
├── Jobs/                  # Queueable background jobs
├── Models/                # Eloquent models for database tables
├── Services/
│   ├── Certificate/       # Certificate state machine & lifecycle
│   ├── Connectors/        # API transport connectors (V2 HTTP)
│   ├── Diagnostics/       # Read-only health inspector
│   ├── Execute/           # Invoice signing & storage orchestrator
│   ├── Invoice/           # UBL 2.1 XML generator factories
│   ├── OnBoarding/        # 3-step onboarding orchestrator
│   ├── SDK/               # Java SDK process wrapper & adapter
│   └── Security/          # Encryption, CSR, & Sanitization
└── State/                 # ICV counter & PIH state managers
```

### Core Design Principles
1. **Unified EGS Management (`EgsRegistry`):** EGS configurations are retrieved dynamically, ensuring multi-branch isolation.
2. **Strict Environment Separation:** Compliance and Production environments use separate database records, keys, and sequence counters.
3. **Isolated SDK Workspaces:** Each signing operation executes in an isolated temporary directory with `0600` file permissions.

---

## 💻 Quick Start

### 1. Implement `InvoiceSourceContract`
Map your domain model (e.g., `Order`) to `ZatcaInvoiceData`:

```php
namespace App\Zatca;

use Asas\Zatca\Contracts\InvoiceSourceContract;
use Asas\Zatca\DTOs\ZatcaInvoiceData;
use Asas\Zatca\DTOs\ZatcaInvoiceLine;

class OrderInvoiceSource implements InvoiceSourceContract
{
    public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
    {
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

### 2. Register Service Binding
In `app/Providers/AppServiceProvider.php`:
```php
use Asas\Zatca\Contracts\InvoiceSourceContract;
use App\Zatca\OrderInvoiceSource;

public function register(): void
{
    $this->app->bind(InvoiceSourceContract::class, OrderInvoiceSource::class);
}
```

### 3. Dispatch Invoice Job
Trigger invoice processing asynchronously after payment confirmation:

```php
use Asas\Zatca\Jobs\GenerateZatcaInvoiceJob;

// Recommended convenience helper:
dispatch(GenerateZatcaInvoiceJob::forModel($order, egsIdentifier: 'default'));
```

---

## 🕹️ Console Commands

Run `php artisan list zatca` to view available commands.

| Command | Description | ZATCA API |
| :--- | :--- | :---: |
| `zatca:generate-credentials` | Generates local RSA keypair and CSR | None |
| `zatca:onboard compliance` | Requests Compliance CSID via OTP | `POST /compliance` |
| `zatca:onboard test-invoice` | Runs automated compliance test invoice check | `POST /compliance/invoices` |
| `zatca:onboard production` | Requests final Production CSID | `POST /production/csids` |
| `zatca:health-check` | Validates SDK executable, fingerprint, and credentials | None |
| `zatca:inspect {resource}` | Inspects credentials, state, or specific invoice | None |
| `zatca:report-pending` | Re-dispatches pending/failed reporting jobs | `POST /invoices/reporting/single` |
| `zatca:certificate:check` | Checks certificate expiration state | None |
| `zatca:certificate:renew` | Renews expiring production certificates | `POST /production/csids` |
| `zatca:prune-submission-attempts` | Cleans up historical submission attempt logs | None |

---

## 📡 Public API & Developer Guide

### Key Classes & Interfaces

- **`GenerateZatcaInvoiceJob`:** Main entry job. Takes `$sourceType`, `$sourceId`, and `$egsIdentifier`.
- **`ProductionInvoiceOrchestrator`:** Handles invoice generation, signing, XML formatting, and database storage.
- **`ZatcaCredentialStore`:** Secure store for encrypted CSID credentials and keys.
- **`ZatcaInspector`:** Utility service for fetching diagnostic reports and invoice details.

### Listening to Generated Invoices
Use the `ZatcaInvoiceGenerated` event to attach custom actions (e.g., rendering customer PDF receipts):

```php
use Asas\Zatca\Events\ZatcaInvoiceGenerated;
use Illuminate\Support\Facades\Event;

Event::listen(ZatcaInvoiceGenerated::class, function (ZatcaInvoiceGenerated $event) {
    // $event->invoice contains the signed ZatcaInvoice model
    $qrCode = $event->invoice->qr_code;
    $xmlContent = $event->invoice->signed_xml;
});
```

---

## 🔐 Onboarding Guide

Execute onboarding once per EGS device:

```bash
# Step 1: Generate Keypair & CSR
php artisan zatca:generate-credentials --egs=default

# Step 2: Request Compliance CSID (Get OTP from Fatoora Portal)
php artisan zatca:onboard compliance --otp=123456

# Step 3: Run Automated Test Invoice Check
php artisan zatca:onboard test-invoice

# Step 4: Obtain Production CSID
php artisan zatca:onboard production

# Step 5: Verify Health Status
php artisan zatca:health-check
```

---

## 🔄 Production Reporting & Retries

### State Lifecycle
```text
[Generated] ──> [PendingReporting] ──┬──> [Reported] (PASS)
                                    ├──> [ReportedWithWarnings] (WARNING)
                                    ├──> [Retrying] ──> [Reported] (On Success)
                                    └──> [ManualReview] (Unrecoverable Error / Max Tries)
```

- **Transient Network Errors (5xx / Timeouts):** Retried automatically via scheduled `zatca:report-pending` command.
- **Validation Errors (4xx):** Moved to `manual_review` state for human inspection to prevent retry loops.
- **Idempotency:** HTTP 409 "already reported" responses are safely handled without duplicating state.

---

## 🛡️ Error Handling & Security

- **Log Sanitization:** `ZatcaSanitizer` automatically redacts PEM keys, certificates, base64 XMLs, and Authorization headers from exception trace logs.
- **Fingerprint Verification:** `zatca:health-check` computes the SHA-256 hash of the Java SDK jar file to detect unauthorized modifications.

---

## 🧪 Testing & Quality Assurance

Run package tests with isolated database and Orchestra Testbench:

```bash
cd packages/asas/zatca
composer install
composer test
```

### SDK Local Smoke Test
Validate Java binary integration without touching the network:
```bash
php artisan zatca:sdk-smoke-test
```

---

## ❓ Troubleshooting & FAQ

<details>
<summary><b>Q: Does this package generate PDF invoices?</b></summary>
No. It generates signed XML documents, computes QR codes, and reports to ZATCA. Listen to <code>ZatcaInvoiceGenerated</code> to trigger your application's PDF generator.
</details>

<details>
<summary><b>Q: What happens if ZATCA APIs are down during payment?</b></summary>
Customer checkout is never interrupted. Invoices are queued in <code>pending_reporting</code> status and submitted automatically when ZATCA services recover.
</details>

<details>
<summary><b>Q: How do I fix "OTP Invalid" during onboarding?</b></summary>
OTPs issued by the ZATCA portal expire rapidly. Request a fresh OTP from the portal and immediately re-run <code>php artisan zatca:onboard compliance --otp=NEW_OTP</code>.
</details>

---

## 🗺️ Roadmap & License

- [x] Simplified Tax Invoices (B2C)
- [x] Automated Onboarding Pipeline
- [ ] Standard Tax Invoices (B2B)
- [ ] Credit & Debit Notes Support
- [ ] Clearance Model Integration

**License:** Proprietary — Internal Use Only.