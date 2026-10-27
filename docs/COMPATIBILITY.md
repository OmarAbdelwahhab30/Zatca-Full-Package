# ZATCA Package Compatibility Matrix

This file answers three questions:

1. Which ZATCA versions (API, SDK, UBL, XML profile) the package works with.
2. Which document flows the package supports, and **how far each one has been verified**.
3. Which Laravel and PHP versions the package runs on, and which of those still get security fixes.

Do not mix ZATCA versions outside the table in section 1. Do not treat a flow as production-verified unless section 2 says so.

## 1. ZATCA version combination

| Package version | `ZATCA_API_VERSION` | `ZATCA_SDK_VERSION` | `ZATCA_UBL_VERSION` | `ZATCA_XML_PROFILE_VERSION` | Notes |
|---|---|---|---|---|---|
| 1.0.0 (ready to tag, see [CHANGELOG](../CHANGELOG.md)) | `V2` | `3.4.8` | `2.1` | `V2` | Reporting (B2C) and Clearance (B2B, off by default): the document flows in section 2. Tested SDK copy: [`SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/`](../SDKs/README.md) (`composer smoke` all OK, real-SDK tests passing, 2026-10-14). Golden-file fixtures for this SDK version live at `tests/Fixtures/SdkOutput/V3_4_8/` (SDK output) and `tests/Fixtures/Golden/V2/` (unsigned XML and snapshots). |

## 2. Document flows and how far each is verified

"Package tests" means the automated suite (`composer test`). It does not call ZATCA, and the 7 tests that drive the real Java SDK skip unless you point them at an SDK install (for example the copy in [`SDKs/`](../SDKs/README.md)). So "package tests" proves the package logic and the XML it builds, not that ZATCA accepts it.

| Flow | Submission | Status | Verified by |
|---|---|---|---|
| Simplified Tax Invoice (B2C) | Reporting | Supported | End-to-end for 1.0.x, plus package tests and golden XML fixtures |
| Simplified Credit Note / Debit Note (B2C), for real sales | Reporting | Supported | Package tests, golden XML fixtures, and the developer-portal sandbox (`composer test:sandbox`: full and partial credit notes, debit note, all reported with no warnings). Onboarding (`zatca:onboard test-credit-note` / `test-debit-note`) submits compliance samples of both types. |
| Standard Tax Invoice (B2B) | Clearance | Built, **off by default** (`ZATCA_B2B_ENABLED=false`) | Package tests, and the developer-portal sandbox (`composer test:sandbox`: B2B EGS onboarding with invoice type `1000`, invoices cleared with no warnings, cleared copy stored). **Not yet run in simulation**, which is the only place the production signature is checked. See the README's [Known limitations](LIMITATIONS.md#known-limitations). |
| Standard Credit Note / Debit Note (B2B) | Clearance | Built, **off by default** | Package tests, and the developer-portal sandbox (full and partial credit notes, debit note, all cleared with no warnings). Not yet run in simulation. |

