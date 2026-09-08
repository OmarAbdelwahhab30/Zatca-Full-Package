# ZATCA Package — Problems & Troubleshooting Guide (Q&A for AI & Developers)

This guide provides a comprehensive, Question & Answer (Q&A) reference for AI assistants (Claude, ChatGPT, Antigravity, etc.) and developers troubleshooting production, staging, and development issues with the `zatca/zatca` package.

---

## Table of Contents
1. [SDK & Java Environment Issues](#1-sdk--java-environment-issues)
2. [Certificate, Onboarding & CSID Issues](#2-certificate-onboarding--csid-issues)
3. [ZATCA API & Business Rejections (HTTP 400–499)](#3-zatca-api--business-rejections-http-400499)
4. [Server, Network & Queue Failures (HTTP 500–599 / Timeouts)](#4-server-network--queue-failures-http-500599--timeouts)
5. [Database, Sequence & Hash Chain Integrity](#5-database-sequence--hash-chain-integrity)
6. [Configuration & Environment Setup](#6-configuration--environment-setup)
7. [Emergency Playbook & AI Instructions](#7-emergency-playbook--ai-instructions)

---

## 1. SDK & Java Environment Issues

### Q1.1: I get `Command "zatca:status" is not defined`. What should the AI check?
* **Cause:** The `zatca/zatca` package is not registered by Laravel's autoloader or package discovery.
* **Diagnosis:** Check `composer.json` in the root app to ensure `zatca/zatca` is listed in `repositories` (as a path repo) and `require`.
* **Fix Command:**
  ```bash
  composer install
  php artisan package:discover
  ```

---

### Q1.2: I get `ZatcaSdkNotFoundException` or `ZATCA_SDK_EXECUTABLE ... does not point to an existing SDK jar`.
* **Cause:** The path configured in `.env` (`ZATCA_SDK_EXECUTABLE`) is invalid, points to a non-existent file, or has incorrect permissions.
* **Diagnosis:** Run `php artisan zatca:health-check` or inspect the `.env` value.
* **Fix:**
  1. Verify the absolute path to `fatoora-executable.jar` (or equivalent ZATCA SDK JAR).
  2. Update `.env`:
     ```env
     ZATCA_SDK_EXECUTABLE="/path/to/zatca-sdk/Apps/fatoora-executable.jar"
     ```
  3. Clear config cache:
     ```bash
     php artisan config:cache
     ```

---

### Q1.3: I get a Java error like `Java binary not found` or `Unsupported class file version`.
* **Cause:** Java (JRE/JDK) is missing from system `PATH` or the installed Java version is outside the supported range (**Java 11 to Java 15**).
* **Diagnosis:** Check system Java version:
  ```bash
  java -version
  ```
* **Fix:**
  1. Install JDK/JRE 11, 12, 13, 14, or 15.
  2. Ensure the `java` binary is available in the system `PATH` of the web server user (`www-data`, `nginx`, etc.) and queue workers.

---

### Q1.4: I get `ZatcaSdkFingerprintMismatchException`. Is this dangerous?
* **Cause:** The SHA-256 checksum of the ZATCA SDK `.jar` file does not match `ZATCA_SDK_FINGERPRINT` in `.env`.
* **Risk Level:** **HIGH / CRITICAL**.
* **AI Action Protocol:**
  1. **Do NOT update the fingerprint immediately.** Ask the user if they intentionally upgraded the ZATCA SDK JAR file.
  2. If the user **did NOT** upgrade it, **STOP**. The file may have been tampered with or corrupted.
  3. If the user **did** intentionally upgrade the SDK, calculate the new SHA-256 hash and update `.env`:
     ```env
     ZATCA_SDK_FINGERPRINT="new_calculated_sha256_hash_here"
     ```
     Then clear config cache: `php artisan config:cache`.

---

### Q1.5: I get `ZatcaSdkOutputChangedException`. What happened?
* **Cause:** The ZATCA Java SDK was updated by ZATCA and its CLI text output format changed, causing regex parsers in `Zatca\Services\SDK\OutputParsers\` to fail.
* **Fix:**
  1. Test the SDK directly with `php artisan zatca:sdk-smoke-test`.
  2. Update the package parser or add a parser version for the new SDK release.

---

## 2. Certificate, Onboarding & CSID Issues

### Q2.1: `php artisan zatca:status` shows `Certificate ... FAIL (EXPIRED)`. Are invoices lost?
* **Answer:** **No, no invoices are lost.**
* **Explanation:** When the production CSID certificate expires, `ReportZatcaInvoiceJob` deliberately **pauses** reporting. Invoices are still generated, signed, and saved safely in `zatca_invoices`. They wait as `pending_reporting`.
* **Fix Steps:**
  1. Obtain a new OTP from the ZATCA Fatoora Portal.
  2. Run the onboarding commands:
     ```bash
     php artisan zatca:onboard compliance --otp=XXXXXX
     php artisan zatca:onboard test-invoice
     php artisan zatca:onboard test-credit-note
     php artisan zatca:onboard test-debit-note
     php artisan zatca:onboard production
     ```
  3. Once `zatca:status` shows valid, push the waiting invoices:
     ```bash
     php artisan zatca:report-pending
     ```

---

### Q2.2: `php artisan zatca:status` shows `Certificate ... WARNING (Expires in X days)`. How to renew?
* **Cause:** Production certificates expire after 1 year (or 2 years depending on CSID type).
* **Important Note:** Automatic renewal is **DISABLED** by default (`ZATCA_AUTO_RENEWAL_ENABLED=false`) because manual OTP input from the Fatoora Portal is required.
* **Fix:** Obtain a fresh OTP from the Fatoora portal and run the onboarding commands shown in Q2.1.

---

### Q2.1a: `zatca:onboard production` fails with `The compliance certificate is not done with the following compliance steps yet [simplified-credit-note-compliant,simplified-debit-note-compliant]`.
* **Cause:** ZATCA's Compliance Invoice Check tracks the simplified invoice, simplified credit note, and simplified debit note as three separate checklist items against one compliance certificate, and refuses to issue a Production CSID until all three have a compliant (`PASS`/`WARNING`) submission on record. `zatca:onboard test-invoice` only ever submits the plain invoice — the credit/debit note checks need their own commands.
* **Fix Steps:**
  1. Make sure `test-invoice` has already succeeded for this EGS (its result is what the notes bill against):
     ```bash
     php artisan zatca:onboard test-invoice
     ```
  2. Submit the two missing document types:
     ```bash
     php artisan zatca:onboard test-credit-note
     php artisan zatca:onboard test-debit-note
     ```
  3. Retry:
     ```bash
     php artisan zatca:onboard production
     ```
* **If `test-credit-note`/`test-debit-note` says `No compliant simplified invoice on record for EGS [...]`:** run `test-invoice` first — it did not succeed yet, or ran for a different `--egs` value than the one you're passing to the note commands.

---

### Q2.1b: `zatca:onboard test-credit-note` / `test-debit-note` fails with an Arabic error about "السبب (KSA-10)" ("the reason... leading to the issuance of this type of invoice").
* **Cause:** ZATCA business rule KSA-10 (BR-KSA-17) — every credit or debit note (`InvoiceTypeCode` 381/383) must state why it was issued, specifically at `cac:PaymentMeans/cbc:InstructionNote`. A top-level `cbc:Note` does **not** satisfy this rule — ZATCA's sandbox rejects it with this same message even though a reason is present, because it's at the wrong path.
* **Fix:** Make sure you're on a version of the package where `SimplifiedCreditNoteXmlGenerator`/`SimplifiedDebitNoteXmlGenerator` override `paymentMeansInstructionNoteXml()` (not a `noteXml()`/top-level `cbc:Note` — that was an earlier, incorrect attempt at this same fix), then re-run the failed step. You can confirm the generated XML has `<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode><cbc:InstructionNote>...</cbc:InstructionNote></cac:PaymentMeans>` and no `<cbc:Note>` anywhere.

---

### Q2.3: I get `DecryptException` when reading credentials from `zatca_egs_credentials`.
* **Cause:** `APP_KEY` in `.env` was changed or rotated. Secrets and private keys in `zatca_egs_credentials` are encrypted with Laravel's encrypter using `APP_KEY`.
* **Fix:**
  1. Restore the original `APP_KEY` in `.env` if backed up.
  2. If `APP_KEY` cannot be restored, the stored private key is unrecoverable. You must re-onboard the EGS from scratch using a new OTP from ZATCA.

---

## 3. ZATCA API & Business Rejections (HTTP 400–499)

### Q3.1: An invoice failed with HTTP 400 and error `BR-CL-14`. What is wrong?
* **Meaning:** `BR-CL-14` is ZATCA's validation rule for **Seller Identification Number (VAT Number)**.
* **Rule:** Must be exactly 15 digits, starting with `3` and ending with `3`.
* **Fix Steps:**
  1. Inspect the invoice error: `php artisan zatca:inspect invoice --id=X`.
  2. Fix the Seller VAT Number in the host application configuration / DB.
  3. Re-queue the invoice: `php artisan zatca:requeue --id=X`.

---

### Q3.2: An invoice failed with HTTP 400 and error `BR-KSA-15` or VAT calculation mismatch.
* **Meaning:** The calculated VAT amount, line item tax totals, or net prices do not equal `Amount * (15 / 100)` within allowed rounding tolerances.
* **Fix Steps:**
  1. Inspect the XML tax breakdown using `php artisan zatca:inspect invoice --id=X`.
  2. Fix rounding logic or line item tax calculations in the host app payload builder.
  3. Re-queue the invoice: `php artisan zatca:requeue --id=X`.

---

### Q3.3: Can I re-send an invoice with `php artisan zatca:requeue --id=X` without fixing the data error first?
* **Answer:** **No.**
* **Explanation:** `RetryZatcaInvoiceJob` re-sends the **exact stored signed XML bytes** from `zatca_invoices`. Re-sending without fixing the root cause will result in the exact same HTTP 400 rejection from ZATCA.

---

### Q3.4: ZATCA returned a 400 rejection for duplicate Invoice Counter Value (`ICV`) or UUID.
* **Cause:** The database sequence in `zatca_egs_states` was manually edited or rolled back, causing a collision with an already reported invoice.
* **Fix:**
  1. Check the highest reported ICV in ZATCA portal or `zatca_invoices`.
  2. Update `zatca_egs_states` counter to be `max_icv + 1`.
  3. **NEVER** decrement `icv` or alter `previous_invoice_hash` manually.

---

## 4. Server, Network & Queue Failures (HTTP 500–599 / Timeouts)

### Q4.1: `php artisan zatca:status` shows `Reporting queue ... FAIL`.
* **Cause:** Invoices are signed and waiting in the queue, but the queue worker is not running or crashed.
* **Diagnosis & Fix:**
  1. Check worker status: `php artisan queue:work --queue=zatca --tries=1`.
  2. Ensure Supervisor / systemd is configured to keep `queue:work --queue=zatca` running continuously.

---

### Q4.2: Invoices show HTTP status `0`, `500`, `502`, `503`, or `504` in `zatca:requeue --dry-run`.
* **Cause:** Temporary network failure, DNS issue, or ZATCA servers are temporarily down.
* **Action Required:** **NONE (Do not panic).**
* **Explanation:** HTTP 5xx errors are transient. The system automatically retries via `zatca:requeue --only-transient` (if scheduled). No invoice data is lost.

---

### Q4.3: I see jobs in `failed_jobs` table for ZATCA. How to retry them safely?
* **Fix:**
  1. Check job errors: `php artisan queue:failed`.
  2. Resolve any underlying server/network issue.
  3. Retry all failed ZATCA jobs: `php artisan queue:retry all`.

---

## 5. Database, Sequence & Hash Chain Integrity

### Q5.1: Someone deleted rows from `zatca_invoices`. How bad is this?
* **Impact:** **CRITICAL**.
* **Explanation:** `zatca_invoices` is the official legal invoice store and contains the cryptographic chain. Deleting rows breaks the Previous Invoice Hash (`PIH`) chain for all future invoices.
* **Fix:** Restore the table immediately from the latest database backup.

---

### Q5.2: Can I manually edit `icv` or `previous_invoice_hash` in `zatca_egs_states`?
* **Answer:** **ABSOLUTELY NOT.**
* **Explanation:** Changing `icv` or `previous_invoice_hash` manually breaks the cryptographic hash chain. ZATCA will reject **every subsequent invoice** submitted by this EGS unit.

---

### Q5.3: Can I re-sign an invoice that ZATCA has already accepted?
* **Answer:** **NO.**
* **Explanation:** Re-signing an accepted invoice generates a new signature for the same ICV/UUID, which ZATCA will treat as a fraudulent or duplicate submission.

---

## 6. Configuration & Environment Setup

### Q6.1: How do I verify which ZATCA environment I am connected to?
* **Check `.env`:**
  * `ZATCA_ENVIRONMENT="sandbox"` (Testing with ZATCA Sandbox endpoints)
  * `ZATCA_ENVIRONMENT="simulation"` (Testing with ZATCA Simulation portal)
  * `ZATCA_ENVIRONMENT="production"` (Live tax reporting)
* **Check command:** `php artisan zatca:status` shows the active environment.

---

### Q6.2: Should I set `ZATCA_AUTO_RENEWAL_ENABLED=true` in production?
* **Answer:** **No.** Keep it `false`.
* **Explanation:** ZATCA requires an OTP entered manually via the Fatoora Portal for renewal. Setting this to `true` without verified auto-renewal contracts can leave certificates in an unrenewed or corrupted state.

---

## 7. Emergency Playbook & AI Instructions

When an AI Assistant is assisting a developer during a production incident:

1. **Rule 1 (Data Safety):** Always reassure the developer that signed invoices in `zatca_invoices` are safe and never lost.
2. **Rule 2 (Sanitization):** Remind the developer to sanitize `APP_KEY`, `secret`, `private_key`, `CSID`, and customer PII before sharing logs.
3. **Rule 3 (No Destructive Queries):** Never suggest `DELETE` queries on `zatca_invoices` or manual updates on `icv` / `previous_invoice_hash`.
4. **Rule 4 (First Step):** Always instruct the user to run `php artisan zatca:status` as the first diagnostic step.
5. **Rule 5 (SDK Verification):** Use `php artisan zatca:sdk-smoke-test` to test local Java SDK signing without sending requests to ZATCA.
