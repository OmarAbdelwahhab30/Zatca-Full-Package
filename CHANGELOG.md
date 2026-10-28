# Changelog

All notable changes to this package are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - Unreleased

First tagged release. Set the date here when the tag is created.

### Added

- **Optional built-in dashboard** (`ZATCA_DASHBOARD_ENABLED=true`, off by default): overview, onboarding wizard, invoice history with ZATCA responses and XML download, and six one-click demo scenarios (B2C/B2B invoice, credit note, debit note). No authentication, so it is refused in production. Adds four `zatca_demo_*` tables only while enabled.
- **B2C (Simplified) documents, sent by Reporting:** invoices, credit notes and debit notes. The package calculates VAT, numbers and chains each document (ICV and PIH), builds the UBL XML, signs it with the ZATCA SDK, adds the QR code, stores it, and reports it to ZATCA from the queue.
- **B2B (Standard) documents, sent by Clearance:** invoices, credit notes and debit notes, with ZATCA's cleared copy stored. Off by default (`ZATCA_B2B_ENABLED=false`). See *Known limitations* in the README before turning it on.
- **Onboarding:** `zatca:generate-credentials` and `zatca:onboard` (compliance CSID, compliance test documents, production CSID) for a primary EGS and an optional separate B2B EGS.
- **Certificates:** expiry tracking, `ZatcaCertificateExpiring` events, and automatic pause and resume of reporting around an expired certificate.
- **Reliability:** retry budget and back-off, `zatca:report-pending` and `zatca:requeue` sweeps, `zatca:recover` for stuck documents, regeneration of rejected documents, and a global kill switch (`ZATCA_ENABLED`).
- **Operations:** `zatca:health-check`, `zatca:status` (also `--json` and `--strict`), `zatca:inspect`, `zatca:sdk-smoke-test`, and cleanup commands for old attempt rows and SDK temp files.
- **Scheduler monitoring:** `zatca:report-pending` records each run in the Laravel cache. `zatca:status` and `zatca:health-check` report a scheduler that has not run for `ZATCA_SCHEDULER_STALE_MINUTES` (default 30). No new table or service.
- **Reporting-deadline warning:** `zatca:status` warns about unreported B2C documents older than `ZATCA_REPORTING_DEADLINE_WARN_HOURS` (default 20) and fails past ZATCA's 24-hour limit.
- **Compatibility:** Laravel 9 to 12 and PHP 8.1 to 8.4, with ZATCA API `V2`, SDK `3.4.8` and UBL `2.1`. Laravel 12 is recommended; 9, 10 and 11 are legacy (see the README).
- `docs/run-compat-matrix.ps1` runs the whole suite on every supported Laravel and PHP combination, and a GitHub Actions workflow runs a representative set.

### Fixed

- **Expired-certificate guard.** Reporting and Clearance compared the certificate state against the wrong type, so the check never matched and documents were still submitted with an expired production certificate. They now stop before any request or attempt record and wait for renewal.

### Security

- Guzzle requirement raised to `^7.15.2`, the first release without the open Packagist advisories (including host and cookie handling). Older Guzzle releases also followed redirects with credentials attached.
- The ZATCA HTTP client no longer follows redirects. ZATCA's API never redirects these requests, and following one could send the CSID Basic-auth credentials to another address.
- The SDK adapter no longer leaves a copy of every signed invoice on disk. The signed document is returned in memory and stored in the database only.
- Invoice text (names, descriptions, reasons, buyer fields) is checked for valid UTF-8 and XML-legal characters before a counter number is used. Before, invalid UTF-8 was silently emptied in the XML and control characters made the document unparseable.
- Private keys, secrets, OTPs and credential arrays are marked `#[SensitiveParameter]`, so PHP 8.2 and later keep them out of stack traces. `ZatcaCredentialSet` hides its secret, key and token from `var_dump` and `print_r`.

### Changed

- `ZatcaSdkInterface::signInvoice()` no longer returns `signedXmlPath`. Use `signedXmlBase64`.
- `zatca:sdk-smoke-test` writes its own temporary copy of the signed XML for the validation step and deletes it afterwards.

See [UPGRADE.md](UPGRADE.md) for what to do when moving to 1.0.0.
