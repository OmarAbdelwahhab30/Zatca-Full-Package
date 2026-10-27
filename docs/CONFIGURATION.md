# Configuration reference

<a id="6-configuration"></a>
## Configuration

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
| `ZATCA_VAT_PERCENTAGE` | `15` | The rate for standard-rated items. Above 0, at most 100, at most 2 decimals. An item can set its own rate (see [Invoice items](INTEGRATION_REFERENCE.md#invoice-items)). |
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
| `ZATCA_SCHEDULER_STALE_MINUTES` | `30` | `zatca:status` and `zatca:health-check` report a failure when `zatca:report-pending` has not run for this many minutes (a missing or broken cron entry). |
| `ZATCA_REPORTING_DEADLINE_WARN_HOURS` | `20` | `zatca:status` warns about B2C documents still unreported after this many hours (1–23). It fails once one is past ZATCA's 24-hour limit. |
| `ZATCA_AUTO_RENEWAL_ENABLED` | `false` | Lets `zatca:certificate:renew` call ZATCA. Leave it `false` (see [Certificates](INTEGRATION_REFERENCE.md#certificates)). |
| `ZATCA_CREDENTIAL_ENCRYPTER` | `laravel` | Label only. To use Vault or KMS, bind your own encrypter (see [Custom bindings](INTEGRATION_REFERENCE.md#custom-bindings)). |
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
