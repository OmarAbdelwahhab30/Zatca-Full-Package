# Integration reference

<a id="8-zatca-onboarding"></a>
## ZATCA onboarding

Onboarding gives your EGS its certificate. You do it **once per EGS, per environment**. Do it in `sandbox` first, then `simulation`, then `production`.

### What you need from the business

- VAT number, CR number, English and Arabic legal names, branch name, business category, national address. Put them in `.env`.
- Access to the **Fatoora portal** (simulation or production) to create an **OTP**. An OTP expires quickly, usually within 1 hour, so create it right before step 2.

### The order of steps

```bash
# 1. Create the private key + CSR (stored encrypted in the database). No ZATCA call.
php artisan zatca:generate-credentials --egs=default
```

It prints the SDK fingerprint. Put that value in `.env` as `ZATCA_SDK_FINGERPRINT`, then run `php artisan config:clear`.

```bash
# 2. Get the compliance (test) certificate. Needs the OTP.
php artisan zatca:onboard compliance --egs=default --otp=123456
```

```bash
# 3. Prove your documents are valid. Run all three, in this order.
php artisan zatca:onboard test-invoice --egs=default
```

```bash
php artisan zatca:onboard test-credit-note --egs=default
```

```bash
php artisan zatca:onboard test-debit-note --egs=default
```

Each one builds a sample document, signs it with the compliance certificate and sends it to ZATCA. You should see `Status: PASS` (or `WARNING`). `ERROR` means fix the problem and run that step again. The credit and debit note checks reference the last test invoice, so run `test-invoice` first.

```bash
# 4. Get the production certificate. Invoices can be signed from now on.
php artisan zatca:onboard production --egs=default
```

```bash
# 5. Confirm.
php artisan zatca:health-check
```

```bash
php artisan zatca:inspect credentials --egs=default
```

Success looks like this: `[OK] [default] compliance credentials present` and `[OK] [default] production credentials present — certificate state: valid`.

### Onboarding the B2B EGS

Set the `ZATCA_B2B_*` values, then run the same five steps with `--egs=b2b`. Because `b2b` is not your primary EGS, the package automatically sends **standard** test documents for it (standard invoice, credit note and debit note).

### Doing the same from PHP

The commands call `Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator`. You can call it from your own admin screen:

```php
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;

$onboarding = app(ComplianceOnboardingOrchestrator::class);

$onboarding->generateCredentials('default');            // ['csr' => ..., 'privateKeyGenerated' => true, 'sdkFingerprint' => ...]
$onboarding->compliance('default', $otpFromAdmin);      // ['requestId' => ..., 'dispositionMessage' => ...]
$onboarding->testInvoice('default');                    // ['status' => 'pass'|'warning'|'error', 'warnings' => [...], 'errors' => [...]]
$onboarding->testCreditNote('default');
$onboarding->testDebitNote('default');
$onboarding->production('default');                     // ['binarySecurityToken' => true]
```

### Moving from testing to production

1. Finish all steps in `sandbox` and test your real code there. Sandbox invoices are not legal.
2. Set `ZATCA_ENVIRONMENT=simulation`, run `config:clear`, and onboard again with an OTP from the **simulation** portal. Send a few real-looking invoices.
3. Set `ZATCA_ENVIRONMENT=production`, run `config:cache`, and onboard again with an OTP from the **production** Fatoora portal.
4. From now on every invoice is real. Never switch a live server back to `sandbox`.

Onboarding never touches invoices. It only adds credentials for the current environment.

<a id="10-invoice-fields-explained"></a>
## Invoice fields explained

**You provide** these in `ZatcaInvoiceInput` (through your mapper). No amounts other than prices: the package calculates net amounts, VAT and totals.

