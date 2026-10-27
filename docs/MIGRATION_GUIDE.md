# Pre-release API and database migration guide

<a id="17-renamed-classes-and-methods"></a>
## Renamed classes and methods

Some names used to be specific to one kind of business. They now have generic names. The old **PHP** names still work as deprecated aliases.

| Old name (deprecated) | New name |
|---|---|
| `ZatcaCreditNoteReason::CourseCancellation` | `ZatcaCreditNoteReason::ItemCancellation` |
| `ZatcaCreditNoteReason::revokesCourseAccess()` | `ZatcaCreditNoteReason::cancelsItems()` |
| `ZatcaDebitNoteReason::AdditionalCourse` | `ZatcaDebitNoteReason::AdditionalItem` |
| `ZatcaDebitNoteReason::grantsCourseAccess()` | `ZatcaDebitNoteReason::addsItems()` |
| `DebitNoteLineInput` `courseId:` argument and `->courseId` | `itemId:` argument and `->itemId` |
| `CreditSource` `courseId` | `itemId` |
| `$line->course_id` on note lines (read-only) | `$line->item_id` |

The database uses the new names too:

| Old | New |
|---|---|
| `reason_type` value `course_cancellation` | `item_cancellation` |
| `reason_type` value `additional_course` | `additional_item` |
| `course_id` column on `zatca_credit_note_lines` / `zatca_debit_note_lines` | `item_id` |
| `course_ids` in the debit note "requested" event detail | `item_ids` |

These were changed inside the original migrations, because the package had not run in production. **Any database that already ran the old migrations (local, staging) must be rebuilt** with `php artisan migrate:fresh`. Never do that on production.

### VAT calculation moved into the package (breaking, before the first release)

The package now calculates VAT from prices (see [VAT settings](CONFIGURATION.md#vat-settings)). There are no aliases for these: the old signatures took amounts the package now calculates.

| Before | Now |
|---|---|
| `InvoiceSourceContract::toInvoiceData(): ZatcaInvoiceData` | `toInvoiceInput(): ZatcaInvoiceInput` |
| `ZatcaInvoiceLine` / `ZatcaAllowanceCharge` built by your app, with net, VAT and totals | `ZatcaInvoiceItem` / `ZatcaInvoiceDiscount` with prices. The package builds the calculated DTOs. |
| `ZatcaInvoiceLine::$unitPrice` | `$netUnitPrice` (plus `$suppliedPrice` and `$priceMode`) |
| `new DebitNoteLineInput($desc, $qty, $unitPrice, $vatRate, $net, $vat, ...)` | `new DebitNoteLineInput($desc, $qty, $price, vatRate: ..., originalLineIndex: ...)`, and `DebitNoteLineInput::vatAdjustment()` for VAT-only lines. `fromArray()` reads `price` (and `vat_only`). |
| `issuePriceAdjustment(..., $netAmount, $vatAmount, ...)` and `new PriceAdjustmentInput($key, $net, $vat, $desc)` | one `$amount`: `issuePriceAdjustment(..., $amount, ...)`, `new PriceAdjustmentInput($key, $amount, $desc)` |
| `config('zatca.egs.*.vat_percentage')`, `EgsConfig::$vatPercentage` | `config('zatca.vat.percentage')` and `config('zatca.vat.mode')` |

The `unit_price` column of `zatca_credit_note_lines` and `zatca_debit_note_lines` now has 6 decimals (`decimal(18,6)`), also changed in the original migrations: rebuild local and staging databases as above.
