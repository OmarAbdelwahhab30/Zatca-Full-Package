# Implementation log

One entry per phase, newest first. Fields: status, date, changed files, tests run (command and result), production steps, rollback, risks, open gates, approval.

## P45 — Buyer validation & normalisation

* **Status:** Done, awaiting review (2026-09-28). Unblocked by the G-01 facts F-SDK-04…F-SDK-10 read from the official SDK rule files.
* **Changed files (new):** `app/Services/Buyer/BuyerNormalizer.php`, `app/Rules/SaudiVatNumber.php` (BR-KSA-44), `app/Rules/SaudiCrNumber.php` (BR-KSA-F-08: 10 digits), `app/Rules/SaudiPostalCode.php` (BR-KSA-67), `app/Http/Requests/Concerns/BuyerPayloadRules.php`, `lang/{ar,en}/zatca_buyer.php`; test `tests/B2B/Buyer/BuyerValidationTest.php` (38).
* **Deviation (R-DOC):** the plan listed `SaudiBuildingNumber` (4 digits) and `SaudiAdditionalNumber`. No ZATCA rule for the *buyer's* building or additional number format was found (BR-KSA-37 is seller-only), so neither is enforced beyond "required" (building, BR-KSA-63) and length; blueprint BR-09's "4 digits" stays unverified under G-01.
* **Tests:** harness 160 OK. **Production steps:** none (no caller yet). **Rollback:** delete the files.

## P21 — Snapshot v3 (Standard)

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files:** `packages/zatca/src/Support/InvoiceDataSnapshot.php` (`VERSION_STANDARD = 3`, written only for Standard types, with `document_type`, `buyer`, `supply_date` and the note fields; `toArray()` refuses a Standard document without a buyer; `fromArray()` requires version and type to agree — v2 Simplified notes only, v3 Standard only). Tests: `packages/zatca/tests/Unit/Golden/StandardSnapshotTest.php` (new); `SnapshotGoldenTest` version test updated on purpose (v3 holding Simplified data, v4 and v0 refused).
* **B2C:** v1/v2 bytes unchanged (P05 golden fixtures green).
* **Tests:** golden group 81 OK; package 612 OK, 1 skipped; harness 122 OK.
* **Rollback:** revert (no v3 row can exist yet — generation still refuses Standard types).

## P20 — `BuyerParty` DTOs & `ZatcaInvoiceData` extension

* **Status:** Done, awaiting review (2026-09-28). Gate: the scheme list is closed from BR-KSA-14 (F-SDK-04).
* **Changed files:** `packages/zatca/src/DTOs/BuyerParty.php`, `BuyerAddress.php`, `BuyerOtherId.php` (new; scheme allow-list from BR-KSA-14); `packages/zatca/src/DTOs/ZatcaInvoiceData.php` (trailing optional `buyer`, `supplyDate`; `withBuyer()`, `withSupplyDate()`). Test: `packages/zatca/tests/Unit/Golden/BuyerPartyTest.php` (25) — every golden Simplified document is byte-identical, and its snapshot identical, with a buyer and supply date attached.
* **Deviation:** no separate `crNumber` on `BuyerParty` — the CR travels as `BuyerOtherId('CRN', …)`, which is how it appears in the XML (BT-46); the display copy lives with the buyer record (P44).
* **Tests:** package 598 OK, 1 skipped; harness 122 OK. **Rollback:** revert.

## P19 — Cross-EGS source uniqueness

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files:** `packages/zatca/database/migrations/2026_09_28_000002_add_env_source_unique_to_zatca_invoices_table.php` (unique `zatca_inv_env_src_uq` on `(environment, source_reference)`; refuses to run while duplicates exist); `packages/zatca/src/Support/SourceUniqueness.php`, `src/Console/Commands/CheckSourceUniqueness.php` (`zatca:check-source-uniqueness`, read-only), `src/Exceptions/CrossEgsDuplicateException.php` (new); `src/Services/Execute/ProductionInvoiceOrchestrator.php` (guard before credentials/ICV in `reserveDraft()` and `reserveAdjustmentNoteDraft()`, and after a lost unique race); `src/Jobs/GenerateZatca{Invoice,CreditNote,DebitNote}Job.php` (the exception fails the job without retries, like missing credentials); `src/ZatcaServiceProvider.php` (command). Test: `packages/zatca/tests/Feature/CrossEgsUniquenessTest.php` (7).
* **B2C:** with one EGS the guard never fires and the index rejects nothing old code can produce; same-EGS idempotency unchanged (tested).
* **Tests:** package 573 OK, 1 skipped; harness 117 OK.
* **Production steps (R1):** run `php artisan zatca:check-source-uniqueness` on production (or a copy) before deploying — it must print `[OK]`. **Rollback:** drop the index (only before B2B rows).