| Field | Meaning | Required? | Notes |
|---|---|---|---|
| `egsIdentifier` | Which EGS signs it | Yes | Passed to you. Return it as-is. |
| `sourceReference` | Your unique invoice number | Yes | Used for duplicate protection and printed as the invoice number. Must match `sourceReference()`. |
| `buyerName` | Buyer's name | B2C: optional | B2C: printed if set. B2B: use `buyer` instead. |
| `buyerVatNumber` | Buyer's VAT number | No | Kept for your records. B2B uses `buyer->vatNumber`. |
| `issueDateTime` | Invoice date and time | Yes | Written exactly as given, so use Saudi time (`Asia/Riyadh`). |
| `currency` | Currency code | Yes | Must be `SAR`. Anything else is refused. |
| `items` | Array of `ZatcaInvoiceItem` | Yes, at least 1 | See below |
| `discounts` | Document-level discounts | No | Array of `ZatcaInvoiceDiscount` |
| `priceMode` | What the prices on this invoice mean | No | `VatPriceMode::Inclusive` / `Exclusive`. `null` = `ZATCA_VAT_MODE` |
| `documentType` | `Invoice` (B2C) or `StandardInvoice` (B2B) | Default `Invoice` | Decides Reporting or Clearance |
| `buyer` | Company buyer (`BuyerParty`) | **B2B: yes** | Ignored for B2C |
| `supplyDate` | Delivery or supply date | **B2B: yes** | Ignored for B2C |

<a id="invoice-items"></a>
**Each item (`ZatcaInvoiceItem`):**

| Field | Meaning | Rule |
|---|---|---|
| `description` | Item name | Any text (escaped for XML for you) |
| `quantity` | How many | Whole number, at least 1 |
| `price` | Price of ONE unit, as you charge it | Not negative. VAT-inclusive or exclusive per the price mode. Up to 6 decimals. |
| `vatRate` | VAT % for this item only, e.g. `5` | Optional. `null` = `ZATCA_VAT_PERCENTAGE`. 0–100, at most 2 decimals. Standard-rated items only. |
| `vatCategory` | VAT category | Optional. `ZatcaVatCategory::Standard` (default). Use `ZatcaInvoiceItem::zeroRated()` / `exempt()` for 0% items. |
| `vatExemptionReasonCode`, `vatExemptionReason` | ZATCA reason code and text | Required for zero-rated and exempt items |

**Each discount (`ZatcaInvoiceDiscount`):** `reason`, `amount` (in the same price mode as the items), and optionally `vatRate`, `vatCategory` with its exemption code and text, `lineIndexes` (the items it covers) and `poolKey` (your own name for that group, e.g. `bundle:7`).

<a id="how-vat-is-calculated"></a>
#### How VAT is calculated

All in exact decimal arithmetic (no floating point), rounding half-up to the halala:

1. **Per line, not per unit:** line amount = quantity × price, rounded once. Ten items at 100.00 are 1,000.00.
2. **Exclusive:** net = line amount; VAT = net × rate, rounded.
   **Inclusive:** total = line amount; net = total ÷ (1 + rate), rounded; VAT = total − net. So net + VAT is always exactly what the customer paid.
3. **Zero-rated / exempt:** no VAT, net = line amount, in either mode.
4. **The XML unit price** (`PriceAmount`) is the NET unit price, line net ÷ quantity, with up to 6 decimals when needed, so that quantity × price matches the line net (ZATCA rule BR-KSA-EN16931-11). A price that fits 2 decimals is written with 2.
5. **A discount** is calculated like a one-unit line, at its own rate.
6. **Totals** are the sums of the rounded lines and discounts.

| Items (15%) | Mode | Net | VAT | Total | XML unit price |
|---|---|---|---|---|---|
| 1 × 115.00 | inclusive | 100.00 | 15.00 | 115.00 | 100.00 |
| 1 × 100.00 | exclusive | 100.00 | 15.00 | 115.00 | 100.00 |
| 1 × 100.00 | inclusive | 86.96 | 13.04 | 100.00 | 86.96 |
| 10 × 100.00 | inclusive | 869.57 | 130.43 | 1,000.00 | 86.957 |
| 3 × 33.33 | inclusive | 86.95 | 13.04 | 99.99 | 28.983333 |
| 3 × 33.33 | exclusive | 99.99 | 15.00 | 114.99 | 33.33 |

