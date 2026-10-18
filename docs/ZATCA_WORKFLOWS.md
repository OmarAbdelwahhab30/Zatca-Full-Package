# ZATCA Workflows

The full lifecycle of a ZATCA document, how Reporting and Clearance actually differ operationally, what each environment is for, and how to run the package in production — certificates, deployment stages, and version changes. For day-to-day code, see [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md) and the README. For "something's broken", see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

---

## 1. The full invoice lifecycle

The README's [§7 How the package works](../README.md#7-how-the-package-works) shows the happy path for generating one invoice. In practice, a document can take more branches than that diagram shows — here's the full picture, including rejection and regeneration:

```text
 dispatch(GenerateZatcaInvoiceJob)
          │
          ▼
   ┌─────────────┐   SDK/storage failure   ┌──────────────────────────────┐
   │   draft     │ ───────────────────────▶ │ unsigned draft, left as-is   │
   └─────────────┘                          │ → CompleteZatcaInvoiceGenerationJob
          │ signed OK                       │   finishes it later, same ICV │
          ▼                                 └──────────────────────────────┘
┌────────────────────┐
│ pending_reporting   │  (B2C)        or        │ pending_clearance │  (B2B)
└────────────────────┘                          └────────────────────┘
          │  sent                                         │  sent
          ▼                                                ▼
   ┌─────────────┐                                  ┌───────────────┐
   │  reported   │ ◀── accepted ──────────────────▶ │    cleared    │
   └─────────────┘                                  └───────────────┘
          │ ZATCA unreachable (5xx/network)                 │ same
          ▼                                                 ▼
    retrying → (after budget exhausted) → manual_review / clearance_manual_review
          │                                                 │
          │ ZATCA rejected (4xx, bad data)                  │
          ▼                                                 ▼
   manual_review ──────────────────────────▶ RegenerateZatcaInvoiceJob
                                              (new row, new ICV, old row marked superseded)
```