## P18 — `zatca_invoices` additive columns (M-P1)

* **Status:** Done, awaiting review (2026-09-28). G-24 (rollback-floor ops sign-off) stays **open**: it gates the R1 production deploy, not the code.
* **Changed files:** `packages/zatca/database/migrations/2026_09_28_000001_add_b2b_columns_to_zatca_invoices_table.php` (`submission_model` default `reporting`, `clearance_status`, `cleared_xml_base64`, `cleared_invoice_hash`, `cleared_at`, `submission_claimed_at`, `in_doubt_since`, `reissue_reason`, `reissued_by_type/id`, index `zatca_inv_model_status_idx`; appended without `after()` so MySQL can add them instantly; `down()` refuses once a clearance row exists); `packages/zatca/src/Models/ZatcaInvoice.php` (datetime casts; `submission_model` accessor: null → Reporting, unknown value → error). Test: `packages/zatca/tests/Feature/Characterization/B2bColumnsMigrationTest.php` (7).
* **Tests:** package 566 OK, 1 skipped; harness 117 OK.
* **Production steps (R1):** `php artisan migrate --force`; time the ALTER on a staging copy of `zatca_invoices` first. **Rollback:** `down()` before any clearance row; afterwards forbidden (rollback floor).

## P12 — Alert listeners for existing ZATCA events

* **Status:** Done, awaiting review (2026-09-28). Precondition "ops agrees recipients" not met, so the alerts ship **off** (`ZATCA_ALERTS_ENABLED`, fail-closed) and the listeners are not even queued while off — B2C production unchanged.
* **Changed files:** `app/Listeners/Zatca/AlertsAdmins.php`, `AlertOnZatcaInvoiceNeedsAttention.php`, `AlertOnZatcaCertificateExpiring.php` (new; queued, `tries=3`, one alert per document / certificate state per 24 h, admin notification to every admin with `ZATCA_ALERTS_ROLE` (default "Super Admin", guard admin) plus a Sentry message, identifiers only in the payload); `app/Providers/EventServiceProvider.php` (TP-22); `config/zatca_admin.php` (`alerts`); `.env.example`. Test: `tests/B2B/Alerts/ZatcaAlertListenersTest.php` (5).
* **Tests:** harness 122 OK. **To switch on:** set the role and `ZATCA_ALERTS_ENABLED=true`, then `php artisan optimize:clear` and `queue:restart`. **Rollback:** unset the flag (or unregister the listeners).

## P15 — Business-level tax policy resolution

* **Status:** Done, awaiting review (2026-09-28). User's answer: the existing admin setting decides inclusive/exclusive — read as approval (the settings screen is unchanged).
* **Changed files:** `app/Services/Admin/ZatcaSettingService.php` — `priceTaxPolicy($egs)` reads the primary EGS's row for any configured EGS (`policyOwner()`); unknown identifiers keep their own lookup; `updatePriceTaxPolicy()` and `VatCalculator` unchanged. Test: `tests/B2B/Characterization/Host/VatAndSettingsTest.php` — the P07 test pinning L-11 was replaced on purpose by `test_a_second_egs_follows_the_admin_policy` and a 2 009-price equality property (b2b split = primary split, both policies).
* **Tests:** harness 117 OK; package 559 OK, 1 skipped.
* **Production steps:** none. **Rollback:** revert the method. **Risks:** none for the primary EGS (same row, same result).

## P14 — B2B EGS configuration composition & primary-EGS invariant

* **Status:** Done, awaiting review (2026-09-28). Approved by the user.
* **Changed files:** `packages/zatca/config/zatca.php` (the primary entry moved unchanged into `$zatcaPrimaryEgsConfig`; new `primary_egs`; optional B2B entry composed from it when `ZATCA_B2B_EGS_IDENTIFIER` is set and differs from the primary — seller fields inherited, only environment, unit name, CSR common name/serial and invoice type are its own, unset ones stay null; `b2b.egs_identifier`); `packages/zatca/src/State/EgsRegistry.php` (`primary()`, falls back to `default()` when the key is absent or unknown); `packages/zatca/src/Console/Commands/ZatcaHealthCheck.php` (seller-identity drift across EGS → FAIL; B2B id equal to the primary → FAIL; unset B2B CSR fields → PENDING; environment mismatch → WARN); `app/Services/Admin/ZatcaSettingService.php` (`egsIdentifier()` = primary); `app/Zatca/InvoiceSellerProfile.php` (fallback = primary); `.env.example` (B2B EGS block, empty). Tests: `packages/zatca/tests/Unit/EgsCompositionTest.php` (new, 10); harness `VatAndSettingsTest` (order test replaced on purpose, seller-profile fallback added).
* **Invariant:** without `ZATCA_B2B_EGS_IDENTIFIER` the registry is exactly today's single entry, same keys in the same order (pinned).
* **Tests:** package 559 OK, 1 skipped (real SDK R3.4.8); harness 117 OK.
* **Production steps:** none (env unset). After deploy: `php artisan optimize:clear`. **Rollback:** unset the env vars; revert.
* **Risks:** a stale config cache keeps the old shape — `primary()` falls back to the first key, which is the primary today.

