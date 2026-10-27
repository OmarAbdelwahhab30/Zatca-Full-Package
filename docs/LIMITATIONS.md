# Known limitations and verification gaps

<a id="known-limitations"></a>
<a id="16-known-limitations"></a>
## Known limitations

These come from the current code. They are not documentation mistakes.

**What the developer-portal sandbox proved, and what it cannot**

`composer test:sandbox` (see [Sandbox tests](TESTING.md#sandbox-tests)) runs onboarding and every B2C and B2B document against ZATCA's real developer-portal sandbox. All of them are accepted with no warnings. That confirms:
- The API contract: endpoints, `Accept-Version: V2`, `Clearance-Status`, request bodies (including `uuid`), and Basic auth.
- The XML passes ZATCA's checks: XSD, EN 16931 and KSA rules, for all six documents.
- The B2B EGS onboards with `ZATCA_B2B_INVOICE_TYPE=1000`, and its compliance documents come back `CLEARED`.
- ZATCA's cleared copy keeps the same UUID and adds a QR code.

The sandbox is lenient, so these still need ZATCA's **simulation** environment, with your real company data and OTP (project gates G-02 to G-07):
1. **The production signature.** The sandbox's production certificate is shared and does not match your key, yet the sandbox accepts the invoice anyway. So the sandbox does not check it.
2. **Refusals.** The sandbox accepts any OTP and issues a production certificate even before the compliance checks. These refusals are covered by local tests instead (`OnboardCommandFailuresTest`).
3. Whether ZATCA really accepts `1000` (or wants `1100`) for a separate B2B device.
4. Which hash continues the chain after a cleared invoice, and rejected documents in the chain. The sandbox accepted the package's chain, but may not check it strictly.
5. Real `NOT_CLEARED` and warning responses for Clearance.
6. Which XML and QR to give the B2B buyer (the package stores ZATCA's `cleared_xml_base64`).
7. What ZATCA returns when a timed-out (`clearance_in_doubt`) document is sent again.

Run `composer test:sandbox`, then onboard both EGSs in simulation and clear a few invoices, credit notes and debit notes there, before you set `ZATCA_B2B_ENABLED=true` in production.

The package does not check that the three compliance checks passed before it asks for the production certificate. It relies on ZATCA to refuse. Real ZATCA does refuse, but the sandbox does not.

**Tax and amounts**
- `Z` and `E` are supported. Out-of-scope (`O`) is not, because ZATCA limits how it can be mixed with other categories on one document.
- Every amount is in **SAR**. Other currencies are refused.
- `quantity` is a whole number. Fractions (1.5 kg) are not supported.
- No line-level discounts. Only document-level discounts (`ZatcaInvoiceDiscount`).
- One price mode per invoice (`priceMode`), not per item.
- The net unit price in the XML (`PriceAmount`) can have up to 6 decimals, so that quantity × price matches the line net. ZATCA's SDK 3.4.8 rules accept this (tested with the SDK's own validator), and so does the developer-portal sandbox (for example 2 × 19.99 VAT-inclusive, a net unit price of 17.385). Simulation and production have not been tried with it yet.
- Payment means is always code `10` (cash).

**Notes**
- `CreditNoteIssuer::issue()` credits whole lines. Use price adjustments to credit part of a line.
- One credit or debit note in progress per invoice at a time.

**Other**
- Laravel 12 is recommended. Laravel 9, 10 and 11 work but no longer get security fixes from Laravel, and need an explicit Composer override to install. See [Laravel compatibility](SERVER_REQUIREMENTS.md#laravel-compatibility).
- Real ZATCA SDK signing is not covered by the default automated suite (the real-SDK tests skip unless an SDK is configured, and the sandbox tests are opt-in). With SDK 3.4.8 enabled, the real-SDK tests passed on Laravel 9 to 12 and PHP 8.1 to 8.4 (2026-10-20). The sandbox tests were last run on Laravel 10 / PHP 8.2.
- Certificate renewal through the API is turned off. Renew by onboarding again.
- No PDF generation.
