# ZATCA Database — Simple Guide

What the package stores, and what each command adds. Nothing here is theory — every row below comes from the actual code.

The package creates **5 tables**. All of them start with `zatca_`. It never touches your own tables.

---

## Part 1 — The 5 Tables

### 1. `zatca_egs_credentials` — your keys and certificates

Who you are to ZATCA. **This is the most important table. Never delete it.**

One row per certificate type. You end up with **2 rows**:

| `credential_type` | What it is |
|---|---|
| `compliance` | The test certificate. You get it first. |
| `production` | The real certificate. Used for real invoices. |

Main columns:

| Column | Meaning | Encrypted? |
|---|---|---|
| `csr` | The certificate request sent to ZATCA | No |
| `private_key` | Your private key — signs every invoice | 🔒 Yes |
| `binary_security_token` | The certificate ZATCA gave you (the CSID) | No |
| `secret` | The password used with the token | 🔒 Yes |
| `request_id` | Needed later to get the production certificate | No |
| `certificate_expires_at` | When it expires | No |
| `certificate_state` | `valid` / `expiring_soon` / `expired` | No |

> 🔒 Encrypted with `APP_KEY`. If `APP_KEY` changes, these become unreadable and you must onboard again.

### 2. `zatca_egs_states` — the invoice counter

ZATCA requires every invoice to be numbered in order and linked to the one before it. This table remembers where you are.

| Column | Meaning |
|---|---|
| `icv` | Invoice counter. 1, 2, 3, 4… never repeats, never goes back. |
| `previous_invoice_hash` | The fingerprint of the previous invoice (PIH) |
| `state_type` | `compliance` or `production` — two separate counters |

**Never edit this table by hand.** A wrong counter breaks the chain for every invoice after it.

### 3. `zatca_invoices` — your real invoices

One row per invoice. **This is the official record. It is never deleted by any command.**

| Column | Meaning |
|---|---|
| `source_reference` | Link to your own order (stops duplicates) |
| `uuid`, `icv`, `invoice_hash`, `previous_invoice_hash` | ZATCA identifiers |
| `unsigned_xml` | The invoice before signing |
| `signed_xml_base64` | **The signed invoice — this is what gets sent to ZATCA** |
| `qr_code` | The QR code |
| `status` | Where it is now (see below) |
| `attempt_count`, `last_error` | Sending history |

Status values:

| Status | Meaning |
|---|---|
| `draft` | Created, not signed yet |
| `pending_reporting` | Signed, waiting to be sent |
| `retrying` | Sending failed, will try again |
| `reported` | ✅ Accepted by ZATCA |
| `reported_with_warnings` | ✅ Accepted, with warnings |
| `manual_review` | ❌ Needs a human |

### 4. `zatca_submission_attempts` — the sending log

Every attempt to send an invoice adds one row here. One invoice can have several.

| Column | Meaning |
|---|---|
| `zatca_invoice_id` | Which invoice |
| `attempt_number` | 1, 2, 3… |
| `outcome` | Succeeded or failed |
| `http_status` | ZATCA's response code |
| `error_message` | Why it failed |

This is the only table that gets cleaned automatically (after 180 days, and only for invoices that already succeeded).

### 5. `zatca_compliance_documents` — onboarding test invoices

The fake invoices sent to ZATCA during registration. Kept as proof that onboarding was completed. Not related to real sales.

One row per `zatca:onboard test-*` call, `document_type` distinguishing `simplified_invoice` / `simplified_credit_note` / `simplified_debit_note`. `document_reference` holds the invoice's `cbc:ID` (not its `uuid`) — the credit/debit note rows copy it from the invoice row so `zatca:onboard test-credit-note`/`test-debit-note` can find what to bill against without re-parsing stored XML.

---

## Part 2 — What Each Command Does to the Database

### Commands that WRITE

| Command | Table | What it adds or changes |
|---|---|---|
| `zatca:generate-credentials` | `zatca_egs_credentials` | Creates the **compliance** row with `csr` + `private_key` |
| `zatca:onboard compliance` | `zatca_egs_credentials` | Fills the same row with `binary_security_token`, `secret`, `request_id`, expiry date |
| `zatca:onboard test-invoice` / `test-credit-note` / `test-debit-note` | `zatca_compliance_documents`<br>`zatca_egs_states` | Adds 1 test document of that type (notes also copy `document_reference` from the invoice row)<br>Moves the compliance counter forward |
| `zatca:onboard production` | `zatca_egs_credentials` | Creates the **production** row (reuses the same private key) |
| `zatca:certificate:check` | `zatca_egs_credentials` | Changes `certificate_state` only |
| `zatca:certificate:renew` | `zatca_egs_credentials` | Updates the certificate (only if auto-renewal is enabled) |
| `zatca:report-pending` | `zatca_invoices`<br>`zatca_submission_attempts` | Retries sending → updates status, adds attempt rows |
| `zatca:requeue` | `zatca_invoices` | Sets stuck invoices back to `retrying` and resets `attempt_count`. Never touches an invoice ZATCA accepted. |
| `zatca:recover` | `zatca_invoices`<br>`zatca_egs_states`<br>`zatca_submission_attempts` | For one sale: creates the document if none exists (reserves an ICV, advances the hash chain), or signs its unsigned draft (same ICV), then sends it once and records the attempt. Sets and clears `generation_claimed_at` while signing a draft, and writes `last_error` if that signing fails. Never regenerates, never deletes. `--dry-run` writes nothing. |

