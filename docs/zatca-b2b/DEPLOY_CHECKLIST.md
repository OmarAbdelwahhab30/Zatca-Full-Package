# ZATCA B2B Deploy Checklist (Staging, then Production)

Do this exact list twice: once on **Staging** now, once on **Production** in 2 days.
Steps marked **[PROD ONLY]** or **[STAGING ONLY]** differ — everything else is identical both times.

---

## 1. Before you touch anything

- [ ] Full database backup.
- [ ] Confirm the branch you're deploying is the one the tests passed on.
- [ ] Have a Super Admin ready to watch the app for 1 hour after deploy.

---

## 2. Deploy the code

- [ ] Pull/deploy latest code: `coursefer` (backend), `lms-admin-frontend`, `resources/app`.
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `lms-admin-frontend`: `npm ci && npm run build`
- [ ] `resources/app`: `npm ci && npm run build`

---

## 3. Migrations

```bash
php artisan zatca:check-source-uniqueness
```
- [ ] Must print `[OK]`. If it prints any duplicate row → **STOP**, fix the database first, do not migrate.

```bash
php artisan migrate --force
```
- [ ] Ran with no errors.

---

## 4. `.env` — set these now

```env
ZATCA_ENABLED=true
ZATCA_B2B_ENABLED=false
WATHQ_ENABLED=false
WATHQ_SIMULATE=false
```

- [ ] `WATHQ_SIMULATE` is `false` (this is a dev-only flag, must never be true here).
- [ ] Leave these empty for now — you'll fill them in step 6:
  `ZATCA_B2B_EGS_IDENTIFIER`, `ZATCA_B2B_ENVIRONMENT`, `ZATCA_B2B_CSR_COMMON_NAME`, `ZATCA_B2B_CSR_SERIAL_NUMBER`, `ZATCA_B2B_INVOICE_TYPE`, `ZATCA_B2B_ORG_UNIT_NAME`.

**[STAGING ONLY]** use sandbox test values.
**[PROD ONLY]** use real values from step 6 — never copy the sandbox values into production.

---

## 5. Clear cache, restart queue

```bash
php artisan config:clear
php artisan optimize:clear
php artisan queue:restart
```
- [ ] `queue:restart` is mandatory — workers must reload the new code.

---

## 6. ZATCA Onboarding for the B2B device (2nd EGS)

Do this fully, both times (Staging with sandbox, Production for real).

⚠️ Not 100% officially confirmed with ZATCA that a 2nd EGS on one taxpayer works exactly like the 1st. If any step below is rejected by ZATCA, stop and check with ZATCA support before continuing.

**6.1 Set config**
```env
ZATCA_B2B_EGS_IDENTIFIER=b2b
ZATCA_B2B_ENVIRONMENT=sandbox        # or "production" on prod
ZATCA_B2B_ORG_UNIT_NAME=<any name>
ZATCA_B2B_INVOICE_TYPE=1100
```
```bash
php artisan config:clear
```

**6.2 Generate key + CSR**
```bash
php artisan zatca:generate-credentials --egs=b2b
```
- [ ] Copy the printed `SDK fingerprint` into `.env` as `ZATCA_SDK_FINGERPRINT` (if one already exists from the 1st EGS, it must match exactly).
- [ ] Keep the printed CSR — you need it next.

**6.3 Get OTP from ZATCA (manual, outside the code)**
- [ ] Go to [Fatoora Portal](https://fatoora.zatca.gov.sa), log in as the company.
- [ ] Onboard a new device, paste the CSR from 6.2.
- [ ] Get the OTP code sent to the registered contact.

**6.4 Compliance step**
```bash
php artisan zatca:onboard compliance --egs=b2b --otp=XXXXXX
```
- [ ] Replace `XXXXXX` with the real OTP. Must succeed.

**6.5 Three required test documents, in order**
```bash
php artisan zatca:onboard test-invoice --egs=b2b
php artisan zatca:onboard test-credit-note --egs=b2b
php artisan zatca:onboard test-debit-note --egs=b2b
```
- [ ] All three print `Status: PASS`. If any says `ERROR`, fix it before continuing — do NOT go to production certificate yet.

**6.6 Get the production certificate**
```bash
php artisan zatca:onboard production --egs=b2b
```
- [ ] Succeeded.

**6.7 Final check**
```bash
php artisan zatca:health-check
```
- [ ] All `b2b` items show `[OK]`. If any `[FAIL]`, do not go live — fix first.

---

## 7. Turn B2B on

```env
ZATCA_B2B_ENABLED=true
```
```bash
php artisan config:clear
```

**[STAGING ONLY]**
- [ ] Do one full test company purchase → check it produces a Standard invoice (not Simplified) and gets Cleared.
- [ ] Test a credit/debit note on that invoice.
- [ ] Do one normal individual purchase → confirm it's still Simplified (B2C), unaffected.

**[PROD ONLY]**
- [ ] Turn it on for ONE real company first (pilot) — not all customers at once.
- [ ] Watch `zatca:health-check` and `zatca:status` daily for the first week.
- [ ] If anything breaks: set `ZATCA_B2B_ENABLED=false` immediately (old B2C invoices keep working, unaffected).

---

## 8. Permissions

- [ ] Nothing new to create. The "Held Sales" screen reuses existing permissions: `list_zatca_invoices` (view) and `add_transactions` (resolve). Any admin who already has both sees it automatically.

---

## 9. Final check after every deploy

```bash
php artisan zatca:health-check
php artisan zatca:status
```
- [ ] Both look healthy.
- [ ] Open admin "e-Invoicing Settings" → B2C still shows as active/healthy.
- [ ] Do one normal individual (B2C) purchase and confirm the invoice + email look exactly as before. **This is the most important check, every single deploy**, whether or not B2B was touched.

---

## 10. Rollback

- **Migration failed** → restore backup. New tables/columns are additive, safe to roll back if nothing real was written to them yet.
- **B2B misbehaving after going live** → `ZATCA_B2B_ENABLED=false` + `config:clear`. Only blocks new company sales; already-cleared invoices are unaffected. Old B2C flow is never touched by this flag.
- **Code needs full rollback** → redeploy previous version + `composer install` + `config:clear` + `queue:restart`.