Once a document reaches `reported`/`cleared` (or their "with warnings" variants), it is **terminal and permanent** — nothing in the package ever edits or re-signs it. Every correction after that point is a new, separately-numbered document: a credit note, a debit note, or (only for a rejected document) a regeneration. See [INVOICE_TYPES.md](INVOICE_TYPES.md) for credit/debit notes, and `RegenerationPolicy`/`RegenerateZatcaInvoiceJob` in [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md#5-recovering-and-regenerating-from-code) for regeneration.

## 2. Reporting vs. Clearance, operationally

Both routes end with an accepted document in `zatca_invoices`, but what happens *while waiting* is different, and that difference shapes how you build your checkout:

| | Reporting (B2C) | Clearance (B2B) |
|---|---|---|
| Buyer sees their invoice | Immediately — you render it the moment the job dispatches | Only once `cleared_xml_base64` comes back — the buyer's legal copy is **ZATCA's stamped copy**, not your draft |
| If ZATCA is briefly unreachable | Invoice sits `retrying`; buyer already has theirs, nothing to undo | Invoice sits in `clearance_retrying`/`clearance_in_doubt`; you may need to hold the buyer's copy until it resolves |
| If ZATCA rejects it | The sale already happened — fix the cause, then regenerate | The buyer never received anything — same fix-then-regenerate path, with less urgency pressure since nothing went out |
| QR code source | Package-generated `qr_code` field, available once signed | Extracted from the **cleared** XML (`SignedInvoiceXmlReader::extractQrCode()`), not the pre-clearance signed copy |

**Practical implication:** if your B2C checkout renders a receipt synchronously, you can safely render it before the queue job even runs — Reporting means ZATCA finds out after the fact. A B2B "download your invoice" button, by contrast, should wait on the document reaching `cleared`/`cleared_with_warnings`, since giving the buyer the pre-clearance draft isn't the real legal invoice.

## 3. Sandbox → Simulation → Production: what each stage actually proves

See [ZATCA_CONCEPTS.md](ZATCA_CONCEPTS.md#5-three-environments-and-why-they-exist) for *why* three environments exist. Operationally, moving through them looks like this:

1. **Sandbox** — build and test your integration. Use ZATCA's shared test VAT number (`399999999900003`) and test OTP; run `composer test:sandbox` to exercise onboarding, invoices, and notes against the real developer-portal sandbox. Nothing here is legally binding, and the sandbox's leniency means it **cannot** prove your real signature works.
2. **Simulation** — the first rehearsal that uses your real company data and checks your real certificate/signature. This is the environment to run before touching production, especially for B2B (see README [§16 Known limitations](../README.md#known-limitations) for exactly what sandbox leaves unproven).
3. **Production** — real, legally binding invoices. Each environment keeps separate credentials, counters, and hash chains — switching `ZATCA_ENVIRONMENT` means onboarding from zero in that environment, not promoting data from the last one.

**Golden rule before any onboarding step, in any environment:** prove the server can sign *before* you request an OTP.

```bash
php artisan zatca:sdk-smoke-test
```

Onboarding needs an OTP, and OTPs are effectively single-use. If Java or the SDK path is wrong, the onboarding call fails and you're back at the portal for another OTP — an avoidable round trip. `zatca:sdk-smoke-test` checks Java, the SDK, signing and validation **without contacting ZATCA and without any certificate** — run it first, every time, in every environment.

| What you see | Meaning | Action |
|---|---|---|
| Nine `[OK]` lines | The server can sign. | Continue to onboarding. |
| `SKIPPED` (command still exits `0`) | `ZATCA_SDK_EXECUTABLE`/`ZATCA_SDK_ROOT` wrong or unset — **read the text, not the exit code** | Fix the paths, re-run |
| `[FAIL]` on Java | Java missing, wrong version, or not on the PHP/queue user's `PATH` | Fix Java (needs 11–15), re-run |
| `[FAIL]` on signing | SDK folder incomplete or unreadable by the PHP user | Fix permissions/folder, re-run |

## 4. Onboarding and certificate management

The command sequence itself is in the README's [§8 ZATCA onboarding](../README.md#8-zatca-onboarding) — this section covers the operational judgment around it.

### Why production is refused until all three test steps pass

```
Production CSID request failed: The compliance certificate is not done with the
following compliance steps yet [simplified-credit-note-compliant, simplified-debit-note-compliant]
```

ZATCA tracks compliance **per document type** against one compliance certificate. `test-invoice`, `test-credit-note`, and `test-debit-note` each submit a different kind of document, and `production` won't issue until all three have a `PASS`/`WARNING` on record. The note checks bill against the invoice `test-invoice` already submitted, so run `test-invoice` first, every time.

### The certificate lifecycle

| Days until expiry | What happens automatically |
|---|---|
| 60 | State becomes `expiring_soon`; a `ZatcaCertificateExpiring` event fires **once**, and a warning is logged |
| 0 | State becomes `expired`; the event fires again; an error is logged |
| After expiry | Invoices are still **created and signed** but not sent — they wait as `pending_reporting`/`pending_clearance`. **Nothing is lost**, sending is paused. |

This only happens if `zatca:certificate:check` actually runs on a schedule — it's a read-only command that updates certificate state and should be scheduled daily. `zatca:certificate:renew` exists in the codebase but does **not** call ZATCA today (`ZATCA_AUTO_RENEWAL_ENABLED=false` by default) — the real renewal contract hasn't been confirmed against official ZATCA documentation, so renewal is **manual**:

```bash
php artisan zatca:onboard compliance --otp=XXXXXX
php artisan zatca:onboard test-invoice
php artisan zatca:onboard test-credit-note
php artisan zatca:onboard test-debit-note
php artisan zatca:onboard production
```

Then release anything that was paused:

```bash
php artisan zatca:report-pending
```

This re-sends paused invoices **without** signing them again — UUID, ICV, PIH and hash are untouched. It does not touch `manual_review`/`failed` invoices; those need a human regardless of certificate state.

**Never set `ZATCA_AUTO_RENEWAL_ENABLED=true`.** It would send a request to an endpoint whose contract (URL, OTP requirement, response shape) hasn't been verified against ZATCA documentation.

### What must never be lost

| Item | Why |
|---|---|
| `APP_KEY` | Decrypts the stored private key and secrets. Without it, both are unreadable and you must onboard from scratch. |
| `zatca_egs_credentials` rows | Your certificates. The production certificate reuses the **compliance** private key — losing that row means starting over, not just re-requesting a certificate. |
| The CSR values in `.env` (`ZATCA_CSR_COMMON_NAME`, `ZATCA_ORG_*`, `ZATCA_CRN`, ...) | Needed to re-onboard consistently if you ever have to |
| Access to the Fatoora portal | The only source of OTPs |

## 5. What the package handles automatically vs. what you decide

| Automatic (the package does this) | Manual (you decide/do this) |
|---|---|
| UUID, counter (ICV), hash chain (PIH) | Dispatching the job after a sale |
| VAT, line totals, document totals | Writing the `InvoiceSourceContract` mapper |
| XML generation, signing, QR code | Onboarding (needs a human OTP) |
| Retries on transient (5xx/network) failures | Deciding when to renew an expiring certificate |
| Duplicate protection (one document per sale) | Reacting to `ZatcaInvoiceNeedsAttention`/`ZatcaCertificateExpiring` — the package fires events but ships no mail/Slack code of its own |
| Freezing each invoice's rates/data at generation time | Fixing the root cause of a rejection before regenerating |
| Pausing reporting when a certificate expires | Scheduling the commands that make the above actually run (see below) |

### The schedule your app needs

The package ships these commands but does not schedule any of them for you:

```php
$schedule->command('zatca:report-pending')->everyFiveMinutes();
$schedule->command('zatca:requeue --only-transient')->hourly();
$schedule->command('zatca:certificate:check')->daily();
$schedule->command('zatca:status')->hourly();
$schedule->command('zatca:prune-submission-attempts')->weekly();
$schedule->command('zatca:prune-sdk-files')->daily();
```

`--only-transient` on the hourly requeue is important: it retries only outage victims (last HTTP `0`/`5xx`) and never retries a genuine rejection, so it's safe to leave running unattended. See README [§13 Console commands](../README.md#13-console-commands) for every flag, and [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for reading what these commands report.

## 6. Tracing a document through the database

The package writes to tables listed in the README's [installation step 4](../README.md#5-installation) (`zatca_egs_credentials`, `zatca_egs_states`, `zatca_invoices`, `zatca_submission_attempts`, `zatca_compliance_documents`, plus the credit/debit note tables). Here's what actually happens to them, end to end, starting from an empty database:

| Step | Command (or automatic trigger) | What changes |
|---|---|---|
| 1 | `zatca:generate-credentials` | `zatca_egs_credentials`: 1 row (`compliance` type — key + CSR, no certificate yet) |
| 2 | `zatca:onboard compliance --otp=...` | Same row, now filled with the certificate ZATCA returned |
| 3 | `zatca:onboard test-invoice` | `zatca_compliance_documents`: 1 row. `zatca_egs_states`: 1 row (compliance counter, `icv=1`) |
| 4 | `zatca:onboard production` | `zatca_egs_credentials`: 2nd row (`production` type, same private key) — onboarding finished |
| 5 | A customer pays (automatic) | `zatca_egs_states`: production counter created (`icv=1`). `zatca_invoices`: 1 row, `draft` → `pending_reporting` → `reported`. `zatca_submission_attempts`: 1 row. |
| 6 | A second customer pays | `zatca_invoices`/`zatca_submission_attempts` grow by one row each. `zatca_egs_states` **does not grow** — it has one row per counter, and the number inside it just increases. |

If signing itself fails partway (step 5 stops after the `draft` row), `CompleteZatcaInvoiceGenerationJob` can finish that same draft later — same row, same ICV reservation. If ZATCA rejects a signed document, `RegenerateZatcaInvoiceJob` creates a *new* `zatca_invoices` row from the stored business snapshot and links the old one to it via `superseded_by_id`; the rejected row is never deleted.

**What must never be lost:** `zatca_egs_credentials` (certificates), `zatca_egs_states` (the counter — a wrong value breaks every future invoice), and `zatca_invoices` (your official records). Safe to lose: `zatca_submission_attempts` (a log, auto-pruned after 180 days) and anything under `storage/app/private/zatca/` (temporary signing files; `zatca:prune-sdk-files` cleans these — the signed invoice itself lives in the database, never in these files).

```bash
php artisan zatca:inspect credentials    # Do I have certificates? Are they valid?
php artisan zatca:inspect state          # What number is the counter on?
php artisan zatca:inspect invoice --id=5 # What happened to invoice 5?
```

## 7. Deploying and updating the package itself

The README's [§14 Server Setup & Production Deployment](../README.md#server-setup) covers server prerequisites, user accounts, the SDK, Supervisor/cron, and the standard first-deploy and every-deploy steps. Two situations it doesn't cover:

### When the ZATCA SDK version changes

1. Put the new SDK on the server, update `ZATCA_SDK_EXECUTABLE`/`ZATCA_SDK_ROOT`/`ZATCA_SDK_VERSION`.
2. Run `zatca:generate-credentials` to get the new fingerprint, and update `ZATCA_SDK_FINGERPRINT` — skipping this makes `zatca:health-check` fail with a fingerprint mismatch.
3. **Changing `ZATCA_SDK_VERSION` in `.env` does not, by itself, change which output parser is used** — the parser class is wired by name inside `ZatcaSdkAdapter`, not read from config. If the new SDK's console output format changed, a developer needs to add a new parser folder and update that class.
4. Verify with `composer test` and `php artisan zatca:sdk-smoke-test` (remember: `SKIPPED` still exits `0` — read the text), then test full onboarding plus one invoice in sandbox before production.
5. Record the new version combination in [`docs/COMPATIBILITY.md`](COMPATIBILITY.md) — it's the only place that table lives.

### When ZATCA releases a new API version

This is a code change, not a config change — a new connector class has to be added under `src/Services/Connectors/` before `ZATCA_API_VERSION` can safely point at it. If `.env` names a version that has no matching connector wired up, the package refuses the request with a clear error rather than silently sending the wrong version. This work belongs to whoever maintains the package itself; as a consumer of it, the signal to watch for is a new package release accompanied by a new row in `docs/COMPATIBILITY.md`.

## 8. Switching ZATCA off entirely

Different from an outage (which is survived by waiting): this is a deliberate stop, for maintenance or an incident.

```bash
php artisan optimize:clear
php artisan queue:restart
```

Both commands matter — the flag is config-cached, and running queue workers hold the old config in memory until restarted. It's safe to flip with work in flight: queued jobs exit quietly, no invoice status or attempt count is written, and onboarding/diagnostic commands stay available so an EGS can still be repaired while invoicing is off. Full behavior is in the README's [Kill switch](../README.md#kill-switch) section.
