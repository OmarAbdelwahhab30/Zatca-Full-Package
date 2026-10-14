# ZATCA Package Compatibility Matrix

This file answers three questions:

1. Which ZATCA versions (API, SDK, UBL, XML profile) the package works with.
2. Which document flows the package supports, and **how far each one has been verified**.
3. Which Laravel and PHP versions the package runs on, and which of those still get security fixes.

Do not mix ZATCA versions outside the table in section 1. Do not treat a flow as production-verified unless section 2 says so.

## 1. ZATCA version combination

| Package version | `ZATCA_API_VERSION` | `ZATCA_SDK_VERSION` | `ZATCA_UBL_VERSION` | `ZATCA_XML_PROFILE_VERSION` | Notes |
|---|---|---|---|---|---|
| 1.0.x | `V2` | `3.4.8` | `2.1` | `V2` | Reporting model, Simplified Tax Invoice (B2C) only. Verified end-to-end (signing, validation and reporting) for that release. |
| Current development (not yet tagged) | `V2` | `3.4.8` | `2.1` | `V2` | Same ZATCA versions as 1.0.x. Adds the document flows in section 2. Tested SDK copy: [`SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/`](../SDKs/README.md) (`composer smoke` all OK, real-SDK tests passing, 2026-10-14). Golden-file fixtures for this SDK version live at `tests/Fixtures/SdkOutput/V3_4_8/` (SDK output) and `tests/Fixtures/Golden/V2/` (unsigned XML and snapshots). |

## 2. Document flows and how far each is verified

"Package tests" means the automated suite (`composer test`). It does not call ZATCA, and the 7 tests that drive the real Java SDK skip unless you point them at an SDK install (for example the copy in [`SDKs/`](../SDKs/README.md)). So "package tests" proves the package logic and the XML it builds, not that ZATCA accepts it.

| Flow | Submission | Status | Verified by |
|---|---|---|---|
| Simplified Tax Invoice (B2C) | Reporting | Supported | End-to-end for 1.0.x, plus package tests and golden XML fixtures |
| Simplified Credit Note / Debit Note (B2C), for real sales | Reporting | Supported | Package tests and golden XML fixtures. Onboarding (`zatca:onboard test-credit-note` / `test-debit-note`) submits compliance samples of both types. |
| Standard Tax Invoice (B2B) | Clearance | Built, **off by default** (`ZATCA_B2B_ENABLED=false`) | Package tests only. **Not yet checked against the real ZATCA sandbox** (gates G-02 to G-07 in [`zatca-b2b/gates.md`](zatca-b2b/gates.md)). |
| Standard Credit Note / Debit Note (B2B) | Clearance | Built, **off by default** | Package tests only. Not yet checked against the real ZATCA sandbox. |

Before turning B2B on in production, run the sandbox checks listed under "Known limitations" in the [README](../README.md#known-limitations).

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

Laravel 9–11 are kept so existing apps keep working while they plan an upgrade. Use Laravel 12 for new production projects. The README section [Laravel compatibility](../README.md#laravel-compatibility) lists the open advisories and explains how an app owner can override Composer's security block. This package never turns that block off for you.

**Test runs (2026-10-14, SQLite, clean install per Laravel version):**

| Laravel | Framework | Testbench | PHPUnit | PHP versions run | Result on each PHP version |
|---|---|---|---|---|---|
| 9 | 9.52.22 | 7.58 | 9.6 | 8.1, 8.3 | 834 passed, 8 skipped |
| 10 | 10.50.3 | 8.39 | 10.5 | 8.2, 8.3 | 834 passed, 8 skipped |
| 11 | 11.57.0 | 9.18 | 11.5 | 8.2, 8.3 | 834 passed, 8 skipped |
| 12 | 12.70.0 | 10.12 | 11.5 | 8.2, 8.3, 8.4 | 834 passed, 8 skipped |
| 12 | 12.70.0 | 10.12 | 12.5 | 8.3 | 834 passed, 8 skipped |

Every run gave 2,706 assertions and no PHPUnit warnings or deprecations. The 8 skipped tests are the 7 real-SDK tests plus a Unix file-permission test that skips on Windows, where these runs were made. Real SDK signing was **not** part of these runs. It was checked separately with the bundled SDK 3.4.8 on Laravel 10 / PHP 8.3: `composer smoke` all OK, and the full suite with the SDK enabled gave 834 tests, 2,720 assertions, 1 skipped. That includes `VatRealSdkValidationTest`: invoices whose VAT the package calculated (inclusive and exclusive, quantities, mixed rates, zero-rated, discounts) signed and checked with the SDK's own validator, with no amount-rule errors or warnings. Run `composer smoke` and the onboarding sandbox steps on your own setup before going live.

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