### Commands that DELETE

| Command | Deletes | Never deletes |
|---|---|---|
| `zatca:prune-submission-attempts` | Old rows in `zatca_submission_attempts` | Invoices, credentials, counters |
| `zatca:prune-sdk-files` | Files on disk only | **Nothing in the database** |

### Commands that only READ

`zatca:inspect` · `zatca:health-check` · `zatca:status` · `zatca:sdk-smoke-test`

These never change anything. Safe to run any time, including on production.

> One exception worth knowing: `zatca:certificate:check` will fill in a missing
> `certificate_expires_at` by reading the stored certificate. That is a repair,
> not a change of meaning — a null expiry means the certificate cannot be
> monitored at all.

### Not a command — the automatic part

When a customer pays, a background job runs. This is what actually creates invoices:

| Step | Table | What happens |
|---|---|---|
| Invoice created | `zatca_egs_states` | Counter goes up by 1 |
| | `zatca_invoices` | New row, status `draft` |
| After signing | `zatca_invoices` | Adds `signed_xml_base64`, `invoice_hash`, `qr_code` → status `pending_reporting` |
| | `zatca_egs_states` | Saves the new hash for the next invoice |
| After sending | `zatca_invoices` | Status becomes `reported` |
| | `zatca_submission_attempts` | New row with the result |

If signing stops after the `draft` row is created, `CompleteZatcaInvoiceGenerationJob` can complete that same draft later. It keeps the same row, UUID/ICV reservation, and business data; it only fills the missing signing fields and moves the document back toward reporting.

If ZATCA rejects a signed document for document-content reasons, `RegenerateZatcaInvoiceJob` can regenerate it. Regeneration creates a new `zatca_invoices` row from the stored business snapshot, sets `regenerated_from_id` on the replacement, and sets `superseded_by_id` on the rejected document only after the replacement is signed. The admin list follows that chain and shows the newest non-superseded document as the invoice's current status.

---

## Part 3 — Walkthrough: From Empty to First Invoice

Start: all 5 tables are **empty**.

**Step 1 —** `php artisan zatca:generate-credentials`

| Table | Rows |
|---|---|
| `zatca_egs_credentials` | **1** (compliance — has key + CSR, but no certificate yet) |

**Step 2 —** `php artisan zatca:onboard compliance --otp=123456`

| Table | Rows |
|---|---|
| `zatca_egs_credentials` | 1 — *the same row*, now filled with the certificate from ZATCA |

**Step 3 —** `php artisan zatca:onboard test-invoice`

| Table | Rows |
|---|---|
| `zatca_compliance_documents` | **1** |
| `zatca_egs_states` | **1** (compliance counter: `icv = 1`) |

**Step 4 —** `php artisan zatca:onboard production`

| Table | Rows |
|---|---|
| `zatca_egs_credentials` | **2** ← the production row is added. Onboarding is finished. |

**Step 5 —** A customer pays (automatic, no command)

| Table | Rows |
|---|---|
| `zatca_egs_states` | 2 (a production counter is created: `icv = 1`) |
| `zatca_invoices` | **1** — `draft` → `pending_reporting` → `reported` |
| `zatca_submission_attempts` | **1** |

**Step 6 —** Customer number 2 pays

| Table | Rows |
|---|---|
| `zatca_invoices` | **2** |
| `zatca_submission_attempts` | **2** |
| `zatca_egs_states` | still 2 rows — the production counter is now `icv = 2` |

Notice: `zatca_egs_states` **never grows**. It has one row per counter and the number inside it goes up.

---

## Part 4 — What Must Never Be Lost

| Item | Why |
|---|---|
| `APP_KEY` in `.env` | Without it the private key cannot be decrypted → full re-onboarding |
| `zatca_egs_credentials` | Your certificate and key |
| `zatca_egs_states` | The counter. A wrong value breaks every future invoice. |
| `zatca_invoices` | Your official invoice records |

Safe to lose: `zatca_submission_attempts` (a log) and everything under `storage/app/private/zatca/` (temporary files).

Make sure your database backup includes these tables.

---

## Quick Checks

```bash
php artisan zatca:inspect credentials    # Do I have certificates? Are they valid?
php artisan zatca:inspect state          # What number is the counter on?
php artisan zatca:inspect invoice --id=5 # What happened to invoice 5?
php artisan zatca:health-check           # Is everything OK?
```

More detail: [`OPERATIONS.md`](OPERATIONS.md) · [`../README.md`](../README.md)
