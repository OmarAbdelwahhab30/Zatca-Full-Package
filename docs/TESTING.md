# Package, real-SDK and sandbox tests

<a id="18-running-the-package-tests"></a>
## Running the package tests

```bash
composer install
```

```bash
composer test
```

The suite uses Orchestra Testbench and SQLite and never calls ZATCA or the real SDK. It has 890 tests. With the real SDK configured, 1 is skipped on Windows (a Unix-only file-permission test); without it, the 9 real-SDK tests are skipped too. The 13 [sandbox tests](#sandbox-tests) are separate. On PHP 8.2+, `composer install` picks Laravel 12. To run the suite against an older Laravel version, install into a separate copy of the package with `composer update --with "orchestra/testbench:^9.0" -W` (Laravel 11; use `^8.0` for 10 and `^7.0` for 9). Laravel 9–11 need the security override described in [Laravel compatibility](SERVER_REQUIREMENTS.md#laravel-compatibility), so keep that copy throwaway and never ship its settings.

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
- The ZATCA SDK. The copy bundled in [`SDKs/`](../SDKs/) works.
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

**What they check** (13 tests, in [`tests/Sandbox/`](../tests/Sandbox/))

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

**Not covered by the sandbox.** The sandbox accepts any OTP. It also issues a production certificate even before the compliance checks, and that certificate is a shared one that does not match your key. So these tests do not prove the production signature, and they cannot test refusals. The refusals and wrong-order cases are covered by `tests/Feature/OnboardCommandFailuresTest.php` in the normal suite instead. Proving the signature needs a rehearsal in ZATCA's **simulation** environment (see [Known limitations](LIMITATIONS.md#known-limitations)).

More operational detail is in [`docs/OPERATIONS.md`](OPERATIONS.md), [`docs/SERVER_REQUIREMENTS.md`](SERVER_REQUIREMENTS.md), [`docs/DATABASE.md`](DATABASE.md) and [`docs/COMPATIBILITY.md`](COMPATIBILITY.md).
