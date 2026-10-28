# ZATCA for Laravel

Send Saudi Fatoora Phase 2 e-invoices from Laravel. You provide your sale's items, quantities and prices. The package calculates VAT, builds UBL XML, signs it with the ZATCA SDK, adds a QR code, submits it from a queue, and stores the result.

**Status:** This package is currently running in production.

**SDK used and tested:** ZATCA Java SDK **3.4.8** (`238-R3.4.8`), bundled in [SDKs/](SDKs/).

**Tested versions:** The package test suite was run with the real ZATCA SDK 3.4.8 on these Laravel/PHP combinations (recorded on 2026-10-20):

| Laravel version | PHP versions tested |
|---|---|
| 9.52.22 | 8.1, 8.2 |
| 10.50.3 | 8.1, 8.3 |
| 11.57.0 | 8.2, 8.4 |
| 12.70.0 | 8.2, 8.4 |

All listed runs passed with no failures; platform-specific skips are documented in the [compatibility test record](docs/COMPATIBILITY.md#3-laravel-and-php). Live sandbox tests were separate from these runs.

## Start here

1. [Understand the two flows](#understand-the-two-flows)
2. [Install and configure](#install-and-configure) (or [try the built-in dashboard](#try-it-built-in-dashboard))
3. [Connect your Laravel app](#connect-your-laravel-app)
4. [Onboard with ZATCA](#onboard-with-zatca)
5. [Create and read invoices](#create-and-read-invoices)
6. [Create credit and debit notes](#create-credit-and-debit-notes)
7. [Check statuses and recover failures](#check-statuses-and-recover-failures)
8. [Deploy and operate safely](#deploy-and-operate-safely)
9. [Know the limits](#know-the-limits)
10. [Tests and further reading](#tests-and-further-reading)

## Understand the two flows

| Comparison | B2C — sales to individuals | B2B — sales to companies |
|---|---|---|
| Buyer | Individual | Company |
| Document | Simplified tax invoice | Standard tax invoice |
| Submission | **Reporting:** issue it, then report within 24 hours | **Clearance:** ZATCA must clear it before you give it to the buyer |
| Buyer data | Name optional | Legal name, VAT number **or** another ID, full address, supply date |
| Device in this package | Primary EGS, usually `default` | Separate EGS, usually `b2b` |

The document type chooses the endpoint. Your app does not choose Reporting or Clearance separately.

A **credit note** reduces an accepted invoice (return, refund or overcharge). A **debit note** increases it (missing charge, price or VAT correction). Never edit an accepted invoice. Both kinds of note reference the original and follow its submission flow.

| Document | `Zatca\Enums\ZatcaComplianceDocumentType` | Code / subtype | Route |
|---|---|---|---|
| B2C invoice | `Invoice` | 388 / 02 | Reporting |
| B2C credit note | `CreditNote` | 381 / 02 | Reporting |
| B2C debit note | `DebitNote` | 383 / 02 | Reporting |
| B2B invoice | `StandardInvoice` | 388 / 01 | Clearance |
| B2B credit note | `StandardCreditNote` | 381 / 01 | Clearance |
| B2B debit note | `StandardDebitNote` | 383 / 01 | Clearance |

An **EGS** is your registered invoice-generating device. Each EGS and environment has its own encrypted credentials, invoice counter (**ICV**) and previous-invoice hash (**PIH**). These link documents into a chain. The package creates UUIDs, counters, hashes, signatures and note numbers; never supply or reset them yourself.

## Install and configure

### Requirements

| Requirement | What to use |
|---|---|
| Laravel / PHP | Laravel 12 with PHP 8.2+ is the repository's recommended combination. Laravel 9–10 require PHP 8.1+; Laravel 11 requires PHP 8.2+. |
| PHP extensions | `openssl`, `dom`, `mbstring`, `ctype`, `json`, and your database's PDO driver |
| Java | Use Java 11, available to the queue worker and onboarding/recovery CLI user |
| ZATCA SDK | Bundled Java SDK **3.4.8** (`238-R3.4.8`) |
| Infrastructure | Laravel database, persistent queue worker, scheduler and outbound HTTPS to `gw-fatoora.zatca.gov.sa:443` |
| Business data | VAT/CR numbers, legal names, branch, national address, and access to the matching Fatoora portal for an OTP |

Laravel 9–11 remain technically compatible, but the repository records expired security support and open advisories. Composer may block installation. Read [legacy compatibility and security overrides](docs/SERVER_REQUIREMENTS.md#laravel-compatibility), run `composer audit` in your app, and plan an upgrade. The package never disables Composer's security checks.

### Install

Run these commands in **your Laravel application**. Choose **one** of the two ways to get the package, then run the common steps below.

**Option A: from Packagist (recommended).** No extra setup:

```bash
composer require omarabdelwahhab30/zatca -W
```

**Option B: straight from GitHub** (your own fork or a private copy, or before it is on Packagist). Add the repository to the app's `composer.json`, replacing the URL, then require it:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/OmarAbdelwahhab30/Zatca-Full-Package" }
]
```

```bash
composer require omarabdelwahhab30/zatca:dev-main -W
```

Do not do both: the `repositories` entry is only for Option B. With Option A, leave it out.

`-W` (`--with-all-dependencies`) is needed on a fresh **Laravel 12** app: without it Composer can refuse with a `doctrine/dbal` / `carbonphp/carbon-doctrine-types` conflict because those packages are still locked by the new project.

Then, for either option:

```bash
php artisan vendor:publish --tag=zatca-config
php artisan migrate
```

Laravel discovers `Zatca\ZatcaServiceProvider` automatically. Migrations load automatically; do not publish them. On an **existing installation**, run `php artisan zatca:check-source-uniqueness` before upgrade migrations and resolve any duplicates first.

Install Java 11 for your OS (`java -version` should work). On a Linux system with the package available:

```bash
sudo apt-get install -y openjdk-11-jre-headless
```

The SDK already ships under `vendor/omarabdelwahhab30/zatca/SDKs/`. It needs its complete `Apps/`, `Configuration/` and `Data/` folders. No SDK installer is needed. Use absolute paths and keep it outside `public/`.

### Configure `.env`

Replace the example business values with your own. `399999999900003` is ZATCA's **sandbox** VAT number. Use real company data in simulation and production.

```dotenv
ZATCA_ENABLED=true
ZATCA_ENVIRONMENT=sandbox

ZATCA_SDK_ROOT=/var/www/your-app/vendor/omarabdelwahhab30/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
ZATCA_SDK_EXECUTABLE=/var/www/your-app/vendor/omarabdelwahhab30/zatca/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
ZATCA_SDK_VERSION=3.4.8

ZATCA_EGS_IDENTIFIER=default
ZATCA_INVOICE_TYPE=0100
ZATCA_VAT_NUMBER=399999999900003
ZATCA_ORG_IDENTIFIER=399999999900003
ZATCA_ORG_NAME="Example Trading Co."
ZATCA_COMPANY_NAME_AR="شركة المثال للتجارة"
ZATCA_ORG_UNIT_NAME="Riyadh Branch"
ZATCA_CRN=1010010000
ZATCA_BUSINESS_CATEGORY=Retail
ZATCA_COUNTRY_CODE=SA
ZATCA_CSR_COMMON_NAME="Example-POS-1"
ZATCA_CSR_SERIAL_NUMBER="1-Example|2-POS|3-6f4d20e0-6bfe-4a80-9389-7dabe6620f12"

ZATCA_STREET="King Fahd Road"
ZATCA_DISTRICT="Al Olaya"
ZATCA_BUILDING_NUMBER=1234
ZATCA_ADDITIONAL_NUMBER=5678
ZATCA_CITY=Riyadh
ZATCA_POSTAL_CODE=12211

ZATCA_VAT_PERCENTAGE=15
ZATCA_VAT_MODE=inclusive
QUEUE_CONNECTION=database
ZATCA_QUEUE_CONNECTION=database
ZATCA_QUEUE_NAME=zatca
```

### Where do these values come from?

Ask the business owner or accountant for the **VAT registration certificate, commercial registration, and the registered National Address for the invoicing branch**. The developer chooses the app/device identifiers and queue settings; these are not numbers issued by ZATCA.

| Setting | Where to get it / what to enter |
|---|---|
| `ZATCA_EGS_IDENTIFIER` | Choose an internal name for the primary device, such as `default`. Use the same name in jobs and commands; keep it stable after onboarding. |
| `ZATCA_INVOICE_TYPE` | Use `0100` for this package's primary B2C device. This is the CSR device capability, not the invoice's `388` document code. B2B has a separate setting below. |
| `ZATCA_VAT_NUMBER` | Copy the **15-digit VAT registration number** from the company's VAT certificate, not its CR number. It must start and end with `3`. The sample above is for sandbox only. |
| `ZATCA_ORG_IDENTIFIER` | Copy exactly the same value as `ZATCA_VAT_NUMBER`; do not enter a different company identifier. |
| `ZATCA_ORG_NAME` | Get the company's English legal name from its official business records, confirmed by the business owner/accountant. Used in the CSR; do not substitute a shop nickname or Arabic text. |
| `ZATCA_COMPANY_NAME_AR` | Copy the Arabic legal name from the company's registration documents, confirmed by the business owner/accountant. Used on invoices. |
| `ZATCA_ORG_UNIT_NAME` | Ask which branch issues these invoices, for example `Riyadh Branch`. For a VAT-group member, obtain the branch/member's 10-digit TIN from the accountant instead of using a branch label. |
| `ZATCA_CRN` | Copy the company's **10-digit commercial registration number** from its commercial registration. This differs from the VAT number. |
| `ZATCA_BUSINESS_CATEGORY` | Ask the business owner for a plain description of the registered activity, such as `Retail`. This is an activity description, not the VAT rate. |
| `ZATCA_COUNTRY_CODE` | Use `SA` for the Saudi seller in this setup. |
| `ZATCA_CSR_COMMON_NAME` | Assign a meaningful name to this registered device/app instance, such as `Example-POS-1`. Record it with your device configuration. |
| `ZATCA_CSR_SERIAL_NUMBER` | Build the device serial as `1-solution\|2-model\|3-serial`. Use your solution/provider name, model or app identifier, and a unique serial for this device. For a software device, you can generate a UUID once for the last part, for example with `Illuminate\Support\Str::uuid()`. Save it; do not generate a new value on every deployment. |
| `ZATCA_STREET` | Copy the street name from the branch's registered National Address. |
| `ZATCA_DISTRICT` | Copy the district from that National Address. |
| `ZATCA_BUILDING_NUMBER` | Copy the **4-digit building number** from that National Address; not the office/unit number. |
| `ZATCA_ADDITIONAL_NUMBER` | Copy the **4-digit additional/secondary number** from that National Address; not a phone number or the building number. |
| `ZATCA_CITY` | Copy the city from that National Address. |
| `ZATCA_POSTAL_CODE` | Copy its **5-digit postal code**. Do not guess it from the city name. |
| `ZATCA_VAT_PERCENTAGE` | Ask the accountant to confirm the default standard VAT rate for the goods/services you sell. The package default is `15`; items with different treatment use their own rate/category as explained below. |
| `ZATCA_VAT_MODE` | Check how your app stores the prices passed to the mapper. Use `inclusive` if those prices already include VAT, or `exclusive` if VAT must be added. Confirm this with the developer responsible for checkout and the accountant. |
| `ZATCA_QUEUE_CONNECTION` | Choose an existing connection from your app's `config/queue.php`, such as `database` or `redis`. If omitted, the package uses `QUEUE_CONNECTION`. |
| `ZATCA_QUEUE_NAME` | Choose a queue name, usually `zatca`, and configure the worker to listen to that exact name. This value does not come from the business or ZATCA. |

Use the branch's actual registered details consistently. Do not copy the example names, address, CR number or device serial into a real deployment. Repeated/sequential placeholder IDs can produce `BR-KSA-F-13` warnings.

If using the database queue, ensure your app has Laravel's queue tables and has migrated them. `sync` is suitable only for quick local tests; it runs signing and HTTP calls inside the request.

Create `storage/app/private/zatca` with the [storage permissions below](#storage-permissions). It must be writable by the **queue worker** and any CLI user running signing commands. The SDK itself needs read access only.

### Storage permissions

This directory temporarily holds private keys and certificates during signing. Keep it outside `public/`; never use `chmod 777` or change ownership of the entire `storage/` tree just for ZATCA.

**Local development:** if the worker and Artisan run as your own account, create the directory from your app root. On Linux/macOS:

```bash
mkdir -p storage/app/private/zatca
chmod 700 storage/app/private/zatca
```

On Windows, create the same directory and give your worker/CLI account write access through Windows folder permissions.

**Linux production:** first identify the account running this app's queue worker. Check `user=` in its Supervisor configuration or `User=` in its systemd service. Do not assume it is the PHP-FPM/web-server account. `whoami` shows the account running your manual Artisan commands.

If one account runs both the worker and signing commands, use that account as the owner. Replace `deploy` below with the actual worker account, and run from your app root:

```bash
QUEUE_USER=deploy
sudo mkdir -p storage/app/private/zatca
sudo chown "$QUEUE_USER" storage/app/private/zatca
sudo chmod 700 storage/app/private/zatca
sudo -u "$QUEUE_USER" -- test -w storage/app/private/zatca
```

The last command exits successfully if the worker can write. You can run manual signing/onboarding commands as the same account with `sudo -u "$QUEUE_USER" -- php artisan <command>`. That account also needs Java on its `PATH` and read access to the complete SDK folder. The web-server account needs signing access only if you use a synchronous queue.

If separate worker and CLI accounts must both write here, use a shared group instead. Replace both example usernames; create the group only if it does not already exist:

```bash
QUEUE_USER=deploy
CLI_USER=ops
sudo groupadd zatca-writers
sudo usermod -aG zatca-writers "$QUEUE_USER"
sudo usermod -aG zatca-writers "$CLI_USER"
sudo chown "$QUEUE_USER:zatca-writers" storage/app/private/zatca
sudo chmod 2770 storage/app/private/zatca
```

The `2` makes new entries inherit the directory's group. Start a new CLI login and restart the worker through its process manager so both pick up their new group membership. Verify write access with `sudo -u "$QUEUE_USER" -- test -w storage/app/private/zatca` and repeat for `"$CLI_USER"`. Existing files keep their existing permissions; avoid broad recursive permission changes to private signing files.

### Check the installation

```bash
php artisan config:clear
php artisan zatca:health-check
php artisan zatca:sdk-smoke-test
```

Missing onboarding credentials are expected at this stage. The smoke test makes no ZATCA calls: read its output and require all nine checks to show `[OK]`. `SKIPPED` can still exit successfully.

### Try it: built-in dashboard

Want to see the package work before writing any code? It ships an **optional dashboard** with an overview, an onboarding wizard, invoice history (response, attempts, XML download) and **six buttons**: B2C invoice, credit note, debit note and B2B invoice, credit note, debit note. Each button creates a sample sale, signs it, sends it to ZATCA and shows the real response.

It is **off by default** and has **no login**, so use it only on a local or test machine, in `sandbox` or `simulation`. It answers 404 unless enabled and 403 if `APP_ENV` or any EGS is `production`.

```dotenv
ZATCA_DASHBOARD_ENABLED=true
# ZATCA_DASHBOARD_PATH=zatca-dashboard   # optional URL prefix
```

```bash
php artisan config:clear
php artisan migrate        # adds 4 small zatca_demo_* tables, only while the dashboard is enabled
```

A plain `php artisan migrate` with the dashboard **off** (the default) creates only the package's core tables. The `zatca_demo_*` tables are created only while `ZATCA_DASHBOARD_ENABLED=true`, and switching it on later adds just those four tables. After changing the value, run `php artisan config:clear` first if your config is cached.

Open `http://your-app.test/zatca-dashboard`.

1. **Onboarding** page: run the steps for `default` (and `b2b` if `ZATCA_B2B_ENABLED=true`). In sandbox use OTP `123345`. This does the same as the [Artisan commands](#onboard-with-zatca).
2. **Overview** or **Invoices** page: press a scenario button. It takes roughly 5 to 30 seconds. Notes are issued against an accepted demo invoice; one is created automatically if none is eligible.

Things to know:

- No queue worker is needed for the demo: the button runs the real database queue in the same request. Real sales still need a worker and the scheduler.
- Demo sales use their own tables and a `DEMO-<id>` reference. If your app already binds `InvoiceSourceContract`, your mapper is kept for all your own sales; the dashboard only maps its demo orders.
- B2C demo prices include VAT; B2B demo prices exclude VAT (the dashboard sets the price mode itself, whatever `ZATCA_VAT_MODE` says).
- Add your own middleware (for example `auth`) through `zatca.dashboard.middleware` in the published config if you must expose it. Customise the pages with `php artisan vendor:publish --tag=zatca-dashboard-views`.
- Remove it when done: set `ZATCA_DASHBOARD_ENABLED=false`. The `zatca_demo_*` tables are harmless and can be dropped.

### Environments and B2B configuration

| Environment | Purpose | Credentials |
|---|---|---|
| `sandbox` | Developer-portal integration tests; no legal invoices | Sample VAT above, test OTP `123345` |
| `simulation` | Rehearsal with your real business data; no legal invoices | OTP from simulation portal; onboard again |
| `production` | Real legal invoices | OTP from production portal; onboard again |

Credentials and chains are isolated per EGS/environment. Do not reuse test credentials or switch a live invoicing server back to sandbox.

For B2B, add a separate device; it inherits the primary EGS's seller details:

```dotenv
ZATCA_B2B_ENABLED=false
ZATCA_B2B_EGS_IDENTIFIER=b2b
ZATCA_B2B_CSR_COMMON_NAME="Example-B2B-1"
ZATCA_B2B_CSR_SERIAL_NUMBER="1-Example|2-B2B|3-1c9b1f3e-0f2e-4a47-9a25-1b2c3d4e5f60"
ZATCA_B2B_INVOICE_TYPE=1000
```

The identifiers must differ. `1000` means standard-only; `1100` means standard and simplified. Sandbox accepted `1000`; verify it in simulation. Optional `ZATCA_B2B_ENVIRONMENT` and `ZATCA_B2B_ORG_UNIT_NAME` overrides should be **omitted** to inherit the primary values, rather than set to empty strings.

All options live in [config/zatca.php](config/zatca.php). Keep API `V2`, UBL `2.1` and XML profile `V2`; changing a version requires compatible code and tests. The [full configuration reference](docs/CONFIGURATION.md#all-other-options) covers HTTP, retry, monitoring and retention settings. After production config changes, run `php artisan config:cache` and `php artisan queue:restart`.

## Connect your Laravel app

### 1. Map your order to invoice input

Create `app/Zatca/OrderInvoiceSource.php` in your app. This example assumes an `Order` with `items`, `customer_name`, and a `paid_at` **datetime cast**. Items have a name, integer quantity, unit price and optional `vat_rate`. Keep these sale values stable until generation: the worker reloads the model from the database.

```php
<?php

namespace App\Zatca;

use DateTimeImmutable;
use DateTimeZone;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;

class OrderInvoiceSource implements InvoiceSourceContract
{
    public function sourceReference(mixed $source): string
    {
        return 'INV-'.$source->id;
    }

    public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
    {
        $items = [];
        foreach ($source->items as $item) {
            $items[] = new ZatcaInvoiceItem(
                description: $item->name,
                quantity: $item->quantity,
                price: $item->price,
                vatRate: $item->vat_rate,
            );
        }

        $issuedAt = DateTimeImmutable::createFromInterface($source->paid_at)
            ->setTimezone(new DateTimeZone('Asia/Riyadh'));

        return new ZatcaInvoiceInput(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($source),
            buyerName: $source->customer_name,
            buyerVatNumber: null,
            issueDateTime: $issuedAt,
            currency: 'SAR',
            items: $items,
        );
    }
}
```

Adapt model fields/relations to your app and cast item quantities to integers in your model. The reference must be stable, unique per sale and identical in both methods. It is also the invoice number in the XML. Use the actual sale time in Saudi time, not the time a retry happens. Do not calculate VAT or create `ZatcaInvoiceData` yourself.

### 2. Bind the mapper

In your app's `AppServiceProvider::register()`:

```php
$this->app->bind(
    \Zatca\Contracts\InvoiceSourceContract::class,
    \App\Zatca\OrderInvoiceSource::class,
);
```

The default source resolver reloads Eloquent models using `Model::find()`. For other sources, implement `InvoiceSourceResolverContract`; see the [non-Eloquent example](docs/USAGE_EXAMPLES.md#2-a-non-eloquent-invoice-source).

### 3. Start the worker

```bash
php artisan queue:work database --queue=zatca
```

For this database-queue setup, set both `QUEUE_CONNECTION=database` and `ZATCA_QUEUE_CONNECTION=database` in your app's `.env`. The first sets Laravel's default queue connection; the second explicitly selects it for ZATCA jobs. Ensure the database queue tables exist and migrations have run.

The worker connection and queue name must match `ZATCA_QUEUE_CONNECTION` and `ZATCA_QUEUE_NAME`. Keep this worker running; production also needs the scheduler described below. After changing these settings, run `php artisan config:cache` and `php artisan queue:restart`.

## Onboard with ZATCA

Do this once **per EGS, per environment**. A CSR requests a certificate; the private key signs documents. The compliance CSID is the test credential; the production CSID is the credential used for normal submissions in that environment.

Start in sandbox. For simulation/production, obtain a fresh OTP from the matching portal immediately before requesting compliance credentials.

```bash
php artisan zatca:generate-credentials --egs=default
```

Save the printed SDK fingerprint as `ZATCA_SDK_FINGERPRINT` in `.env`, then refresh config. **Running this command again replaces the stored key and CSR.** Use it only when deliberately starting/restarting onboarding, never just to inspect the SDK.

```bash
php artisan config:clear
php artisan zatca:onboard compliance --egs=default --otp=123345
php artisan zatca:onboard test-invoice --egs=default
php artisan zatca:onboard test-credit-note --egs=default
php artisan zatca:onboard test-debit-note --egs=default
php artisan zatca:onboard production --egs=default
php artisan zatca:health-check
php artisan zatca:inspect credentials --egs=default
```

Use your real OTP outside sandbox. Run the test steps in order: notes reference the test invoice. Each test consumes a compliance counter. Resolve `ERROR` results and review warnings before requesting the production certificate; expect valid compliance and production credentials afterward.

For B2B, repeat the whole sequence with `--egs=b2b`. The package selects standard compliance documents for the non-primary EGS. Complete the tests even though **the package does not enforce that all three passed before requesting the production CSID**; it relies on ZATCA to refuse incomplete onboarding. Sandbox does not enforce that refusal.

Then repeat in simulation with your real business data and simulation OTP. Follow the [simulation checks](#simulation-checks-before-b2b-production). Only afterward onboard in production with its OTP and refresh cached config/workers. Never send fake test sales to production.

For a custom admin onboarding screen, the verified PHP methods are listed in [onboarding from PHP](docs/INTEGRATION_REFERENCE.md#doing-the-same-from-php).

## Create and read invoices

### B2C invoice

After saving payment/order data, dispatch:

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;

dispatch(
    GenerateZatcaInvoiceJob::forModel($order, config('zatca.primary_egs'))->afterCommit()
);
```

With an asynchronous queue, the request returns while the worker calculates and validates the data, reserves the counter/UUID, stores a draft, signs it, then submits it. Validation happens **before** consuming a counter. Signed business data is frozen for notes and regeneration.

```php
use Zatca\Models\ZatcaInvoice;
use Zatca\State\EgsRegistry;

$egs = app(EgsRegistry::class)->primary();
$invoice = ZatcaInvoice::where('egs_identifier', $egs->egsIdentifier)
    ->where('environment', $egs->environment->value)
    ->where('source_reference', 'INV-'.$order->id)
    ->first()?->authoritative();

if ($invoice) {
    $accepted = $invoice->status->isTerminalSuccess();
    $qr = $invoice->qr_code;                  // Base64 TLV data for your receipt renderer
    $xml = $invoice->signed_xml_base64;
    $error = $invoice->last_error;
}
```

The row may not exist until the worker runs. `authoritative()` follows replacements to the current document. Scope queries to the correct EGS/environment (and your tenant where applicable). The package stores XML and QR data; your app builds the PDF/receipt and handles delivery.

### B2B invoice

Extend the same mapper for company orders. Alongside the existing imports, add:

```php
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;
```

Before returning `ZatcaInvoiceInput`, set `$isCompany = $source->buyer_type === 'company';` and `$c = $isCompany ? $source->company : null;`. Add these arguments to that constructor:

```php
documentType: $isCompany
    ? ZatcaComplianceDocumentType::StandardInvoice
    : ZatcaComplianceDocumentType::Invoice,
buyer: $isCompany ? new BuyerParty(
    registrationName: $c->legal_name,
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
) : null,
supplyDate: $isCompany
    ? DateTimeImmutable::createFromInterface($source->supplied_at)
    : null,
priceMode: $isCompany ? VatPriceMode::Exclusive : null,
```

This assumes company relations/fields and a `supplied_at` datetime cast. Use the actual delivery/supply date. Give the buyer exactly one identification method: VAT number or other ID. Saudi buyers need street, 4-digit building number, district, city, 5-digit postal code and country; the additional number is optional. B2B XML uses `buyer`, not the flat `buyerName`/`buyerVatNumber` fields.

The `Exclusive` override above is appropriate **only if your company prices exclude VAT**. Otherwise use `null` for the configured mode.

Check readiness **before accepting a new company sale**, then dispatch on the B2B EGS:

```php
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Support\B2bReadiness;

abort_unless(app(B2bReadiness::class)->ready(), 422, 'Company invoicing is unavailable.');

dispatch(
    GenerateZatcaInvoiceJob::forModel($order, config('zatca.b2b.egs_identifier'))->afterCommit()
);
```

Set `ZATCA_B2B_ENABLED=true` in the environment you are testing once onboarding is complete. `ready()` checks the switch, registered B2B EGS, presence of a compliance certificate and a production certificate whose stored state is `valid`. **It does not inspect the three compliance-test results.** Your deployment process must verify them. Direct generation does not enforce the B2B switch for you.

Wait for `cleared`/`cleared_with_warnings` before delivering a B2B document. Read its stamped XML and QR from ZATCA's returned copy:

```php
use Zatca\Services\SDK\SignedInvoiceXmlReader;

// $invoice is the current B2B ZatcaInvoice, loaded for the right EGS/environment.
if ($invoice->status->isTerminalSuccess() && $invoice->cleared_xml_base64) {
    $xml = base64_decode($invoice->cleared_xml_base64);
    $qr = app(SignedInvoiceXmlReader::class)->extractQrCode($xml);
}
```

Confirm buyer-facing XML/QR behavior in simulation; it remains an open verification item. `ZatcaInvoiceGenerated` fires after signing, **before clearance**, so it must not trigger B2B delivery on its own.

### Prices, VAT and discounts

Pass unit prices, not calculated net/VAT totals. Prices may have up to 6 decimals; quantities must be whole numbers of at least 1, and prices cannot be negative.

| Mode | Unit price 115.00 at 15% VAT |
|---|---|
| `inclusive` (default) | Net 100.00 + VAT 15.00 = 115.00 |
| `exclusive` | Net 115.00 + VAT 17.25 = 132.25 |

The calculator uses decimal arithmetic and half-up rounding to halalas. It multiplies quantity by price **before rounding the line**, splits net/VAT, then sums rounded lines minus discounts. Inclusive VAT is the remainder after rounding net, so a one-halala difference from net × rate can be valid. XML net unit prices can have up to 6 decimals to reconcile the line.

`ZATCA_VAT_PERCENTAGE` defaults to 15; standard rates must be above 0, at most 100, with at most 2 decimals. `vatRate` overrides it per item. One invoice can mix rates and categories:

```php
use Zatca\DTOs\ZatcaInvoiceItem;

$items = [
    new ZatcaInvoiceItem('Standard item', 1, '115.00'),
    new ZatcaInvoiceItem('Item at another rate', 1, '105.00', vatRate: 5),
    ZatcaInvoiceItem::zeroRated('Export', 1, '200.00', 'VATEX-SA-32', 'Export of goods'),
    ZatcaInvoiceItem::exempt('Residential rent', 1, '500.00', 'VATEX-SA-30', 'Residential rent'),
];
```

Choose the actual applicable exemption code from ZATCA's list. `S` is standard-rated; `Z` and `E` are always 0% and require a `VATEX-SA-...` code and reason text. Do not represent 0% as standard-rated or supply a rate to a zero-rated/exempt item.

Add document-level discounts through the input's `discounts` argument:

```php
discounts: [
    new \Zatca\DTOs\ZatcaInvoiceDiscount(
        reason: 'Coupon', amount: '23.00', lineIndexes: [0],
    ),
],
```

Amounts use the invoice's price mode. `lineIndexes` are zero-based; `poolKey` can identify a discount group for later credits. Tax is inferred only when all covered lines share one category/rate. **Mixed covered lines fall back to the configured standard rate, even with `lineIndexes`.** Split discounts by tax treatment or explicitly set `vatRate`, `vatCategory` and any required exemption fields. Many small discounts can fail `BR-S-09` rounding checks; consolidate or allocate them carefully.

See [VAT rounding, mixed discounts and worked examples](docs/VAT_GUIDE.md). To preview totals, call `app(\Zatca\Services\Tax\InvoiceCalculator::class)->calculate($input)` and read `totalExclVat`, `totalVat`, `totalInclVat`. Changing defaults affects new invoices only; existing snapshots retain their tax treatment.

## Create credit and debit notes

The original must be an accepted invoice with a snapshot and usable credentials. Only one credit **or** debit note may be in progress against it at a time. Notes inherit the original EGS, document family, buyer and supply date. The same calls below therefore cover **both B2C and B2B**.

Use the current original (`$original = $invoice->authoritative()`). Generate a unique UUID **once per refund/extra-charge request**, persist it in your application, and reuse it on retries/double-clicks. In the examples, `$requestKey` is that stored UUID. A different key can create a separate charge; idempotency does not detect two different business requests for the same amount.

### Whole-line or full credit

```php
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Services\CreditNote\CreditNoteIssuer;

$credit = app(CreditNoteIssuer::class)->issue(
    original: $original,
    type: ZatcaCreditNoteType::Partial,
    lineIndexes: [0],
    reason: 'Customer returned the item',
    idempotencyKey: $requestKey,
);
```

`Partial` selects **whole lines**, not quantities within a line. `Full` credits all remaining eligible sources (pass `lineIndexes: []`). Values come from frozen data, reduced by previous credits and allocated discounts. You do not pass line amounts. Optional `expectedTotalInclVat` refuses the request if the calculated refund differs from what the user confirmed. Reasons appear in the signed XML and must be meaningful (maximum 1,000 characters).

### Credit part of a line's value

```php
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditSource;

$credit = app(CreditNoteIssuer::class)->issuePriceAdjustment(
    original: $original,
    sourceKey: CreditSource::invoiceLineKey(0),
    amount: '23.00',
    description: 'Partial refund for damage',
    reason: 'Price reduced after delivery',
    idempotencyKey: $requestKey,
);
```

The amount uses that source line's **frozen price mode and VAT rate**. At 15%, 23.00 inclusive credits net 20.00 + VAT 3.00; 23.00 exclusive credits net 23.00 + VAT 3.45. It must be positive and **less than** the remaining adjustable value. To reduce the entire remaining value to zero, use a whole-line credit instead.

For several adjustments, use `issuePriceAdjustments()` with an array of `new Zatca\Services\CreditNote\PriceAdjustmentInput($sourceKey, $amount, $description)`. Accepted debit-note additions can also be credited using `debitNoteLineIds` in `issue()` or `CreditSource::debitNoteLineKey($id)` for a value adjustment. The [refund examples](docs/USAGE_EXAMPLES.md#3-a-refund-screen-eligibility-check-issue-and-event-together) show eligibility and remaining-credit checks.

### Debit note

```php
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;

$debit = app(DebitNoteIssuer::class)->issue(
    original: $original,
    reasonType: ZatcaDebitNoteReason::AdditionalItem,
    lines: [new DebitNoteLineInput('Delivery charge', 1, '23.00')],
    reason: 'Delivery was omitted from the original invoice',
    idempotencyKey: $requestKey,
);
```

Debit prices default to the **current configured price mode**, not the original's. Pass `priceMode: \Zatca\Enums\VatPriceMode::Exclusive` if the additional prices exclude VAT.

| `ZatcaDebitNoteReason` | Line input |
|---|---|
| `AdditionalItem` | New item/charge; optional `itemId` is your integer product/service ID |
| `PriceAdjustment` | Extra unit price and quantity; set `originalLineIndex` or `sourceDebitNoteLineId` for an accepted earlier addition, never both |
| `VatAdjustment` | `DebitNoteLineInput::vatAdjustment('VAT correction', vatAmount: '7.50', originalLineIndex: 0)` adds VAT only, no net amount |
| `Other` | New charge with a clear description |

An adjusting line inherits the referenced source's frozen VAT category/rate unless explicitly overridden. New charges use configured defaults unless specified. Do not use negative invoice items for refunds.

### Note status and application actions

The issuers create the business record and queue generation **after commit**. They generate `CN-000001`/`DN-000001` numbers and original references automatically. Use `$credit->refresh()->isAccepted()` or `$debit->refresh()->isAccepted()` and `latestZatcaInvoice` for XML, QR, errors and submission status. B2B notes use that document's cleared XML.

`CreditNoteStatusResolver` / `DebitNoteStatusResolver` (under the corresponding `Zatca\Services` namespace) expose `resolve($note)` for `pending`, `processing`, `accepted`, `rejected`, `failed`, `generation_failed`, `cancelled`.

Before showing a note button, use `CreditNoteEligibilityPolicy::verdict($original)` or `DebitNoteEligibilityPolicy::verdict($original)` and read `eligible`/`reason`. The issuer checks again under a lock. `CreditNoteLedger::forOriginal($original)->availableIndexes` shows remaining whole lines.

A never-submitted note may be cancelled via the relevant `CreditNoteCanceller`/`DebitNoteCanceller`: call `isCancellable($note)` before `cancel($note, $actor)`. Accepted or potentially in-flight notes cannot be cancelled. See [cancellation example](docs/INTEGRATION_REFERENCE.md#cancelling-a-note-that-never-reached-zatca).

## Check statuses and recover failures

### What acceptance means

**HTTP 200 alone is not acceptance.** Use `$invoice->status->isTerminalSuccess()`. Warnings can accompany accepted documents: read `last_error` and correct future data without resubmitting the accepted document.

| Status | Meaning / action |
|---|---|
| `draft` | Unsigned; generation retries normally resume it |
| `pending_reporting`, `pending_clearance` | Signed, waiting for submission |
| `reported`, `reported_with_warnings` | B2C accepted |
| `cleared`, `cleared_with_warnings` | B2B accepted; use ZATCA's cleared copy |
| `retrying`, `clearance_retrying` | Temporary network/server problem; retry the same signed document |
| `clearance_in_doubt` | B2B timed out; acceptance is unknown. The sweep retries the same document; alert an operator |
| `manual_review`, `clearance_manual_review` | Rejection, authentication/contract problem or exhausted retries; inspect the last attempt |
| `clearance_rejected` | Explicit refusal; correct the cause and assess regeneration, never resend as-is |
| `failed`, `generated` | Enum values currently not written by the normal pipeline |

The database keeps document snapshots, hashes, unsigned/signed XML, QR, cleared XML, errors and acceptance timestamps in `zatca_invoices`. `zatca_submission_attempts` records HTTP status (0 for network failure) and sanitized responses. Credit/debit tables keep their business records, lines and audit events. See [database tracing](docs/ZATCA_WORKFLOWS.md#6-tracing-a-document-through-the-database).

### What retries do automatically

- Generation defaults to 5 tries with 5s, 30s, 2m, 10m backoff. A signing failure leaves a draft with its reserved UUID/ICV; retries resume it.
- Submission defaults to 12 attempts (`ZATCA_REPORTING_RETRY_ATTEMPTS`). Network/5xx failures retry; exhaustion needs review. At a five-minute sweep cadence this covers roughly one hour of an outage.
- `zatca:report-pending` dispatches pending/retrying documents for **both** routes, including `clearance_in_doubt`. It excludes rejected/manual-review documents and unsigned drafts.
- Submission retries reuse the **exact same signed XML, UUID and ICV**. They never sign again. Accepted documents are excluded. A 409 counts as already reported only when the response body confirms it.
- Repeating generation for the same sale finds the existing document. Cross-EGS duplicate protection prevents invoicing the same source reference on two devices in the same environment.

### Diagnose first

```bash
php artisan zatca:status --json
php artisan zatca:inspect invoice --id=42
php artisan zatca:inspect state --egs=default
```

`inspect` accepts invoice ID or UUID and omits secrets/XML. `status` fails on actionable failures, warns about B2C reporting after 20 hours by default and fails after 24 hours. It also checks failed jobs, certificates and the scheduler heartbeat (stale after 30 minutes by default). Add `--strict` to fail on warnings and `--stale-minutes=60` to set the stuck-document threshold. Your monitoring must send the alert; the package does not install notifications.

### Recover a technical failure

Fix Java, permissions, credentials or connectivity first. For one sale/document:

```bash
php artisan zatca:recover --id=42 --dry-run
php artisan zatca:recover --id=42
```

If no document exists, identify the source instead:

```bash
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042 --egs=default --dry-run
```

Review the plan, then rerun without `--dry-run`. An explicit EGS is required for a new document when B2B is configured. Recovery signs an existing draft or resends stored signed XML, sends once synchronously, and follows replacements. It stops for rejected/accepted documents, ambiguity, expired credentials or the global switch. For `clearance_in_doubt`, first check ZATCA, then deliberately add `--resend-in-doubt` if appropriate. **Recovery never regenerates.** Exit 0 means accepted/no work/safe dry-run; 1 means it is not completed or was refused. Your app's receipts, emails and other follow-ups remain your responsibility.

For batches after an outage:

```bash
php artisan zatca:requeue --only-transient --dry-run
php artisan zatca:requeue --only-transient
```

Default status selection is **B2C `manual_review`**. For exhausted B2B retries, add `--status=clearance_manual_review`. These commands filter the last attempt to HTTP 0/5xx, normally reset the attempt counter and route by the document itself. Use `--keep-attempts` to preserve the counter; `--max-total-attempts` defaults to 50. Use `--help` for ID/EGS/limit filters. Never batch requeue validation rejections.

### Regenerate only a rejected document

```php
use Zatca\Jobs\RegenerateZatcaInvoiceJob;
use Zatca\Services\Regeneration\RegenerationPolicy;

if (app(RegenerationPolicy::class)->allows($invoice)) {
    dispatch(new RegenerateZatcaInvoiceJob($invoice->id));
}
```

The replacement gets a new UUID/ICV and a reference such as `INV-1001#r1`. The old row/history stay intact. Transient errors and 401/403 authentication errors are not regeneration cases.

**Regeneration rebuilds XML with the current generator and seller config from the frozen business snapshot. It does not reread changed order prices, buyer details or mapper output.** Fix a generator/configuration problem before reissuing. If the frozen business data itself is wrong, this API does not provide a way to edit it; do not assume changing the order will fix it or manually edit stored documents.

For a stuck unsigned draft, use recovery or `CompleteZatcaInvoiceGenerationJob` guarded by `DraftCompletionPolicy`. See [programmatic completion and regeneration](docs/USAGE_EXAMPLES.md#5-recovering-and-regenerating-from-code). Neither the pending sweep nor requeue completes unsigned drafts.

| Symptom | First check |
|---|---|
| No document / generation failed | Mapper binding, source exists, invoice data, production credentials for the current environment |
| Draft/signing error | Java as worker user, absolute SDK paths, private storage permissions |
| Pending documents accumulate | Worker connection/name and process-manager logs; scheduler/cron |
| VAT error or `BR-S-09` | Price mode, category/rate, discount allocation and rounding |
| 401/403 | Credentials/certificate/environment; do not regenerate |
| Invalid OTP / missing compliance sample | Correct portal, fresh OTP, onboarding step order |
| Fingerprint mismatch | Investigate the changed jar; do not replace live keys to obtain a hash |
| Counter/hash mismatch after restore | Stop sending; contact ZATCA before continuing |

More rejection codes, exceptions and incident steps: [Troubleshooting](docs/TROUBLESHOOTING.md).

## Deploy and operate safely

### Worker, scheduler and monitoring

Keep `queue:work` running under Supervisor or systemd, starting with one process. The **worker/CLI users** need Java, SDK read access and private-storage write access; the web user normally does not need signing access. Set the worker's connection/name to match config. Use [complete server setup](docs/SERVER_REQUIREMENTS.md#setup-order) for process-manager files, users, groups and permissions.

Register these in `routes/console.php` on Laravel 11/12:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('zatca:report-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('zatca:requeue --only-transient')->hourly()->withoutOverlapping();
Schedule::command('zatca:certificate:check')->daily();
Schedule::command('zatca:status')->hourly();
Schedule::command('zatca:prune-submission-attempts')->weekly();
Schedule::command('zatca:prune-sdk-files')->daily();
```

On Laravel 9/10, use `$schedule->command(...)` inside `App\Console\Kernel::schedule()` instead. Add this cron entry for your app's CLI account, replacing the app/PHP paths:

```cron
* * * * * cd /var/www/your-app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Both the persistent worker and cron are required.** Check `php artisan schedule:list`, worker logs and actual cron runs. Use a cache that persists between processes for the heartbeat, not `array`/`null`. Recovery also needs cache locks. On multiple scheduler servers, add `onOneServer()` and use a shared supported atomic-lock cache (for example Redis/database).

The hourly requeue above revives exhausted **B2C** outage failures by default. If operating B2B, also register `zatca:requeue --status=clearance_manual_review --only-transient` hourly with `withoutOverlapping()`, or handle those failures manually.

Attempt pruning defaults to 180 days and selects successful B2C attempt outcomes; it is not general B2B/history retention. SDK cleanup defaults to 7 days, never touches files younger than one hour, and offers `--dry-run`. Neither command deletes invoice records.

### Events and switches

Register Laravel listeners for the events your app needs:

| Event under `Zatca\Events` | Purpose |
|---|---|
| `ZatcaInvoiceGenerated` (`invoice`) | Signed data ready; may prepare a B2C receipt. B2B still needs clearance |
| `ZatcaInvoiceNeedsAttention` (`invoice`, `reason`) | Notify an operator |
| `ZatcaCreditNoteAccepted` (`creditNote`) | Start your refund/restock workflow |
| `ZatcaDebitNoteAccepted` (`debitNote`) | Start your extra-charge/fulfilment workflow |
| `ZatcaCertificateExpiring` | Warn on transition to expiring/expired; includes EGS, type, expiry, days remaining and `hasExpired()` |

ZATCA acceptance does **not** move money or prove payment. Your application handles refunds, charging, stock, delivery and alerts. Reason helpers `creditReason()->cancelsItems()` and `reason_type->addsItems()` distinguish item changes; debit lines retain your `item_id` values.

`ZATCA_ENABLED=false` stops generation/signing/submission/retries without using counters; queued jobs exit quietly. Onboarding and read-only diagnostics still work. Sales skipped while off need their jobs dispatched again when enabled. Only recognized false values (`false`, `0`, `off`, `no`) disable it; an empty value does not.

`ZATCA_B2B_ENABLED` requires explicit `true`, `1`, `on` or `yes`. Your app checks it through readiness for **new** company sales; already-signed standard documents keep clearing when it is off. Use `Zatca\Support\ZatcaGate::enabled()` / `b2bEnabled()` through `app(...)` when checking switches in code. Refresh config and workers after changes.

### Certificates, secrets and deployment

`zatca:certificate:check` marks certificates `valid`, `expiring_soon` (default 60 days before expiry) or `expired` and emits an event on state changes. Only the largest configured `certificate.alert_days` value is used. Expired production credentials pause submission for that EGS.

**Automatic renewal is unverified and disabled.** Leave `ZATCA_AUTO_RENEWAL_ENABLED=false`; `zatca:certificate:renew` is not a verified production renewal solution. Rehearse deliberate re-onboarding in simulation, then re-onboard with a fresh OTP before expiry without resetting counters/state.

Before going live, verify seller data, SDK/fingerprint, valid production credentials, worker/cron, persistent cache, monitoring, backups and the simulation gates. Keep SDK paths valid across releases; a changing release directory may need a fixed SDK location. To inspect the jar fingerprint without replacing credentials, compute SHA-256 directly (for example `sha256sum /absolute/path/to/sdk.jar` on Linux); investigate unexpected changes before updating the configured fingerprint.

For subsequent deploys, use your normal safe Laravel deployment process, including:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
php artisan zatca:health-check
```

Confirm one small **real** sale is reported/cleared after first production setup. For upgrades, read [CHANGELOG.md](CHANGELOG.md), [UPGRADE.md](UPGRADE.md) and run source-uniqueness checks before migrations. Version/SDK changes need the [compatibility procedure](docs/COMPATIBILITY.md#adding-a-new-zatca-version-combination).

- Back up the database **and `APP_KEY`** securely. Losing/changing that key makes default-encrypted credentials unreadable.
- Never delete/edit credentials, EGS states or invoice rows to fix failures. Never reset counters or run `migrate:fresh`/`migrate:refresh` in production. Restoring an old backup rewinds the chain: contact ZATCA before sending more invoices.
- Never put your private keys, CSIDs, tokens or certificates in Git, logs or `.env`. They belong encrypted in the database. SDK workspaces briefly contain secrets; keep private storage and SDK folders outside the web root.
- Logs use Laravel's log with a `[ZATCA]` prefix and sanitized responses. Use `Zatca\Services\Security\ZatcaSanitizer` for custom diagnostic logs.

Optional integrations can replace `CredentialEncrypterInterface` (Vault/KMS), `OwnerResolverInterface` (tenant owner ID; default `null`) and the source resolver via service-container bindings. `ZATCA_CREDENTIAL_ENCRYPTER` alone is only a label; it does not select a new backend. See [custom bindings](docs/INTEGRATION_REFERENCE.md#custom-bindings).

## Know the limits

### Simulation checks before B2B production

Recorded sandbox tests cover onboarding, all six documents, XML validation, routes/authentication, note references and cleared copies. **The sandbox accepts arbitrary OTPs and issues a shared production certificate that does not match your key.** It also allows requesting production credentials before completing compliance. It cannot prove signature validity or real refusal behavior.

Onboard both devices in simulation with your real data and verify:

1. The production signature and onboarding refusals.
2. Whether the separate B2B device should use CSR type `1000` or `1100`.
3. Hash-chain behavior after clearance and rejection.
4. Actual `NOT_CLEARED` and warning responses.
5. Which returned XML/QR to deliver to the B2B buyer.
6. Resubmission behavior after a `clearance_in_doubt` timeout.

These remain project verification gates, not claims of production support. See the [full readiness record](docs/LIMITATIONS.md#known-limitations).

### Functional limits

| Area | Limit |
|---|---|
| Currency | SAR only |
| Quantity | Whole numbers only; no fractional weights/quantities |
| VAT | `S`, `Z`, `E` supported; out-of-scope `O` unsupported |
| Discounts | Document-level only, no line-level discounts |
| Price mode | One per document, not per item |
| XML unit price | Up to 6 decimals; SDK/sandbox verified, simulation/production not yet verified |
| Payment means | Always code `10` (cash) |
| Notes | Whole-line credits or value adjustments; one note in progress per original |
| Renewal / output | Renewal API unverified; no PDF generation |

For pre-release installations using old course-specific names or old VAT DTOs, read [renamed APIs and migration changes](docs/MIGRATION_GUIDE.md#17-renamed-classes-and-methods). PHP aliases exist for some names, but the old `toInvoiceData()` mapper/amount-based signatures have breaking replacements. Original migrations changed (`item_id`, reason values, 6-decimal unit prices); affected disposable local/staging databases need rebuilding. **Never apply that rebuild advice to production data.**

## Tests and further reading

Run package tests from the **package checkout**, not your consuming app:

```bash
composer install
composer test
```

The normal suite uses Testbench/SQLite and makes no live ZATCA calls. Real-SDK cases skip unless SDK paths are configured. For Bash, enable the bundled SDK explicitly:

```bash
export ZATCA_SDK_ROOT="$PWD/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8"
export ZATCA_SDK_EXECUTABLE="$ZATCA_SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar"
composer smoke
composer test
composer test:sandbox
```

On PowerShell, set `$env:ZATCA_SDK_ROOT` and `$env:ZATCA_SDK_EXECUTABLE` to the equivalent absolute paths. Java 11 must be on `PATH`.

`test:sandbox` opts into real developer-portal requests, uses sample VAT/OTP and fresh devices, and refuses non-sandbox hosts. Without SDK paths its tests skip. It is separate from `composer test` and does not require company credentials. Read skip output; a passing run with skipped SDK/sandbox cases proves less than a full run.

The repository records a real-SDK compatibility run on 2026-10-20 across selected Laravel 9–12 / PHP 8.1–8.4 pairs. The sandbox suite was last recorded on Laravel 10 / PHP 8.2. These are historical results, not a new test run for this guide. See [compatibility evidence and matrix script](docs/COMPATIBILITY.md#3-laravel-and-php).

| Read more | Topic |
|---|---|
| [ZATCA concepts](docs/ZATCA_CONCEPTS.md) | EGS, CSR, certificates, ICV/PIH, XML, signatures, QR |
| [Invoice types](docs/INVOICE_TYPES.md) | Choosing types, notes and tax categories |
| [Usage examples](docs/USAGE_EXAMPLES.md) | Checkout previews, non-Eloquent sources, refund screens, dashboards, recovery |
| [VAT guide](docs/VAT_GUIDE.md) | Exact rounding, mixed categories, discount allocation and price adjustments |
| [Workflows](docs/ZATCA_WORKFLOWS.md) | Lifecycle, environments, certificate management, database tracing and version updates |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Rejection codes, exceptions, integrity and incident response |
| [Compatibility](docs/COMPATIBILITY.md) | Tested versions and readiness evidence |
| [Configuration reference](docs/CONFIGURATION.md) | Seller/device settings, VAT defaults, HTTP, retries and retention |
| [Integration reference](docs/INTEGRATION_REFERENCE.md) | Onboarding from PHP, invoice fields, events, cancellation and custom bindings |
| [Submission responses](docs/RESPONSES.md) | All statuses, response fields, retries and exceptions |
| [SDK distribution](SDKs/README.md) | Bundled SDK contents, provenance, checksum and license |
| [Command reference](docs/COMMANDS.md#13-console-commands) | All 14 commands and flags; also use `php artisan help <command>` |
| [Server setup](docs/SERVER_REQUIREMENTS.md#setup-order) | Linux users, permissions, stable SDK paths, Supervisor/systemd and deployment checklist |
| [Known limitations](docs/LIMITATIONS.md) | Functional limits and outstanding simulation checks |
| [Migration guide](docs/MIGRATION_GUIDE.md) | Renamed APIs and pre-release database changes |
| [Testing](docs/TESTING.md) | Package tests, real SDK setup and opt-in sandbox tests |

Some older linked files contain earlier test counts or readiness wording. Use this guide's explicit simulation limits and the [full readiness record](docs/LIMITATIONS.md#known-limitations) when assessing B2B.

## License

Released under the [MIT License](LICENSE). The bundled ZATCA SDK is a separate work under [GNU LGPL v3](SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/LICENSE.txt).
