# Invoice Types: Which One Do I Need?

The package supports six ZATCA document types. This page helps you decide which one applies to a given sale, with real business scenarios. For the formal definitions, codes and enum values, see the README's [table of the six documents](../README_SIMPLE.md#understand-the-two-flows). For working code, see [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md) and the README's [§9 Complete usage examples](../README_SIMPLE.md#create-and-read-invoices).

---

## 1. B2C vs. B2B: it's about the buyer, not the sale size

The single question that decides everything else is: **who is buying?**

| | B2C (Simplified) | B2B (Standard) |
|---|---|---|
| Buyer | A person, buying for themselves | A company (or another VAT-registered entity) |
| Document type | `Invoice` (simplified tax invoice) | `StandardInvoice` |
| Sent by | **Reporting** (give it to the buyer, then tell ZATCA) | **Clearance** (ZATCA approves it *before* the buyer sees it) |
| Buyer details needed | None — a name is optional | Legal name, and either a VAT number or another ID (CR number), plus a full address |

It is **not** about the amount of the sale, how it was paid, or whether it "feels" like a business transaction. A freelance designer buying one laptop for themselves is B2C. A small shop buying ten notebooks on a company account is B2B, even if the total is smaller than the designer's laptop.

### Real scenarios

| Scenario | Type | Why |
|---|---|---|
| Sara, a walk-in customer, buys headphones at a retail counter | B2C | She's buying as an individual |
| Riyadh Office Supplies Co. orders 20 chairs, invoiced to their CR number | B2B | The buyer is a registered company |
| A restaurant sells a meal to a diner who pays by card | B2C | The diner is a person, regardless of payment method |
| A wholesale furniture business sells a bulk order to another furniture retailer | B2B | Buyer is a company reselling the goods |
| A SaaS company sells a subscription to a freelancer who gives only their personal name | B2C | No company identity was given — treat as an individual sale unless your checkout explicitly captures a company VAT/CR number |
| The same SaaS company sells a subscription to "Al Noor Trading Co." with their VAT number | B2B | A company identity was captured |

**The deciding signal in your own code is usually a flag you already have** — `buyer_type`, "is this a company account?", or whether a VAT/CR number was captured at checkout — not anything ZATCA tells you. See the `OrderInvoiceSource` example in the README's [§9.1](../README_SIMPLE.md#connect-your-laravel-app) for exactly how that flag selects `documentType`.

### A company that buys like an individual

If a company buys something but your checkout never captured their VAT number or CR number, you have two honest choices: treat it as B2C (no company identity was given), or require that information before checkout completes if B2B invoicing matters to your business. The package does not guess — `BuyerParty` requires either a VAT number or another ID scheme (see `BuyerOtherId::commercialRegistration()`), and B2B generation will fail its validation if neither is present.

## 2. Reporting vs. Clearance: a consequence, not a separate choice

You never pick "Reporting" or "Clearance" directly — the **document type** you choose (driven by who the buyer is) determines it automatically:

| | Reporting (B2C) | Clearance (B2B) |
|---|---|---|
| When is ZATCA contacted? | *After* the buyer already has the invoice | *Before* the buyer gets anything |
| Who gives the buyer their invoice? | You, immediately at the point of sale | ZATCA's stamped, cleared copy — it's the legal invoice, not your draft |
| Time limit | ZATCA's rule is "within 24 hours"; the package does it within seconds via the queue | No fixed external deadline, but the buyer is waiting on it |
| What if ZATCA rejects it? | The sale already happened; you fix the cause and regenerate | The buyer never got an invoice at all — nothing to "undo" |

This is why the field names differ: a B2C `ZatcaInvoice` has `reported_at`, a B2B one has `cleared_at` and `clearance_status`. See README [§11 Understanding ZATCA responses](RESPONSES.md#11-understanding-zatca-responses) for the full status tables.

## 3. Credit notes vs. debit notes

Once ZATCA has accepted (reported or cleared) an invoice, you can never edit it. Instead, you issue a **note** that references it.

| | Credit note | Debit note |
|---|---|---|
| Effect | **Lowers** what the invoice says is owed | **Raises** what the invoice says is owed |
| Typical real reasons | Product returned, partial refund, price was too high, item cancelled | Forgot to charge for something, price was too low, VAT was under-charged |
| ZATCA code | 381 | 383 |
| Follows the flow of | Its original invoice — a note on a B2C invoice is reported; a note on a B2B invoice is cleared | Same rule |

### Real scenarios

| Situation | Note type | How |
|---|---|---|
| Sara returns a USB-C charger from her order | Credit note, full line | `CreditNoteIssuer::issue()` with the charger's line index |
| Sara keeps her headphones but gets 23 SAR back for cosmetic damage | Credit note, price adjustment | `CreditNoteIssuer::issuePriceAdjustment()` |
| Al Noor forgot to charge for gift wrapping | Debit note, additional item | `DebitNoteIssuer::issue()` with `ZatcaDebitNoteReason::AdditionalItem` |
| A B2B invoice line was priced too low and needs a correction | Debit note, price adjustment | `ZatcaDebitNoteReason::PriceAdjustment`, referencing `originalLineIndex` |
| VAT was undercharged on a line | Debit note, VAT adjustment | `DebitNoteLineInput::vatAdjustment()` |
| A customer cancels part of their order before it ships, no money changed hands on the invoice yet | Neither — this isn't a note | Notes correct an *accepted ZATCA invoice*. If nothing was reported/cleared yet, cancel or adjust the sale in your own system before it's ever generated. |

### Rules that shape which note you can issue

- **The original must already be accepted** (`reported`/`cleared`, or their "with warnings" variants) — you can't issue a note against a draft or a rejected invoice.
- **Only one credit *or* debit note can be in progress per invoice at a time.** You can't fire off two refunds for the same invoice simultaneously; the second call is refused until the first resolves. See `CreditNoteEligibilityPolicy`/`DebitNoteEligibilityPolicy` in README [§12](INTEGRATION_REFERENCE.md#12-more-features).
- **`issue()` on a credit note always credits whole lines.** To credit *part* of a line's value (a partial refund, a damage discount), use a price adjustment instead — see [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md).
- **Reducing a line to zero is a return, not an adjustment** — use `issue()`, not `issuePriceAdjustment()`, when the customer is giving the item back entirely.
- **Debit notes have no such "one line, one adjustment" ceiling** — multiple debit notes against the same original line are allowed; only the idempotency key prevents accidental duplicates.

## 4. Full vs. partial credit notes

| | Full | Partial |
|---|---|---|
| `ZatcaCreditNoteType` | `Full` | `Partial` |
| Covers | Every line still available on the invoice | Specific line indexes you choose |
| Typical use | The whole order is cancelled/returned | Only some items came back |

## 5. VAT category per item (not per invoice)

An invoice isn't necessarily "all standard-rated" or "all zero-rated" — each `ZatcaInvoiceItem` carries its own VAT category:

| Category | Code | Example |
|---|---|---|
| Standard-rated | `S` | Most retail goods and services, at 15% (or your configured rate) |
| Zero-rated | `Z` | Categories ZATCA defines as taxed at 0%, e.g. qualifying exports |
| Exempt | `E` | Categories ZATCA exempts from VAT entirely — requires an exemption reason code |

A single invoice can legitimately mix all three — see [VAT_GUIDE.md](VAT_GUIDE.md) for the worked math on a mixed-category invoice.
