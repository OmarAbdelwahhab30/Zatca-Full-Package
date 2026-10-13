# References

## Official ZATCA sources

| Source | Where | Used for |
|---|---|---|
| Clearance API — "e-Invoicing Sandbox Release (2.1.0)", ZATCA Integration Sandbox (page last updated 22 April 2025) | https://sandbox.zatca.gov.sa/IntegrationSandbox/clearance-api (supplied by the user on 2026-09-28) | G-03, G-04, G-06, G-07, G-16 — see the Facts Register (P01) |
| Compliance CSID, Compliance Invoice, Production CSID and Reporting API documents | the uploaded set kept with the other ZATCA documents (outside the repository) | blueprint Appendix C.2 |
| Still missing: XML Implementation Standard + Data Dictionary, Detailed Technical Guidelines, Developer Portal User Manual (§4.3 CSR, §2.3.10 sandbox), clearance switch-off procedure | to obtain (P00/P01) | G-01, G-02, G-16, G-17, G-18, G-22 |

## Product sources

* User story #33496 "Select Invoice Buyer Type During Checkout" — `B2B-User-Stories/First.txt`. Two documents it cites are not available: "Pasted text(20260916-144013)" and "lms_business_rules_admin_student" (G-25).
* User story #33502 "Company Information & Commercial Registration Verification" — `B2B-User-Stories/Second.txt`, read 2026-09-28; traced in blueprint §17.7; open point D-45.

## Plan

* Blueprint: [`blueprint.md`](blueprint.md) and https://claude.ai/artifact/UnYTRr3g4pVcZZxNLX7Dw2.

## Facts taken from the uploaded official documents (blueprint Appendix C.2)

1. Reporting endpoint `POST /invoices/reporting/single` "Reports a single SIMPLIFIED invoice, credit note, or debit note"; headers `Clearance-Status` (0/1), `Accept-Version: V2`; responses 200/202/400/401/406/409/500 with shapes — *Reporting API (ISB 2.1.0)*.
2. Compliance Invoice API checks Standard invoice/CN/DN and Simplified invoice/CN/DN; response includes `clearanceStatus` — *Compliance Invoice API*.
3. `ClearedInvoiceResultModel {invoiceHash, clearedInvoice, status Cleared/Not Cleared, warnings, erros}`; `InfoModel` for clearance flag on/off — *schema sections of the API PDFs*.
4. ISB does not cover exceptional backend handling such as overriding the clearance process — *all four PDFs, intro*.
5. KSA-2 structure NNPNESB (01 tax invoice, 02 simplified) — *BR-KSA-06 example error in the Reporting API doc*.
6. Seller address example error BR-KSA-09 listing additional number (KSA-23) etc. — *Compliance Invoice API example*.
7. Standard invoice/CN/DN sample structure: `0100000`, buyer PostalAddress → PartyTaxScheme → PartyLegalEntity, `cac:Delivery/ActualDeliveryDate`, BillingReference and InstructionNote for notes, QR present, ProfileID `reporting:1.0` — *SDK 3.4.8 samples*.
8. `csr.invoice.type` exists; example value `1100` — *SDK CSR templates*.
