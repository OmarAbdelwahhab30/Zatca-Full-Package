# When Something Breaks

A short guide for the moment something goes wrong. No theory. Run a command, read the answer, follow the step.

---

## First, breathe

**No invoice is ever lost.**

Once an invoice is signed, it is saved in the database with its signature, its number, and its QR code. Nothing deletes it. Nothing changes it. If ZATCA cannot be reached, the invoice simply **waits**.

You are never recovering lost data. You are only restarting sending.

---

## Step 1 — Always start here

```bash
php artisan zatca:status
```

This one command tells you what is wrong. Find your answer below.

| What you see | Go to |
|---|---|
| `Nothing needs attention.` | [Case A](#case-a--everything-is-fine) |
| `Reporting queue ... FAIL` | [Case B](#case-b--invoices-are-piling-up) |
| `Manual review ... FAIL` | [Case C](#case-c--invoices-need-a-human) |
| `Certificate ... FAIL` | [Case D](#case-d--certificate-problem) |
| `Failed jobs ... FAIL` | [Case E](#case-e--failed-jobs) |
| Command not found / an error appears | [Case F](#case-f--the-command-itself-fails) |

---

## Case A — Everything is fine

Nothing to do. If someone reported a problem, the problem is somewhere else (payment, email, the website) — not ZATCA.

To double-check a specific invoice:

```bash
php artisan zatca:inspect invoice --id=41
```

---

## Case B — Invoices are piling up

**What it means:** invoices are signed but not reaching ZATCA.

There are only three causes. Check them in this order.

### 1. Is the worker running?

```bash
php artisan queue:work --queue=zatca --tries=1
```

If invoices start moving, the worker was dead. Make sure it restarts automatically (Supervisor / systemd).

### 2. Is ZATCA down?

```bash
php artisan zatca:status
```

If it says the certificate is fine and the worker is running, ZATCA is probably unreachable.

**Do nothing.** The system retries by itself for about an hour, and `zatca:requeue --only-transient` keeps retrying after that. Check again in an hour.

### 3. Is the certificate expired?

If yes → [Case D](#case-d--certificate-problem). Reporting is paused on purpose until the certificate is fixed.

---

## Case C — Invoices need a human

**What it means:** an invoice stopped trying. There are two possible reasons — and only one needs you.

```bash
php artisan zatca:requeue --dry-run
```

Look at the **Last HTTP** column:

| Last HTTP | Meaning | What to do |
|---|---|---|
| `0` or `500`–`599` | ZATCA was down | Nothing. It restarts itself if `zatca:requeue --only-transient` is scheduled. Or run it now. |
| `400`–`499` | **ZATCA rejected the invoice** | Keep reading — this one needs you. |

### If ZATCA rejected it

Find out why:

```bash
php artisan zatca:inspect invoice --id=41
```

Read `last_error`. It says exactly what ZATCA did not accept (a wrong number, a missing field, bad data).

Fix that cause first. Then send it again:

```bash
php artisan zatca:requeue --id=41
```

> Sending it again **without** fixing the cause will fail in exactly the same way. Nothing bad happens — but nothing good either.

---

## Case D — Certificate problem

### "Expires in X days"

Renewal cannot be automated: it needs an OTP from the Fatoora portal, typed by a person.

Get the OTP from the portal, then run the three steps:

```bash
php artisan zatca:onboard compliance --otp=XXXXXX
php artisan zatca:onboard test-invoice
php artisan zatca:onboard production
```

Then confirm:

```bash
php artisan zatca:status
```

### "EXPIRED"

The same three steps. The only difference: invoices are **paused** while it is expired. They are not lost. After onboarding succeeds, release them:

```bash
php artisan zatca:report-pending
```

### "No expiry date recorded"

The date could not be read from the certificate. Repair it:

```bash
php artisan zatca:certificate:check
php artisan zatca:status
```

---

## Case E — Failed jobs

```bash
php artisan queue:failed
```

Read the error. After fixing the cause:

```bash
php artisan queue:retry all
```

---

## Case F — The command itself fails

| Error | Cause | Fix |
|---|---|---|
| `Command "zatca:status" is not defined` | Package not loaded | `composer install` then `php artisan package:discover` |
| `ZATCA_SDK_EXECUTABLE ... does not point to an existing SDK jar` | Wrong path in `.env` | Fix the path, then `php artisan config:cache` |
| Anything mentioning Java | Java missing or wrong version | `java -version` must show **11 to 15** |
| `fingerprint mismatch` | The SDK jar file changed | If you upgraded on purpose, update `ZATCA_SDK_FINGERPRINT`. If not, **stop** — someone replaced the file. |

Test the whole SDK without touching ZATCA:

```bash
php artisan zatca:sdk-smoke-test
```

All nine lines must say `[OK]`. If it says `SKIPPED`, the SDK paths in `.env` are wrong — note that the command still exits successfully, so read the text, not the exit code.

---

## Never do these

| Never | Why |
|---|---|
| Delete rows from `zatca_invoices` | That is the official invoice record |
| Edit `icv` or `previous_invoice_hash` by hand | It breaks the chain — ZATCA then rejects **every** future invoice |
| Re-sign an invoice ZATCA already accepted | It would become a duplicate |
| Change `APP_KEY` | The certificate and private key become unreadable; full re-onboarding |
| Set `ZATCA_AUTO_RENEWAL_ENABLED=true` | The renewal contract is unverified — see [OPERATIONS.md](OPERATIONS.md) §4 |

---

## Still stuck?

Copy the prompt in [`AI_SUPPORT_PROMPT.md`](AI_SUPPORT_PROMPT.md) into an AI assistant. It explains the whole system and asks you the right questions.

⚠ Redact secrets first: `APP_KEY`, private keys, tokens, OTPs.

---

## Cheat sheet

```bash
php artisan zatca:status                  # Is anything wrong?
php artisan zatca:inspect invoice --id=41 # Why did this invoice fail?
php artisan zatca:requeue --dry-run       # What is stuck, and why?
php artisan zatca:requeue --id=41         # Send one invoice again
php artisan zatca:report-pending          # Push waiting invoices now
php artisan zatca:health-check            # Is the setup still correct?
php artisan zatca:sdk-smoke-test          # Does signing still work?
```

Every command above is safe to run more than once.
