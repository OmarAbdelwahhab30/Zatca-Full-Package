# Troubleshooting

A guide for the moment something goes wrong. Run a command, read the answer, follow the step. For the master list of ZATCA rejection codes and config errors, see the README's [§15 Troubleshooting](#15-troubleshooting) — this page focuses on diagnosis flow, operational judgment calls, and the problems not already covered there.

---

## First, breathe

**No invoice is ever lost.** Once an invoice is signed, it is saved in the database with its signature, number, and QR code. Nothing deletes it, nothing changes it. If ZATCA can't be reached, the invoice simply **waits**. You are never recovering lost data — you are only restarting sending.

## Step 1: always start here

```bash
php artisan zatca:status
```

| What you see | Go to |
|---|---|
| `Nothing needs attention.` | [Everything is fine](#everything-is-fine) |
| `Reporting queue ... FAIL` | [Invoices are piling up](#invoices-are-piling-up) |
| `Manual review ... FAIL` | [Invoices need a human](#invoices-need-a-human) |
| `Certificate ... FAIL` | [Certificate problem](#certificate-problem) |
| `Failed jobs ... FAIL` | [Failed jobs](#failed-jobs) |
| Command not found / an error appears | [The command itself fails](#the-command-itself-fails) |

## Everything is fine

Nothing to do. If someone reported a problem anyway, it's somewhere else (payment, email, the website) — not ZATCA. To double-check a specific invoice: `php artisan zatca:inspect invoice --id=41`.

## Invoices are piling up

Meaning: invoices are signed but not reaching ZATCA. Check in this order:

1. **Is the worker running?** `php artisan queue:work --queue=zatca --tries=1`. If invoices start moving, the worker was dead — make sure Supervisor/systemd restarts it automatically.
2. **Is ZATCA down?** If the certificate is fine and the worker is running, ZATCA is probably unreachable. **Do nothing** — the system retries by itself for about an hour, and `zatca:requeue --only-transient` (if scheduled) keeps retrying after that.
3. **Is the certificate expired?** If yes, go to [Certificate problem](#certificate-problem) — reporting is paused on purpose until it's fixed.

## Invoices need a human

An invoice stopped trying. There are two possible reasons, and only one needs you:

```bash
php artisan zatca:requeue --dry-run
```

Look at the **Last HTTP** column:

| Last HTTP | Meaning | What to do |
|---|---|---|
| `0` or `500`–`599` | ZATCA was down | Nothing — it restarts itself if `zatca:requeue --only-transient` is scheduled, or run it now |
| `400`–`499` | **ZATCA rejected the invoice** | Fix the underlying cause, then regenerate — see below |

If rejected: `php artisan zatca:inspect invoice --id=41` and read `last_error` — it names the specific rule ZATCA didn't accept (see the [error-code reference](#common-rejection-codes) below and README [§15](#15-troubleshooting) for the full table). Fix that cause first, then regenerate the document (`RegenerateZatcaInvoiceJob` — see [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md#5-recovering-and-regenerating-from-code)). **Re-sending the same rejected XML with `zatca:requeue` fails identically every time** — `RetryZatcaInvoiceJob` resends the exact stored signed bytes, it never re-signs. Every regeneration spends a new ICV that can never be given back.

## Certificate problem

| Status | What it means | Fix |
|---|---|---|
| "Expires in X days" | Approaching expiry — renewal needs a human OTP | Get an OTP from the Fatoora portal, run `zatca:onboard compliance --otp=...`, `test-invoice`, `production`, then `zatca:status` to confirm |
| "EXPIRED" | Invoices are **paused**, not lost | Same three onboarding steps, then `php artisan zatca:report-pending` to release what was waiting |
| "No expiry date recorded" | The date couldn't be read from the certificate | `php artisan zatca:certificate:check` (a read-only repair), then `zatca:status` |

See [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md#4-onboarding-and-certificate-management) for the full certificate lifecycle and why auto-renewal stays off.

## Failed jobs

```bash
php artisan queue:failed
```

Read the error, fix the underlying cause, then `php artisan queue:retry all`.

## The command itself fails

| Error | Cause | Fix |
|---|---|---|
| `Command "zatca:status" is not defined` | Package not registered | `composer install` then `php artisan package:discover` |
| `ZATCA_SDK_EXECUTABLE ... does not point to an existing SDK jar` | Wrong path in `.env` | Fix the path, then `php artisan config:cache` |
| Anything mentioning Java | Java missing or wrong version | `java -version` must show **11 to 15**, and `java` must be on the `PATH` of the queue worker user, not just your shell |
| `ZatcaSdkFingerprintMismatchException` | The SDK jar file changed | If you upgraded the SDK on purpose, recalculate and update `ZATCA_SDK_FINGERPRINT`. If you did **not** upgrade it, **stop** — the file may have been tampered with or corrupted. Don't update the fingerprint to "make the error go away" without confirming which case this is. |
| `ZatcaSdkOutputChangedException` | ZATCA updated the SDK's CLI output format, breaking the package's parser | Needs a package-level fix (a new parser version) — see [ZATCA_WORKFLOWS.md §7](ZATCA_WORKFLOWS.md#7-deploying-and-updating-the-package-itself) |

Test the whole SDK without touching ZATCA: `php artisan zatca:sdk-smoke-test` — all nine lines must say `[OK]`. If it says `SKIPPED`, the SDK paths in `.env` are wrong — **the command still exits successfully**, so read the text, not the exit code.

## One sale's invoice never finished

A technical problem (missing SDK jar, unwritable folder, a worker that died mid-process) stopped a sale part-way: no document, an unsigned draft, or a signed document never sent. Fix the technical cause first, then finish that sale:

```bash
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042 --dry-run
php artisan zatca:recover --source-type="App\Models\Order" --source-id=1042
php artisan zatca:recover --id=41     # starting from a ZATCA document id instead
```

Always read the dry run first — it shows the current state and exactly what will happen.

| It says | Do this |
|---|---|
| ZATCA rejected the document | Read the reason (`zatca:inspect invoice --id=...`), fix it, then regenerate. Recover never regenerates. |
| `clearance_in_doubt` | Check on the ZATCA side whether it was actually cleared; only if not, re-run with `--resend-in-doubt` |
| Asks for `--egs=` | A B2B EGS is configured — pass the EGS your app signs this sale on |
| Certificate expired | Renew it ([Certificate problem](#certificate-problem)), then run again |

It's safe to run more than once — it continues from wherever the last run stopped and never signs or sends anything twice.

---

## Common rejection codes

The README's [§15 Troubleshooting](#15-troubleshooting) has the full table. These four come up often enough, and with fixes specific enough, to call out here:

| Error | What it means | Fix |
|---|---|---|
| `BR-CL-14` | Seller VAT number is wrong — must be exactly 15 digits, starting and ending with `3` | Fix `ZATCA_VAT_NUMBER`, regenerate affected invoices |
| `BR-KSA-15` | Calculated VAT doesn't match the expected amount within tolerance | Inspect the invoice's tax breakdown (`zatca:inspect invoice --id=X`) — this almost never happens from normal use since the package calculates VAT for you (see [VAT_GUIDE.md](VAT_GUIDE.md)); check whether a custom `vatRate`/`vatCategory` was passed incorrectly |
| `BR-KSA-17` / an Arabic message about "السبب (KSA-10)" | A credit or debit note didn't state its reason in the right place. ZATCA requires the reason at `cac:PaymentMeans/cbc:InstructionNote` — a top-level `cbc:Note` does **not** satisfy this rule, even though a reason is technically present. | Confirmed fixed in the package's note XML generators. If you see this, make sure you're not running a version that predates the fix — the generated XML should contain `<cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode><cbc:InstructionNote>...</cbc:InstructionNote></cac:PaymentMeans>` and no top-level `<cbc:Note>` |
| `onboard production` fails naming missing compliance steps | `test-credit-note`/`test-debit-note` haven't been submitted yet for this EGS | Run `test-invoice` first (notes bill against it), then `test-credit-note` and `test-debit-note`, then retry `production` — see [ZATCA_WORKFLOWS.md §4](ZATCA_WORKFLOWS.md#4-onboarding-and-certificate-management) |

## Exceptions reference

Full behavior for each is in README [§11](RESPONSES.md#exceptions-you-may-catch). Quick lookup:

| Exception | Typically means |
|---|---|
| `ZatcaException` | Invalid invoice data, missing CSR, a failed onboarding call, or bad config |
| `ZatcaCredentialsMissingException` | This EGS has no production certificate yet |
| `ZatcaDisabledException` | Something called the orchestrator directly while `ZATCA_ENABLED=false` |
| `CrossEgsDuplicateException` | This sale already has a document on another EGS |
| `CreditNoteException` | The credit note was refused — check `->getMessage()`; named constructors cover "not eligible", "exceeds remaining value", "amount changed since you checked", and more |
| `DebitNoteException` | The debit note was refused — no lines given, an invalid line reference, etc. |
| `ZatcaSdkProcessException` | The Java SDK process failed, timed out, or produced no output |
| `ZatcaSdkOutputChangedException` | The SDK's console output format doesn't match what the package expects (likely an SDK version mismatch) |
| `ZatcaSdkFingerprintMismatchException` | The SDK jar file on disk doesn't match the recorded fingerprint |
| `ZatcaContractChangedException` | ZATCA's API returned a response shape the package doesn't recognize |

## Database integrity: what never to do

| Never | Why |
|---|---|
| Delete rows from `zatca_invoices` | That's the official invoice record and part of the cryptographic chain |
| Edit `icv` or `previous_invoice_hash` by hand | Breaks the hash chain — ZATCA then rejects **every** future invoice from that EGS |
| Re-sign an invoice ZATCA already accepted | Becomes a duplicate/fraudulent-looking submission |
| Change `APP_KEY` without a backup | The certificate and private key become unreadable — full re-onboarding required |
| Set `ZATCA_AUTO_RENEWAL_ENABLED=true` | The renewal contract is unverified against official ZATCA documentation — see [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md#4-onboarding-and-certificate-management) |

If `zatca_invoices` rows were deleted or `icv`/`previous_invoice_hash` were edited by hand: restore from your latest database backup rather than trying to patch the sequence forward — a guessed "next" counter value risks colliding with something ZATCA already has on record.

## Incident response checklist

When you (or an AI assistant) are helping someone through a live production issue:

1. **Reassure first.** Signed invoices in `zatca_invoices` are safe and never lost — the system is designed so that a ZATCA outage degrades to "waiting", not "data loss".
2. **Redact before sharing.** Strip `APP_KEY`, the `secret` column, `private_key`, CSID/token values, OTPs, and customer personal data from any logs or database rows before pasting them anywhere, including into an AI assistant.
3. **No destructive queries.** Never run `DELETE` on `zatca_invoices`, or manual `UPDATE`s on `icv`/`previous_invoice_hash`.
4. **Start with `zatca:status`.** It's the fastest way to classify what's actually wrong.
5. **Use `zatca:sdk-smoke-test` to isolate SDK/Java problems** without spending an OTP or contacting ZATCA.

## Cheat sheet

```bash
php artisan zatca:status                    # Is anything wrong?
php artisan zatca:inspect invoice --id=41    # Why did this invoice fail?
php artisan zatca:requeue --dry-run          # What is stuck, and why?
php artisan zatca:requeue --id=41            # Send one invoice again
php artisan zatca:recover --id=41 --dry-run  # Finish one sale that broke part-way (look first)
php artisan zatca:report-pending             # Push waiting invoices now
php artisan zatca:health-check               # Is the setup still correct?
php artisan zatca:sdk-smoke-test             # Does signing still work?
```

Every command above is safe to run more than once.

## Still stuck?

See the README's [§15 Troubleshooting](#15-troubleshooting) for the complete error-code table, and [§16 Known limitations](LIMITATIONS.md#known-limitations) for what's genuinely unproven (vs. broken) in B2B today. If you're pasting any of this into an AI assistant for help, redact secrets first (see [Incident response checklist](#incident-response-checklist) above) and point it at this file plus the README — don't rely on an assistant's prior assumptions about what the package does or doesn't implement.

<a id="15-troubleshooting"></a>
## Full error and rejection reference

Server and infrastructure problems (Java, the SDK, storage permissions, the queue worker, cron) have their own table in [§14.9](SERVER_REQUIREMENTS.md#9-troubleshooting). These are the rest: business-rule rejections, onboarding, VAT/data validation, and the data chain itself.

| Problem | Reason | Fix |
|---|---|---|
| `composer require` fails on Laravel 9, 10 or 11 with "affected by security advisories" | Every release of those Laravel versions has open security advisories, and Composer blocks them by default | Upgrade the app to Laravel 12 (recommended), or have the app's owner accept the risk as described in [Laravel compatibility](SERVER_REQUIREMENTS.md#laravel-compatibility) |
| `composer require` fails on Laravel 11 or 12 with a PHP version error | Laravel 11 and 12 need PHP 8.2 or newer | Upgrade PHP to 8.2+ |
| `Call to undefined function openssl_x509_parse` (or similar) | Missing PHP extension | Install or enable `openssl` / `dom` / `mbstring` |
| `Unknown ZATCA EGS identifier` | The EGS name doesn't match `.env` | Use `config('zatca.primary_egs')` / `config('zatca.b2b.egs_identifier')` |
| `No CSR on record ... Run zatca:generate-credentials first` | Step 1 skipped | Run `zatca:generate-credentials` |
| CSR generation fails | Bad or empty CSR fields, or non-English characters in `ZATCA_ORG_NAME` | Fill every CSR field, and keep the English name in `ZATCA_ORG_NAME` |
| `Compliance CSID request failed` / invalid OTP | The OTP expired, is from the wrong portal, or was already used | Create a new OTP in the portal for the **same** environment |
| Compliance test shows `ERROR` | Seller data or address is wrong | Read the printed errors, fix `.env`, run `zatca:health-check`, retry the step |
| `No Compliance CSID on record ... Run zatca:onboard compliance first` | A `test-*` step was run before the compliance step | Run `zatca:onboard compliance --otp=...` first. No counter value was used. |
| `No compliant ... invoice on record ... Run zatca:onboard test-invoice first` | A note check was run before the invoice check | Run `zatca:onboard test-invoice` first |
| Production CSID fails | Compliance steps incomplete | Run all three `test-*` steps with PASS first |
| `ZatcaCredentialsMissingException` / job fails at once | No production certificate for this EGS and environment | Finish onboarding in the current environment |
| `ZATCA_VAT_PERCENTAGE must be a number` / `ZATCA_VAT_MODE must be "inclusive" or "exclusive"` | Invalid VAT setting in `.env` | Fix it (or remove it for the default), then `php artisan config:cache` |
| `does not reconcile — BR-S-09` | Many discounts on few items push a VAT breakdown outside ZATCA's rounding tolerance | Merge the discounts into one, or give each its own `lineIndexes` |
| `item N has quantity 0` / `negative price` | Bad item data | Quantity at least 1, price not negative. Use credit notes for refunds. |
| `has more than two decimals (BR-KSA-DEC-02)` / `must be between 0 and 100` | An item's `vatRate` is not a valid ZATCA rate | Use a rate like `15` or `5` |
| `standard-rated (S) with a 0% rate` | A 0% item without a VAT category | Use `ZatcaInvoiceItem::zeroRated()` or `exempt()` with a `VATEX-SA-...` code and reason |
| `cannot have a 15% rate. Zero-rated and exempt items are always 0%` | A zero-rated/exempt item with a `vatRate` | Remove `vatRate` from that item |
| `needs a ZATCA exemption reason code` / `reason text` | A `Z`/`E` item without its reason | Pass the code and reason to `zeroRated()` / `exempt()` |
| `only SAR is supported` | `currency` is not `SAR` | Invoice in SAR |
| `has no items` / `has no lines` / `negative` | Empty or negative invoice data | Don't invoice orders without items. Use credit notes for refunds. |
| Invoice in `manual_review` with BR-KSA codes | ZATCA rejected the content (for example line math, VAT number) | Fix the mapper or data, then [regenerate](INTEGRATION_REFERENCE.md#re-issuing-a-rejected-invoice-regeneration) |
| `buyer VAT number must be 15 digits...` | Invalid buyer VAT | Ask the buyer for the correct VAT, or use `BuyerOtherId` (CR number) |
| Warning `BR-KSA-F-13` ("Other Seller/Buyer ID ... invalid value") | A seller or buyer ID (for example a CR number) contains 8 equal digits (`00000000`) or 8 sequential digits (`12345678`). ZATCA treats it as a fake ID. | Use the real number. The invoice is accepted with a warning, but fix the data. |
| `Standard document ... has no buyer / no supply date` | B2B data missing | Set `buyer` and `supplyDate` in the mapper |
| Credit note: `not eligible` | Original not accepted yet, another note is in progress, or the original is fully credited | Check `CreditNoteEligibilityPolicy::verdict()->reason` |
| Missing original invoice reference | Not possible from this API | Notes always take the reference from `$original`, so pass the right `ZatcaInvoice` |
| Wrong previous hash / counter problems | The database was restored or edited, or two environments are mixed | Never edit `zatca_egs_states`. Check `zatca:inspect state`. Contact ZATCA if the chain broke. |
| Signing works in sandbox, fails in production | Production not onboarded, a different OTP or portal, or wrong environment value. Also, the sandbox does not check the production signature (its production certificate is shared), so a signing problem can stay hidden there. | Onboard again in production. Credentials are per environment. Rehearse in simulation first. |
| `composer test:sandbox` skips every test | `ZATCA_SDK_ROOT` / `ZATCA_SDK_EXECUTABLE` not set or wrong | Set both (see [Sandbox tests](TESTING.md#sandbox-tests)) |
| `composer test:sandbox` fails at `generate-credentials` (the first SDK step) | Java is missing from the PATH, or the version is outside what the SDK supports (11 up to, not including, 15) | Install Java 11 and put it first on the PATH |