## P13 — Flag semantics & gates

* **Status:** Done, awaiting review (2026-09-28). Approved by the user on 2026-09-28; D-19 (drain) approved the same day, G-12 closed.
* **Changed files:** `packages/zatca/config/zatca.php` (TP-01: `b2b.enabled` ← `ZATCA_B2B_ENABLED`, default false, carried raw); `packages/zatca/src/Support/ZatcaGate.php` (TP-02, additive: `b2bEnabled()`, `b2bDisabled()`, `skipB2b()`, static `isExplicitlyOn()`; `enabled()` untouched); `packages/zatca/src/Support/B2bReadiness.php` (new skeleton, always false until P40); `config/services.php` (TP-52: `wathq.enabled` ← `WATHQ_ENABLED`, default false); `app/Support/WathqGate.php` (new); `.env.example` (TP-49: documented block, both flags false). Tests: `packages/zatca/tests/Unit/ZatcaGateB2bTest.php`, `tests/B2B/Flags/WathqGateTest.php` (new).
* **Semantics:** B2B = `enabled()` AND explicit on; WATHQ = B2B AND explicit on. "Explicit on" is `true`, `1`, or the strings `true`/`1`/`on`/`yes` (trimmed, any case); null, empty, `false`, `0`, `off`, `no`, `yes please`, `2`, `1.0` and arrays are off. A config (published or cached) without the new key means off. The fail-closed rule is the opposite of `enabled()`'s fail-open rule on purpose, and both docblocks say why. D-19 is written into the config comment and `b2bEnabled()`'s docblock: the switch gates new work only; the clearance sweep (P37) will ask `enabled()`.
* **ZATCA requirements encoded:** none (R-DOC not applicable).
* **Tests:** `ZatcaGateB2bTest` 29 OK; `ZatcaKillSwitchTest` 11 OK (unchanged behaviour of `enabled()`); harness `WathqGateTest` 17 OK (8-row truth table, fail-closed parsing, missing key, defaults read from the config files with the variables unset); full package suite with the real SDK R3.4.8: 549 OK, 1 skipped; whole harness 115 OK.
* **Production steps:** none required — both flags default off and nothing reads them yet. When deploying: `php artisan optimize:clear` so the cached config gains the keys (absent keys already mean off).
* **Rollback:** revert the files (no callers).
* **Risks:** none at runtime. `.env` files on servers do not need the new lines; add them only to document intent.

## P08 — Host characterization: email, local invoices, PDFs, recovery