With inclusive prices the VAT is what remains after the net is rounded, so it can differ by one halala from net × rate. ZATCA's rules allow this; the package's own check before signing does too.

**Before a counter number is used,** the package checks the calculated invoice: lines and totals reconcile (BR-CO-10/14/15), each VAT breakdown matches its taxable amount × rate within ZATCA's tolerance (BR-S-09), nothing is negative, and every VAT category has what it needs. You can also call the calculator yourself, for example to show totals before checkout:

```php
use Zatca\Services\Tax\InvoiceCalculator;

// $input = your mapper's ZatcaInvoiceInput, e.g. Sara's order from Scenario 1
$calculated = app(InvoiceCalculator::class)->calculate($input);   // ZatcaInvoiceData
$calculated->totalExclVat;             // 350.00
$calculated->totalVat;                 // 52.50
$calculated->totalInclVat;             // 402.50
$calculated->lines[0]->netUnitPrice;   // 150.0 (the headphones' net unit price, as in the XML)
$calculated->lines[0]->suppliedPrice;  // "172.50" (what you passed)
```

`ZatcaInvoiceData` and `ZatcaInvoiceLine` are the **calculated** form the package works with internally (validation, XML, snapshots, notes). You don't build them.

<a id="vat-categories"></a>
#### VAT categories, mixed rates and per-item rates

Most items are standard-rated at `ZATCA_VAT_PERCENTAGE`, and you don't need to do anything for those. One invoice can mix rates and categories; the XML gets one VAT breakdown per rate and category automatically.

```php
use Zatca\DTOs\ZatcaInvoiceItem;

$items = [
    // Standard, at ZATCA_VAT_PERCENTAGE (15%)
    new ZatcaInvoiceItem('Headphones', 1, 172.50),

    // Standard, at its own rate (overrides the default for this item only)
    new ZatcaInvoiceItem('Reduced-rate item', 2, 52.50, vatRate: 5),

    // Zero-rated: never gets VAT, needs ZATCA's reason
    ZatcaInvoiceItem::zeroRated('Export shipment', 1, 200.00, 'VATEX-SA-32', 'Export of goods'),

    // Exempt: never gets VAT, needs ZATCA's reason
    ZatcaInvoiceItem::exempt('Life insurance', 1, 500.00, 'VATEX-SA-29', 'Financial services'),
];
```

| Category | Rate | Needs a reason? | Example codes |
|---|---|---|---|
| `Standard` (`S`) | `ZATCA_VAT_PERCENTAGE`, or the item's `vatRate` | No | – |
| `ZeroRated` (`Z`) | always 0 | Yes | `VATEX-SA-32` export of goods, `VATEX-SA-33` export of services, `VATEX-SA-35` medicines |
| `Exempt` (`E`) | always 0 | Yes | `VATEX-SA-29` financial services, `VATEX-SA-30` residential rent |

Use ZATCA's official list of reason codes. The package checks the `VATEX-SA-...` format and that the reason text is filled in. A zero-rated or exempt item given a rate is refused, and so is a standard item at 0%. Discounts (`ZatcaInvoiceDiscount`) and debit note lines (`DebitNoteLineInput`) accept the same category fields. Credit notes always keep the rate and category of the line they credit, and a debit note line that adjusts an item keeps that item's rate and category unless you set its own.

**The package generates these. Never set them yourself:**

| Field | Where you find it |
|---|---|
| UUID | `zatca_invoices.uuid` |
| Invoice counter (ICV) | `zatca_invoices.icv` |
| Previous invoice hash (PIH) | `zatca_invoices.previous_invoice_hash` |
| Invoice hash | `zatca_invoices.invoice_hash` |
| Unsigned / signed XML | `unsigned_xml`, `signed_xml_base64` |
| Digital signature | inside `signed_xml_base64` |
| QR code | `qr_code` (B2C). For B2B see Scenario 4. |
| Credit / debit note number | `CN-000001` / `DN-000001` on the note record |
| Original invoice reference and reason in notes | taken from the original and from your `reason` |
| Seller name, VAT, CR, address | from `.env` |

