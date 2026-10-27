# VAT Guide

How the package turns the prices and quantities you give it into net amounts, VAT, and invoice totals — with worked examples beyond the ones in the README. For the `.env` settings themselves, see README [§6 VAT settings](CONFIGURATION.md#vat-settings). All the math here comes from `Zatca\Services\Tax\InvoiceCalculator`, the one place in the package that calculates VAT.

---

## 1. Inclusive vs. exclusive, in one sentence each

- **`ZATCA_VAT_MODE=inclusive`** (the default): the price you pass **already contains** VAT. A 115.00 SAR item at 15% is net 100.00 + VAT 15.00.
- **`ZATCA_VAT_MODE=exclusive`**: the price you pass is **before** VAT. A 100.00 SAR item at 15% becomes net 100.00 + VAT 15.00 = 115.00 charged.

You set one mode per EGS in config, but any individual B2B invoice can override it per call via `priceMode:` on `ZatcaInvoiceInput` (see README §9.5) — useful because consumer-facing prices are usually VAT-inclusive while B2B quotes are usually VAT-exclusive.

## 2. The rounding rule, exactly

Everything is calculated in exact integer arithmetic (halalas — hundredths of a riyal), rounded half-up, never in floating point. In order:

1. The price you supply can have up to 6 decimal places.
2. **The line's base amount is `quantity × price`, rounded once for the whole line** — not per unit, then multiplied. Ten units at 100.00 round once as 1,000.00, not as ten separately-rounded 100.00s.
3. **Exclusive mode:** `net = base`; `VAT = net × rate` (rounded).
   **Inclusive mode:** `net = base ÷ (1 + rate)` (rounded); `VAT = base − net` — the *remainder*, not a second multiplication. This guarantees `net + VAT` always equals exactly what was charged, even though it means `VAT` can differ from `net × rate` by at most one halala (which ZATCA's own line-level tolerance allows).
4. **Zero-rated and exempt items always get VAT = 0**, regardless of mode — their "net" is just the base amount.
5. The unit price written into the XML is `net ÷ quantity`, to 6 decimals, so that `quantity × unit price` reproduces the line's net amount exactly (ZATCA rule `BR-KSA-EN16931-11`).
6. **A discount is calculated exactly like a one-unit line**, in the same mode and rate rules as above.
7. **Document totals are the sum of the already-rounded line and discount figures** — never recalculated from a grand total (ZATCA rules `BR-CO-10`/`BR-CO-14`/`BR-CO-15`).

## 3. Worked example: a mixed-category invoice

Not every line on an invoice has to be taxed the same way. Here's one invoice with all three VAT categories, VAT-inclusive, default 15% rate:

| Line | Item | Category | Price you pass | Net (calculated) | VAT (calculated) |
|---|---|---|---|---|---|
| 0 | Gift bag (standard-rated) | `S` | 115.00 | 100.00 | 15.00 |
| 1 | Zero-rated item | `Z` | 50.00 | 50.00 | 0.00 |
| 2 | Exempt item | `E` | 30.00 | 30.00 | 0.00 |
| | **Totals** | | | **180.00** | **15.00** (total incl. VAT: **195.00**) |

```php
use Zatca\DTOs\ZatcaInvoiceItem;

$items = [
    new ZatcaInvoiceItem(description: 'Gift bag', quantity: 1, price: 115.00),   // standard, default rate
    ZatcaInvoiceItem::zeroRated(
        description: 'Zero-rated item', quantity: 1, price: 50.00,
        exemptionReasonCode: 'VATEX-SA-32', exemptionReason: 'Qualifying export',
    ),
    ZatcaInvoiceItem::exempt(
        description: 'Exempt item', quantity: 1, price: 30.00,
        exemptionReasonCode: 'VATEX-SA-29', exemptionReason: 'Exempt financial service',
    ),
];
```

Both `zeroRated()` and `exempt()` **require** an exemption reason code and text (ZATCA rule `BR-KSA-69`) — it's not optional for either category. See README [§10 VAT categories table](INTEGRATION_REFERENCE.md#vat-categories) for real ZATCA exemption codes.

Zero-rated and exempt items **never accept a nonzero rate** — passing one throws a `ZatcaException` naming the line (`rateFor()` refuses it on purpose, so a mapper bug can't silently tax an exempt item).

## 4. Worked example: a discount that spans different VAT categories

Document-level discounts (`ZatcaInvoiceDiscount`) take the VAT treatment of the line(s) they cover — but only when those lines **agree**. This is a real gotcha worth knowing before you use `lineIndexes` on a mixed invoice.

Take two lines: a standard-rated item (115.00 inclusive → net 100.00, VAT 15.00) and a zero-rated item (50.00, no VAT). Now apply a 23.00 SAR discount across both, without telling the package what rate to use:

```php
use Zatca\DTOs\ZatcaInvoiceDiscount;

discounts: [
    new ZatcaInvoiceDiscount(reason: 'Loyalty discount', amount: 23.00, lineIndexes: [0, 1]),
],
```

Because the two covered lines have **different** treatments (`S` at 15% vs. `Z` at 0%), the package can't infer a single rate — it falls back to **standard-rated at the configured default rate**, not a blended or zero rate:

| | Net | VAT |
|---|---|---|
| Item 0 (standard) | 100.00 | 15.00 |
| Item 1 (zero-rated) | 50.00 | 0.00 |
| Discount (falls back to standard 15%) | −20.00 | −3.00 |
| **Document total** | **130.00** | **12.00** (total incl. VAT: **142.00**) |

If that's not what you want — say, the discount should really be VAT-free because it's mostly against the zero-rated item — set `vatCategory:`/`vatRate:` on the `ZatcaInvoiceDiscount` explicitly rather than relying on inference. The same fallback applies if you don't pass `lineIndexes` at all: with no explicit category and lines that don't all agree, the discount defaults to standard-rated.

## 5. Worked example: crediting part of a B2B exclusive-priced line

README §9.3 shows a price-adjustment credit note on a VAT-inclusive B2C line. The same `issuePriceAdjustment()` call works identically for an exclusive-priced B2B line — the amount is read in **whatever price mode the original line was invoiced in**, frozen at the time that invoice was generated.

Original line: 20 office chairs at 400.00 SAR **exclusive** of VAT (net 8,000.00, VAT 1,200.00 at 15%). A defect is found in 5 of them, and 460.00 SAR (excl. VAT) is credited back:

```php
use Zatca\Services\CreditNote\CreditSource;

app(CreditNoteIssuer::class)->issuePriceAdjustment(
    original: $original,
    sourceKey: CreditSource::invoiceLineKey(0),
    amount: 460.00,                // read in the line's own mode: exclusive
    description: 'Minor defect found in 5 units',
    reason: 'Quality adjustment after delivery inspection',
    idempotencyKey: (string) Str::uuid(),
);
```

Because this line was invoiced exclusive, the adjustment splits the same way an exclusive line would: `net = amount = 460.00`; `VAT = net × 15% = 69.00`. The credit note totals **460.00 net + 69.00 VAT = 529.00 incl. VAT**. If you later change `ZATCA_VAT_MODE` or `ZATCA_VAT_PERCENTAGE` in `.env`, this credit note is unaffected — the original invoice's mode and rate were frozen in it when it was generated, and every note against it uses that frozen data, not today's config.

## 6. Frequently asked questions

**Why don't my net and VAT exactly multiply out to the rate?**
In inclusive mode, VAT is calculated as the *remainder* (`charged amount − net`), not as a second multiplication, so that net + VAT always equals exactly what was charged (see rule 3 above). It can differ from a naive `net × rate` by at most one halala — this is expected and within ZATCA's own tolerance.

**Can one item on an invoice have a different VAT rate than the rest?**
Yes — `vatRate:` on an individual `ZatcaInvoiceItem` overrides the configured default for that line only. This only applies to standard-rated items; zero-rated and exempt items are always 0% and reject an explicit rate.

**What happens to old invoices when I change `ZATCA_VAT_PERCENTAGE` or `ZATCA_VAT_MODE`?**
Nothing — a signed invoice has its rate and mode frozen in it forever. A config change only affects invoices generated *after* the change; its credit notes, debit notes, and any regeneration continue to use the rates that were frozen in the original. See README's [VAT settings](CONFIGURATION.md#vat-settings).

**Can quantity be a fraction, like 1.5 kg?**
No — `quantity` must be a whole number of at least 1 (`InvoiceCalculator::line()` rejects anything less than 1). If you sell by weight or another fractional unit, fold the fraction into the unit price instead (e.g. price per kg × 1, described as "1.5kg of X").

**Does the package support a currency other than SAR?**
No — `currency` on `ZatcaInvoiceInput` must be `'SAR'`; this is a known limitation, not a configurable option (see README [§16 Known limitations](LIMITATIONS.md#known-limitations)).