* **Status:** Done, awaiting review (2026-09-28). Test-only.
* **Changed files (new):** `tests/B2B/Characterization/Delivery/EmailAndLocalInvoiceTest.php`, `InvoiceHtmlSnapshotTest.php`, `RecoverCommandTest.php`; fixtures `tests/B2B/Fixtures/Delivery/{reported_two_lines,pending_reporting,no_zatca_document,coupon_and_bundle}.html`.
* **What it pins:** payment email — one email per payment through the atomic claim; the claim is consumed even when the email is skipped (missing address, no invoice) and is never retried; locale follows the student's language. Transaction email — **L-04** (no claim: every call sends again); online, unpaid, inactive and zero-value rows are skipped. Local invoice — `INV-{payment id, 6 digits}` / `INV-TX-{transaction id}`, amount, currency, issue time, courses snapshot (Arabic title first, bundle rows at their original price), idempotency, ZATCA link at creation (payment) and on later calls (transaction), no paid rows → exception. Student PDF download — linked document first, then `payment:`/`transaction:` source reference; **L-07** (a superseded document is rendered, not its replacement); another student's invoice → 403, unknown → 404. Invoice HTML (`pdf.post_purchase_invoice`, before mPDF) for four documents — reported with two signed lines, pending reporting, no ZATCA document, coupon + bundle — plus RTL by default / LTR only when English is passed, and deterministic QR rendering. `zatca:recover` — the 14 legacy scenarios (CLI validation, full pipeline, resuming a stalled draft with the same UUID/ICV, no duplicates on re-run, reported never resubmitted, reporting retried with the same signed document, dry run, lock, failures during signing and reporting, transaction → payment resolution).
* **Fixtures:** fictitious data only — seller values are set in the test (ZATCA's sample VAT number 399999999900003 and invented CR/address), not read from `.env`; a scan of the fixtures against every `.env` value found only the public sample VAT number and a 4-letter word inside `student@example.test`. Rewrite with `B2B_SNAPSHOT_UPDATE=1` after an intended change.
* **Port of the legacy recovery test:** `tests/Feature/RecoverZatcaInvoiceCommandTest.php` (never runnable, L-17) ported with three differences, each commented in the file: ZATCA switched on in `setUp()`; courses built by the harness builder (HT-18); the resolved ZATCA singletons (`ProductionInvoiceOrchestrator`, `ConnectorFactory`) are forgotten when the test swaps the SDK or connector — without that the legacy test could not pass on a fresh container. One expectation follows current behaviour instead of the legacy one (HT-20).
* **Tests:** `php vendor/bin/phpunit -c phpunit.b2b.xml` → 98 tests OK (whole harness). The legacy suite was not run.
* **Findings (recorded, not fixed):** HT-20 — `zatca:recover` exits 0 when reporting leaves the invoice Retrying; HT-21 (low) — a ZATCA document created after the local payment invoice is never linked to it.
* **Production steps:** none. **Rollback:** delete the new files.
* **Risks:** the HTML fixtures change with any template edit, by design; review fixture diffs line by line.

## P07 — Host characterization: sources, VAT, dispatch branches, callbacks

* **Status:** Done with limitations, awaiting review (2026-09-28). Test-only.
* **Changed files (new):** `tests/B2B/Characterization/Host/InvoiceSourcesTest.php`, `VatAndSettingsTest.php`, `DispatchBranchesTest.php`; `phpunit.b2b.xml` now also pins `ZATCA_EGS_IDENTIFIER=default` and `ZATCA_VAT_PERCENTAGE=15` (other `ZATCA_*` values are still read from `.env`, read-only).
* **What it pins:** `PaymentInvoiceSource` — line order by transaction id, description `title_ar` → `title` → `Course #id`, inclusive split per line, totals summed from rounded lines, buyer = username, no buyer VAT, issue date = `payments.created`, `payment:{id}`, one-cent-per-line drift pushed onto the largest line, larger drift throws (no invoice), exclusive policy ignores the charge, bundle lines at original price with one `Bundle Discount` allowance per bundle (`bundle:{id}`, line indexes), no paid rows throws; `TransactionInvoiceSource` — one line, `transaction:{id}`, date = `created`; the router is the `InvoiceSourceContract` binding and dispatches on class. `VatCalculator` split table (inclusive/exclusive), rate from config, `splitAtRate`; **L-11** (a second EGS falls back to `exclusive` → VAT on VAT); `ZatcaSettingService` policy defaults (inclusive row from the data migration, `exclusive` for unknown EGS), `egsIdentifier()` = first configured key, policy update writes the current EGS row. Listener — one synchronous job per eligible payment with (`Payment`, id, `default`), local invoice built with ZATCA on or off, extensions / zero value / no paid rows produce neither, a ZATCA failure is logged and swallowed, unknown payment logged. `TransactionService::createTransactions` — new paid assignment (job, local invoice, email job, cart cleaned), ZATCA off (invoice + email, no job), unpaid and zero-value assignments (nothing dispatched, cart still cleaned), settling an unpaid row (same row, one job), existing enrolment → round change only, no default round → exception, unknown course skipped.
* **Tests:** `php vendor/bin/phpunit -c phpunit.b2b.xml` → all green (host characterization 37 tests).
* **Limitations:** the Tabby and Tamara callback views and the Apple Pay callback are **not** covered automatically: the views call `die()` on any exception (it would end the test process) and Apple Pay verifies through raw `curl`. They stay on the manual end-to-end list (E2E-B01…B03). Races L-02/L-03 are not demonstrated (SQLite only, G-11).
* **Finding (recorded, not fixed):** **HT-19 — security, high:** the Tabby/Tamara success callbacks are public and fulfil the order without verifying the payment with the gateway (details in blueprint Appendix D). Suggested to the user as a separate task. It must be fixed before B2B go-live, because a forged company sale would be *cleared* with ZATCA.
* **Production steps:** none. **Rollback:** delete the three test files; revert the two `phpunit.b2b.xml` lines.
* **Risks:** the tests read seller data from `.env`; none of them asserts it.

## P17 — Generalise document-kind comparisons

* **Status:** Done, awaiting review (2026-09-28). Approved to start by the user on 2026-09-28.
* **Inventory** (`grep` for `ZatcaComplianceDocumentType::`, the `simplified_*` strings, `isCreditNote()`/`isDebitNote()` and `document_type` in `packages/zatca/src` and `app`), each call site classified:

| Call site | Means | Action |
|---|---|---|
| `Models/ZatcaInvoice::isCreditNote()` / `isDebitNote()` | kind (381/383) | **changed** to `isCreditNoteKind()`/`isDebitNoteKind()` (null-safe as before) |
| `Models/ZatcaInvoice::isAdjustmentNote()` | kind | already `requiresBillingReference()` (P16 keeps it kind) |
| `app/Services/Admin/AdminZatcaInvoiceService.php` :175/:183 (`invoiceIssuedAtFor`), :850 (`updateXmlAndResubmit` refuses credit notes) | kind | unchanged code; now covers Standard notes through the model helpers |
| `OnBoarding/ComplianceOnboardingOrchestrator::requireInvoiceReference()` | subtype of the note | **changed**: takes the note type and looks up the compliance invoice of the same subtype; the refusal text uses the type label (identical for Simplified) |
| `ComplianceOnboardingOrchestrator` :113/:128/:138 (test invoice/CN/DN) | subtype (Simplified onboarding steps) | unchanged; Standard steps are P26 |
| `CreditNote/CreditNoteEligibilityPolicy.php:46`, `DebitNote/DebitNoteEligibilityPolicy.php:52` | subtype **today** | **not changed — moved to P41** (see deviation) |
| `CreditNoteDataFactory.php:188`, `DebitNoteDataFactory.php:94` | subtype (the note produced) | unchanged; P41 |
| `Invoice/XmlGeneratorFactory` arms | subtype (routing) | unchanged; P25 |
| `DTOs/ZatcaInvoiceData` default `Invoice`; `Support/InvoiceDataSnapshot` v1 default | subtype (backward-compatible defaults) | unchanged; v3 is P21 |
| `Invoice/InvoiceDataValidator` (`requiresBillingReference()`, `label()`) | kind | already kind |
| `CreditNoteLedger`/`CreditNoteLedgerSnapshot`/`AdminCreditNoteService` `'document_type' => 'credit_note'…` | display labels, not the enum | not a comparison |

* **Deviation from the plan text (conservative):** P17 was to switch both note-eligibility policies to `isInvoiceKind()`. Until P41 the note factories hard-code Simplified notes, so a widened policy would offer a Simplified credit/debit note against a Standard invoice. The policies stay Simplified-only (pinned by a test) and the switch moves to P41, which already lists those files; the blueprint (P17, P41) is updated.
* **Changed files:** `packages/zatca/src/Models/ZatcaInvoice.php`; `packages/zatca/src/Services/OnBoarding/ComplianceOnboardingOrchestrator.php`. Tests: `packages/zatca/tests/Feature/Characterization/DocumentKindCallSitesTest.php` (new), `packages/zatca/tests/Feature/ComplianceOnboardingOrchestratorNoteTest.php` (2 tests added), `tests/B2B/Characterization/AdminDocumentKindTest.php` (new, harness), `tests/B2B/Guards/DocumentKindComparisonGuardTest.php` (new — the grep guard: fails on a raw `===`/`!==` against a Simplified type or a `simplified_*` literal in `app` or `packages/zatca/src` outside a reasoned allow-list, and on a stale allow-list entry).
* **Tests:** package `DocumentKindCallSitesTest` 9 OK, `ComplianceOnboardingOrchestratorNoteTest` 6 OK; full package suite with the real SDK R3.4.8: 520 tests OK, 1 skipped (same as before); harness `phpunit.b2b.xml` 22 tests OK. The legacy host suite was not run.
* **Production steps:** none. Behaviour for every existing (Simplified) row is identical. **Rollback:** revert the two source files and delete the new tests.
* **Risks:** the grep guard is text-based (it would miss a comparison built dynamically, e.g. `in_array($type, [...])`); the admin UI still labels documents by raw type until P66.

## P03 — Isolated host test harness

* **Status:** Done, awaiting review (2026-09-28). G-11 decided by the user on 2026-09-28: SQLite only; with this harness working, G-11 is closed.
* **Changed files (all new, test infrastructure only):** `phpunit.b2b.xml`; `tests/B2B/bootstrap.php`; `tests/B2B/TestCase.php`; `tests/B2B/Support/HarnessGuard.php`; `tests/B2B/Support/SchemaBuilder.php`; `tests/B2B/Support/Builders/BuildsHostRecords.php`; `tests/B2B/Smoke/HarnessSmokeTest.php`; `.github/workflows/b2b_host_tests.yml`.
* **How it works:** every test gets a fresh in-memory SQLite database. The legacy tables exist only in the tracked dump `lms_db.sql` (no create migrations — the reason the legacy suite fails on `advexams`), so `SchemaBuilder` translates every `CREATE TABLE` in the dump (158 tables, with primary keys, auto-increment and unique keys; plain indexes and foreign keys skipped), records the dump's own `migrations` rows, and `migrate` then applies only the migrations the dump predates (host and ZATCA package). One migration is MySQL-only (`2026_08_26_000002_upgrade_notifications_table_for_admins`, raw `ALTER … MODIFY`) and is emulated. Only DDL and migration names are read from the dump; its data rows are never parsed.
* **Isolation:** `HarnessGuard` refuses to start unless `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` in `$_SERVER`, `$_ENV` and `getenv`, and `APP_CONFIG_CACHE` points to a file that does not exist (so a cached config cannot swap the connection) — checked in the bootstrap and again in `createApplication()` before the app exists; after boot it re-checks the resolved default connection and replaces every other connection (`mysql`, `pgsql`, `sqlsrv`) with a non-existent driver. `Http::preventStrayRequests()` is on for every test. `tests/B2B` is not in the legacy `phpunit.xml` suites. Tests that need real locks call `skipOnSqlite()` (G-11).
* **Tests:** `php vendor/bin/phpunit -c phpunit.b2b.xml` → 13 tests OK (about 12 s): in-memory driver; every other connection refused; guard refuses a MySQL default, a MySQL/file/dev-schema environment and a permitted config cache; every table queryable (> 150, incl. students, courses, rounds, payments, transactions, invoices, zatca_*); only the curated migrations skipped; builders; stray HTTP refused; faked HTTP works. The legacy suite was not run (governance rule 6).
* **Finding (recorded, not fixed):** HT-18 — `App\Models\Course::__construct()` takes no arguments, so `Course::factory()->create([...])` drops every attribute. The harness builder fills courses explicitly.
* **Not verified:** the CI workflow has not run (it needs a push); the host has no committed `composer.lock`, so CI resolves dependencies from `composer.json`.
* **Production steps:** none. **Rollback:** delete the files above.
* **Risks:** the dump is the schema baseline — a table changed on production outside migrations would differ here; the schema is only as current as `lms_db.sql` plus the migrations. No automated test can prove row-lock behaviour (G-11 decision; ADR-24).

## P16 — Document type & submission-model vocabulary (dormant enum release)

* **Status:** Done, awaiting review (2026-09-28). Approved to start by the user on 2026-09-28.
* **Changed files:**
  * `packages/zatca/src/Enums/ZatcaComplianceDocumentType.php` — cases `StandardInvoice`/`StandardCreditNote`/`StandardDebitNote` (`standard_*`); helpers `isInvoiceKind()`, `isCreditNoteKind()`, `isDebitNoteKind()`, `isStandard()`, `submissionModel()`, `transactionCodeName()`; `invoiceTypeCode()`, `requiresBillingReference()`, `label()` extended. Every Simplified answer is unchanged (pinned by a table test).
  * `packages/zatca/src/Enums/ZatcaSubmissionModel.php` (new) — `reporting`, `clearance`.
  * `packages/zatca/src/Enums/ZatcaInvoiceStatus.php` — 7 clearance cases (`pending_clearance`, `clearance_retrying`, `clearance_in_doubt`, `cleared`, `cleared_with_warnings`, `clearance_rejected`, `clearance_manual_review`); `isTerminalSuccess()` also true for `cleared`/`cleared_with_warnings`; `isRetryable()` unchanged; new `isClearanceState()`, `isClearanceRetryable()`.
  * `packages/zatca/src/Services/Invoice/XmlGeneratorFactory.php` — the three Standard arms throw `ZatcaException` (until P25).
  * `database/seeders/ZatcaInvoicesDemoSeeder.php` — the per-status demo loop skips clearance statuses (it would otherwise write them onto Simplified demo rows — P16 forbids anything writing the new values).
  * Tests: `packages/zatca/tests/Unit/DocumentVocabularyTest.php` (new), `packages/zatca/tests/Feature/Characterization/VocabularyHydrationTest.php` (new); P06 `ContractInventoryCharacterizationTest` (vocabulary and whitelist expectations extended — the eight reporting statuses and their sets are asserted unchanged) and `ReportingOutcomeCharacterizationTest` (the "any non-terminal status is sent" table is limited to reporting statuses; the clearance guard of P36 will change it for clearance statuses).
* **ZATCA requirements encoded (R-DOC):** KSA-2 positions 1–2 = `01` tax invoice / `02` simplified — official KSA schematron rule BR-KSA-06 (F-SDK-01); 388/381/383 shared by both subtypes — BR-KSA-05 (F-SDK-02) and the official SDK Standard samples (F-UP-07, example).
* **Call sites checked:** no code enumerates document types (onboarding names its three Simplified types explicitly, so it never submits a Standard type); `zatca:requeue --status` accepts only its whitelist; `ListZatcaInvoicesRequest` now accepts the new statuses as filter values (harmless: no rows); the only exhaustive `match` on the enums outside the enum itself is `XmlGeneratorFactory::make()`.
* **Tests:** `DocumentVocabularyTest` 29 OK; characterization group 38 OK; full package suite with the real SDK R3.4.8: 509 tests OK, 1 skipped (the same skipped test as before, not investigated per instruction).
* **Production steps:** none (no row can carry the new values; nothing writes them).
* **Rollback:** revert the files — allowed only while no row uses a new value (true until the B2B lane writes one; afterwards forbidden, blueprint rollback floor).
* **Risks:** `isTerminalSuccess()` now includes the cleared states; every caller (credit/debit eligibility, regeneration, requeue, recovery, admin service) treats that as "ZATCA accepted it", which is the intended meaning for Standard documents but should be confirmed per caller in P17. A Standard row reaching `ReportZatcaInvoiceJob` would still be sent as reporting until the guard in P36 — impossible today because no Standard row can be generated (the factory refuses).

## P06 — Package pipeline characterization

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files (all new, tests only):** `packages/zatca/tests/Feature/Characterization/CreatesInvoiceRows.php`, `ReportingOutcomeCharacterizationTest.php`, `SelectionCharacterizationTest.php`, `ContractInventoryCharacterizationTest.php`.
* **What it pins:** the status vocabulary and the `isTerminalSuccess`/`isRetryable` sets; the full reporting outcome table through the real HttpConnector with only the network mocked (200/202/400/401/409 confirmed and unconfirmed/500 below and at the budget of 12); the exact reporting request (path, `Clearance-Status: 0`, `Accept-Version: V2`, Basic auth, body keys); ConnectException escaping the job with nothing recorded (L-01); request errors without a response recorded as HTTP 0; unknown 2xx bodies throwing; terminal rows never re-sent; `zatca:report-pending` selection; `zatca:requeue --id` exclusions; post-renewal resume selection per EGS and environment; `EgsRegistry::default()` = first key; per-EGS ICV/PIH isolation; job connection/queue; and the inventory of the 10 files that dispatch ZATCA jobs. RegenerationPolicy, DraftCompletionPolicy and the kill switch already had their own tests and were not duplicated.
* **ZATCA requirements encoded:** none (R-DOC not applicable).
* **Tests:** characterization group 36 tests OK; full package suite 478 tests OK, 3 skipped (SDK tests, SDK variables not set in this run).
* **Findings (recorded, not fixed):** HT-16 — `zatca:report-pending` does not exclude superseded, claimed or unsigned rows and never selects Failed; HT-17 — `report()` sends any non-terminal row it is handed, and its retry fallback (4) differs from the configured default (12). See blueprint Appendix D.
* **Production steps:** none. **Rollback:** delete the four files.
* **Risks:** the dispatch inventory is a source scan; it fails on any added/removed dispatch site by design, which will be noisy when B2B routing lands (update it together with the touchpoint checklist).

## P05 — Golden snapshot & data-contract baseline

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files:** `packages/zatca/tests/Unit/Golden/GoldenDocuments.php` (new — the shared golden inputs, moved out of the P04 test so both suites describe the same documents); `packages/zatca/tests/Unit/Golden/SnapshotGoldenTest.php` (new); `packages/zatca/tests/Unit/Golden/SimplifiedXmlGoldenTest.php` (now uses the shared inputs; its fixtures are unchanged); `packages/zatca/tests/Fixtures/Golden/V2/snapshots/*.json` (9 new).
* **What it pins:** the exact v1 (invoice) and v2 (note) snapshot arrays; round trip `toArray(fromArray(x)) === x`; a snapshot stored through the Eloquent `array` cast regenerates the golden XML byte for byte; snapshot version 3 is refused before P21; the constructor parameters of `ZatcaInvoiceData`, `ZatcaInvoiceLine` and `ZatcaAllowanceCharge` (names, order, types, optionality) that `app/Zatca/*InvoiceSource` rely on, and that anything added later is optional.
* **ZATCA requirements encoded:** none (R-DOC not applicable).
* **Tests:** golden group 42 tests OK; full package suite with the real SDK R3.4.8 (`ZATCA_SDK_EXECUTABLE`/`ZATCA_SDK_ROOT` set) 442 tests OK, 1 skipped (not investigated, per instruction); the SDK folder was byte-for-byte unchanged afterwards.
* **Production steps:** none. **Rollback:** delete the new files; restore the P04 test.
* **Risks:** the snapshot fixtures record issue instants in the Testbench timezone (UTC); a change of the package test timezone would show as a fixture diff, not a production change.

## P01 — Official specification acquisition & Verified Facts Register

* **Status:** Partly done (2026-09-28); stays open until the missing official documents arrive.
* **Changed files:** `docs/zatca-b2b/facts-register.md` (new).
* **Done:** read ZATCA's official Clearance API page (supplied by the user) and recorded F-CL-01…F-CL-13; imported the facts read during planning as F-UP-01…F-UP-08 (reviewer pending). Two discrepancies inside the official page are recorded, not resolved: `uuid` in the request example but not in the schema (F-CL-04); `erroMessages` in the schema but `errorMessages` in the examples (F-CL-09).
* **Gates:** G-03 documentary part closed; G-04, G-06, G-07, G-16 partly; G-01, G-02, G-09, G-17, G-18, G-22 open.
* **Tests:** none (documentation only).
* **Production steps:** none. **Rollback:** delete the file.
* **Risks:** an entry used before review; the page can change without notice (the source row records its "last updated" date).
* **Blocked on:** XML Implementation Standard + Data Dictionary, Detailed Technical Guidelines, Developer Portal User Manual, clearance switch-off procedure, WATHQ API documentation.

## P02 — Living implementation log, ADR log and change-control rules

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files:** `docs/zatca-b2b/**` (new). No code, configuration or schema. The repository-wide PR template is not changed; the review checklist is [`touchpoint-checklist.md`](touchpoint-checklist.md).
* **Tests:** none required (documentation only). The package suite was not affected.
* **Production steps:** none.
* **Rollback:** delete `docs/zatca-b2b/`.
* **Risks:** documentation drift — the blueprint copy is frozen; `gates.md`, `decisions.md` and this log are the living registers.
* **Open gates:** none for this phase.

## P04 — Golden XML baseline for Simplified documents

* **Status:** Done, awaiting review (2026-09-28).
* **Changed files:** `packages/zatca/tests/Unit/Golden/SimplifiedXmlGoldenTest.php` (new); `packages/zatca/tests/Fixtures/Golden/V2/simplified/*.xml` (9 new fixtures); `packages/zatca/composer.json` (`golden:update` script); `packages/zatca/README.md` (fixture section).
* **ZATCA requirements encoded:** none — the fixtures record current output and claim no compliance (R-DOC not applicable).
* **Tests:** `php vendor/bin/phpunit --group golden` → 10 tests OK; full package suite → 410 tests OK, 3 skipped (SDK-backed, unchanged from the baseline of 400 tests).
* **Not done:** the planned one-character mutation of a generator to prove sensitivity was blocked by the environment's permission policy (editing production source, even temporarily). The comparison is an exact whole-document `assertSame`.
* **Production steps:** none.
* **Rollback:** delete the test and fixtures; revert the two package files.
* **Risks:** CRLF→LF is the only normalisation (Windows checkouts turn the signature heredoc into CRLF; servers emit LF). Fixtures can be rewritten with `composer golden:update`, so fixture diffs need line-by-line review.

## P00 — Stakeholder decision workshop & access provisioning

* **Status:** In progress. Approved on 2026-09-28: D-26, D-38, D-39, D-40, D-41, D-42, D-43, P02 location, R-DOC rule; later the same day D-19 (drain), G-11/D-24 (SQLite only) and the start of P16/P17/P25. Open: D-44 and the remaining decisions in [`decisions.md`](decisions.md); all access requests (ZATCA OTP for a second EGS, WATHQ, gateway sandboxes).