<a id="12-more-features"></a>
## More features

### Events

| Event | Fired when | Properties |
|---|---|---|
| `Zatca\Events\ZatcaInvoiceGenerated` | A document was signed (QR ready) | `invoice` |
| `Zatca\Events\ZatcaInvoiceNeedsAttention` | A document needs a human (`manual_review`, `clearance_*` problems) | `invoice`, `reason` |
| `Zatca\Events\ZatcaCreditNoteAccepted` | ZATCA accepted a credit note | `creditNote` |
| `Zatca\Events\ZatcaDebitNoteAccepted` | ZATCA accepted a debit note | `debitNote` |
| `Zatca\Events\ZatcaCertificateExpiring` | A certificate moved to "expiring soon" or "expired" | `egsIdentifier`, `credentialType`, `state`, `expiresAt`, `daysRemaining`, `hasExpired()` |

Acceptance means "ZATCA accepted the document". It does not mean money moved. Never mark anything paid from these events.

Helpers on the note reasons let your listener decide what to do:

```php
$event->creditNote->creditReason()->cancelsItems();   // true = items were returned/cancelled
$event->debitNote->reason_type->addsItems();          // true = a new item was added
$event->debitNote->lines->pluck('item_id');           // your own ids from DebitNoteLineInput::itemId
```

<a id="kill-switch"></a>
### Kill switch (`ZATCA_ENABLED`)

Set `ZATCA_ENABLED=false` to stop **all** ZATCA work without losing anything. That means no new invoices, no counters used, no SDK calls and no requests to ZATCA. Jobs already in the queue exit quietly, and statuses stay as they are. Onboarding and read-only commands still work. Only `false`, `0`, `off` or `no` turns it off. An empty value keeps it on.

```bash
php artisan config:cache
```

```bash
php artisan queue:restart
```

In code, ask the gate (never read the config directly):

```php
use Zatca\Support\ZatcaGate;

app(ZatcaGate::class)->enabled();      // global switch
app(ZatcaGate::class)->b2bEnabled();   // global switch AND ZATCA_B2B_ENABLED
```

Sales made while ZATCA is off are **not** invoiced automatically later. Dispatch their jobs again when you turn it back on.

### B2B switch and readiness

`ZATCA_B2B_ENABLED` is **off unless set** to `true`, `1`, `on` or `yes`. `Zatca\Support\B2bReadiness::ready()` checks the switch, configured B2B EGS, presence of a compliance certificate, and a production certificate with stored state `valid`. It does not inspect the three compliance-test results; verify those during onboarding. Use readiness to show or hide a "Buy as a company" option. Turning B2B off stops new company sales only, and B2B documents already signed still get cleared.

### Eligibility checks for notes

```php
app(\Zatca\Services\CreditNote\CreditNoteEligibilityPolicy::class)->allows($invoice);  // bool
app(\Zatca\Services\DebitNote\DebitNoteEligibilityPolicy::class)->allows($invoice);    // bool
```

`verdict($invoice)` gives you the reason, one of: ZATCA disabled, no document, not an invoice, not accepted yet, no snapshot, certificate expired, another note in progress, fully credited.

To see which lines can still be credited:

```php
$ledger = app(\Zatca\Services\CreditNote\CreditNoteLedger::class)->forOriginal($invoice);
$ledger->availableIndexes;   // e.g. [0] after the charger was returned
```

### Cancelling a note that never reached ZATCA

A note stuck before ZATCA accepted it (for example its generation failed) can be cancelled. That frees its lines for a new note:

```php
use Zatca\Services\CreditNote\CreditNoteCanceller;

$canceller = app(CreditNoteCanceller::class);
if ($canceller->isCancellable($creditNote)) {
    $canceller->cancel($creditNote, ['type' => 'user', 'id' => auth()->id()]);
}
```

`Zatca\Services\DebitNote\DebitNoteCanceller` works the same way. A note that might already be at ZATCA (in flight) cannot be cancelled.