Before turning B2B on in production, run `composer test:sandbox` and a simulation rehearsal, as described under "Known limitations" in the [README](LIMITATIONS.md#known-limitations). The developer-portal sandbox is lenient: it accepts any OTP, and its production certificate is shared and does not match your key, so it does not check the production signature.

## 3. Laravel and PHP

Two separate questions:

- **Technical compatibility:** the package installs and its test suite passes.
- **Security support:** Laravel itself still ships security fixes for that version.

| Laravel | Level | PHP | Technical compatibility | Security support from Laravel | Installs with Composer's default settings? |
|---|---|---|---|---|---|
| **12** | **Recommended** | 8.2+ | ✅ Tested | ✅ Yes | ✅ Yes |
| 11 | Legacy Compatibility | 8.2+ | ✅ Tested | ❌ No (open advisories, no fixed 11.x release) | ❌ No |
| 10 | Legacy Compatibility | 8.1+ | ✅ Tested | ❌ No (open advisories, no fixed 10.x release) | ❌ No |
| 9 | Legacy Compatibility | 8.1+ | ✅ Tested | ❌ No (open advisories, no fixed 9.x release) | ❌ No |

Laravel 9–11 are kept so existing apps keep working while they plan an upgrade. Use Laravel 12 for new production projects. The README section [Laravel compatibility](SERVER_REQUIREMENTS.md#laravel-compatibility) lists the open advisories and explains how an app owner can override Composer's security block. This package never turns that block off for you.

**Test runs (2026-10-20, SQLite, Windows, real ZATCA SDK enabled, clean install per Laravel version):**

| Laravel | Framework | Testbench | PHPUnit | PHP versions run | Result |
|---|---|---|---|---|---|
| 9 | 9.52.22 | 7.58 | 9.6 | 8.1, 8.2 | 890 passed, 2 skipped on 8.1, 1 on 8.2 |
| 10 | 10.50.3 | 8.39 | 10.5 | 8.1, 8.3 | 890 passed, 2 skipped on 8.1, 1 on 8.3 |
| 11 | 11.57.0 | 9.18 | 11.5 | 8.2, 8.4 | 890 passed, 1 skipped on each |
| 12 | 12.70.0 | 10.12 | 11.5 | 8.2, 8.4 | 890 passed, 1 skipped on each |

Together these cover every Laravel from 9 to 12 and every PHP from 8.1 to 8.4. `docs/run-compat-matrix.ps1` repeats the run, and `.github/workflows/tests.yml` runs a representative subset on every push. The live sandbox tests were excluded. The skipped tests are a Unix file-permission test (skips on Windows) and, on PHP 8.1 only, a stack-trace test for `#[SensitiveParameter]`, which PHP honours from 8.2. Real SDK signing and validation ran in every one of these runs, using the bundled SDK 3.4.8.

**PHPUnit:** the package allows PHPUnit 9 to 12. Composer picks the version from your Laravel and PHP versions: Laravel 9 gets 9, Laravel 10 gets 10, Laravel 11 gets 11, and Laravel 12 gets 12 on PHP 8.3+ or 11 on PHP 8.2. The tests carry both PHP attributes (`#[DataProvider]`, `#[Group]`, read by PHPUnit 10+) and the matching docblock tags (`@dataProvider`, `@group`, read by PHPUnit 9). Keep both when you add a test, or Laravel 9 runs lose their data providers.

**Dependency notes:**
- `doctrine/dbal` stays at `^3.6`. Allowing DBAL 4 lets it install on Laravel 9, which then fails with a fatal error. Laravel 10 blocks DBAL 4 itself, and Laravel 11+ no longer use DBAL for column changes.

## Not covered by this table (unverified)

**Certificate renewal** is a separate, explicitly-flagged exception to "verified end-to-end" above: `ZatcaConnectorInterface::renewProductionCsid()` is implemented against the `/production/csids` endpoint only because it's the sole production-cert-issuing endpoint this package has verified ZATCA documentation for — the actual renewal contract (endpoint, OTP requirement, response shape) has not been confirmed against an official ZATCA document. `config('zatca.certificate.auto_renewal_enabled')` is `false` by default for exactly this reason. Do not treat renewal as "supported" until this note is removed and a row/callout here documents the verified contract.

## Adding a new ZATCA version combination

1. Add the new connector under `src/Services/Connectors/V{n}/` (RequestMapper + ResponseNormalizer), never modifying the existing version's files.
2. Add the new SDK output parser under `src/Services/SDK/OutputParsers/V{sdk_version_with_underscores}/` if the SDK's console output format changed, with golden-file fixtures under `tests/Fixtures/SdkOutput/V{sdk_version}/`.
3. Add the new XML generator under `src/Services/Invoice/XmlGenerators/V{ubl_profile}/` if the UBL profile changed.
4. Run the full contract + golden-file + `zatca:sdk-smoke-test` suite against the new versions before adding a row here.
5. Only then switch the relevant `.env` value in a host app — never change the default in `config/zatca.php` without a corresponding row in this table.

## Adding a new Laravel version

1. Widen the `illuminate/*` constraints in `composer.json`, and add the matching `orchestra/testbench` major.
2. Run the full suite in a clean install on that Laravel version and on the lowest and highest PHP version it allows.
3. Run `composer audit` on that install and record the security status.
4. Only then add a row to section 3 here and to the README's "Laravel compatibility" table.


## Legacy Laravel installation and security record

The following is the repository's recorded audit and installation guidance, moved from the server guide. It is historical evidence; run Composer's audit in your application for its current dependency status.

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