### Note display status

`CreditNoteStatusResolver::resolve()` and `DebitNoteStatusResolver::resolve()` return one simple status for your admin screen: `pending`, `processing`, `accepted`, `rejected`, `failed`, `generation_failed` or `cancelled`.

<a id="re-issuing-a-rejected-invoice-regeneration"></a>
### Re-issuing a rejected invoice (regeneration)

If ZATCA **rejected** a document (`manual_review` with a 4xx, `clearance_rejected`, `clearance_manual_review`), fix the cause (config or code), then re-issue it. You get a **new** document (new UUID and counter) built from the original's frozen data. The old one is marked as replaced.

```php
use Zatca\Jobs\RegenerateZatcaInvoiceJob;
use Zatca\Services\Regeneration\RegenerationPolicy;

if (app(RegenerationPolicy::class)->allows($invoice)) {
    dispatch(new RegenerateZatcaInvoiceJob($invoice->id, ['type' => 'user', 'id' => auth()->id()]));
}
```

The replacement's `source_reference` gets a suffix (`INV-1001#r1`), and `$original->authoritative()` returns it. Transient failures (5xx or network) are **not** regenerable. Those are retried instead.

### Finishing a stuck draft

If signing failed (for example Java was missing), the document stays `draft` with its counter reserved. The next generation retry resumes it automatically. To resume it by hand:

```php
use Zatca\Jobs\CompleteZatcaInvoiceGenerationJob;
use Zatca\Services\Generation\DraftCompletionPolicy;

if (app(DraftCompletionPolicy::class)->allows($draft)) {
    dispatch(new CompleteZatcaInvoiceGenerationJob($draft->id, ['type' => 'user', 'id' => auth()->id()]));
}
```

<a id="certificates"></a>
### Certificates

- `zatca:certificate:check` (daily) updates each certificate's state (`valid`, `expiring_soon` from 60 days before expiry, or `expired`) and fires `ZatcaCertificateExpiring` once per change (so once at "expiring soon" and once at "expired").
- When the **production** certificate is expired, sending stops for that EGS, and invoices wait safely.
- **Renewal is turned off.** ZATCA's renewal API has not been verified in this package, so `zatca:certificate:renew` refuses to call ZATCA while `ZATCA_AUTO_RENEWAL_ENABLED=false`. Until then, renew by onboarding again with a new OTP (steps 1 to 4 of [Onboarding](#8-zatca-onboarding)) before the certificate expires. **Test this in simulation first.**

<a id="custom-bindings"></a>
### Custom bindings

| Interface | Default | Bind your own when… |
|---|---|---|
| `Zatca\Contracts\InvoiceSourceContract` | **none, you must bind it** | Always (your mapper) |
| `Zatca\Contracts\InvoiceSourceResolverContract` | `EloquentInvoiceSourceResolver` (`Model::find`) | Your source isn't a plain Eloquent model |
| `Zatca\Contracts\CredentialEncrypterInterface` | `LaravelCredentialEncrypter` (`APP_KEY`) | You want Vault/KMS |
| `Zatca\Contracts\OwnerResolverInterface` | `NullOwnerResolver` (returns `null`) | Multi-tenant: fill `zatca_invoices.owner_id` per EGS |

```php
$this->app->bind(\Zatca\Contracts\CredentialEncrypterInterface::class, \App\Zatca\KmsEncrypter::class);
```

### Reading state in code

```php
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\State\EgsRegistry;

app(ZatcaInspector::class)->credentials('default'); // credential types, cert state, expiry (no secrets)
app(ZatcaInspector::class)->state('default');       // current ICV and a hash preview
app(ZatcaInspector::class)->invoice('42');          // by id or UUID (no XML)

app(EgsRegistry::class)->identifiers();             // ['default', 'b2b']
app(EgsRegistry::class)->primary();                 // EgsConfig of the B2C EGS
```

`Zatca\Services\Security\ZatcaSanitizer` is used internally to remove tokens, secrets and keys from logs and stored responses. You can use it for your own logs.
