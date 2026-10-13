# Verified Facts Register

Phase P01. Every ZATCA requirement a B2B phase encodes must point to an entry here (governance rule R-DOC). An entry records only what the cited official source says; interpretation is marked as such.

**Entry types:** *Rule* — a normative statement in an official source. *Contract* — an API path, header, field, code or schema. *Example* — sample content in an official source; it shows one valid shape and is **never** used as a rule. *Note* — context from an official source.

**Status:** *Recorded* — read from the source by the recorder, reviewer pending. *Reviewed* — a second person checked it against the source. *Superseded* — a later source replaces it.

## Sources

| ID | Source | Version / date | Location | Read on |
|---|---|---|---|---|
| S-CL | ZATCA Integration Sandbox — "واجهة اعتماد الفواتير" (Clearance API), swagger "e-Invoicing Sandbox Release (2.1.0)", API version 1.0.0, OAS 3.0 | page footer: last updated 22 April 2025 | https://sandbox.zatca.gov.sa/IntegrationSandbox/clearance-api | 2026-09-28 |
| S-UP | Uploaded official API documents: Compliance CSID, Compliance Invoice, Production CSID, Reporting (ISB 2.1.0) | as uploaded | kept with the other ZATCA documents, outside the repository | during the planning investigation |
| S-SDK | ZATCA SDK 3.4.8 samples and CSR templates | 3.4.8 | the SDK installation used by the package | during the planning investigation |
| S-KSA | KSA validation rules shipped with the official ZATCA SDK: `Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl` (the KSA rule set the APIs validate against — F-CL-05) | file dated 2021-08-19, in SDK R3.4.8 (`zatca-einvoicing-sdk-238-R3.4.8.jar`) | the SDK installation (`ZACTA-SDK-JAVA`) | 2026-09-28 |

## Clearance API (source S-CL)

| ID | Type | Fact (as stated by the source) | Gate | Status |
|---|---|---|---|---|
| F-CL-01 | Contract | `POST /invoices/clearance/single` — "Clears a single Standard invoice, credit note, or debit note." It accepts the document base64-encoded. Sandbox server listed: `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal`. The simulation and production base URLs are not on this page. | G-03 | Recorded |
| F-CL-02 | Contract | `Authorization` header: username is `binarySecurityToken` and password is `secret`, both "taken from the Production certificate response" (i.e. the production CSID, not the compliance CSID). | G-03 | Recorded |
| F-CL-03 | Contract | Headers: `Clearance-Status` — required; "0" when clearance is disabled, "1" when enabled. `Accept-Version` — required; **the page does not state its value**. `accept-language` — optional, `en` or `ar`, defaults to English. | G-03 (value of `Accept-Version` stays open) | Recorded |
| F-CL-04 | Contract | Request body schema `InvoiceRequest` lists `invoiceHash` and `invoice` (base64). The page's own request example also sends `uuid`. **Discrepancy** between schema and example — resolved only by a sandbox call (P28). | G-03 (open part) | Recorded |
| F-CL-05 | Rule | Before clearing, the API validates: compliance with the UBL 2.1 XSD; EN 16931 rules; KSA rules ("KSA Rules set will override EN 16931 Rules set in case the same rule exists in both sets"); QR code (if any); cryptographic stamp (if any); previous invoice hash (PIH). "On successful validation, the API then signs the invoice, applies a QR code and returns back." | G-06 (documentary part), G-05 (PIH is validated; next-PIH semantics still open) | Recorded |
| F-CL-06 | Contract | Responses: **200** "Returned on successful clearance of submitted invoice" (example `clearanceStatus: CLEARED`, validation `PASS`); **202** "clearance of submitted invoice with warnings" (`CLEARED`, `WARNING`, example warning `BR-KSA-51`); **208** "Invoice Hash Previously Submitted"; **303** "Returned when the submitted invoice is a Standard Invoice while clearance is deactivated"; **400** invalid request (examples: invalid hash, invalid base64, missing hash, missing invoice, XML schema error, EN rules violation, KSA rules violation; `NOT_CLEARED`, `clearedInvoice: null`); **401** username/password missing or wrong; **500** internal error (`category: HTTP-Errors`, `code: 500`). No 406 or 409 is documented on this page. | G-04 (documentary part; real bodies captured in P29) | Recorded |
| F-CL-07 | Contract | 208 example body: `clearanceStatus: CLEARED` with a `clearedInvoice` and validation `PASS`. Behaviour for the same UUID with a different hash is not described. | G-07 (partial) | Recorded |
| F-CL-08 | Contract | 303 example body: `{"message": "Clearance is deactiviated. Please use the /invoices/reporting/single endpoint instead."}` (spelling as in the source). The switch-off procedure itself is not described. | G-16 (partial) | Recorded |
| F-CL-09 | Contract | Response schema `ClearedInvoiceResultModel { validationResults, clearedInvoice (string), clearanceStatus (enum CLEARED, NOT_CLEARED) }`; `validationResultsModel { infoMessages, warningMessages, erroMessages, status (enum PASS, WARNING, ERROR) }`. **Discrepancy:** the schema spells `erroMessages` while every example body uses `errorMessages`; the parser must follow what the sandbox actually returns (P29) and tolerate both until then. Messages carry `type`, `code`, `category`, `message`, `status`. | G-04 | Recorded |
| F-CL-10 | Example | The Standard-invoice request example contains `ProfileID` `reporting:1.0`, `InvoiceTypeCode name="0100000"` `388`; buyer `cac:AccountingCustomerParty/cac:Party` with `PostalAddress` (StreetName, BuildingNumber, CitySubdivisionName, CityName, PostalZone, Country/IdentificationCode `SA`), `PartyTaxScheme/CompanyID` (a VAT number) and `PartyLegalEntity/RegistrationName`; `cac:Delivery/cbc:ActualDeliveryDate`; `PaymentMeansCode` `10`. **An example only** — it does not establish which buyer fields are mandatory, their order rules, or the payment-means code to use. | none closed (G-01, G-22 stay open) | Recorded |
| F-CL-11 | Rule | "validations which can result in an UBL XSD error also apply to optional fields if the tag is present and data input is not compliant. This includes leaving such fields blank. However if the tag itself is absent then the validations will not be performed." Consequence for D-40/BR-37: an empty optional buyer field must be **omitted** from the XML, never emitted as an empty tag. | G-01 (supporting) | Recorded |
| F-CL-12 | Note | The Integration Sandbox simulates most production functions, but "scenarios involving any backend exceptional handling (for example overriding the clearance process) will not be part of the ISB"; the ISB "should not be considered as representative of all integrations and/or APIs" of production. | G-16 | Recorded |
| F-CL-13 | Note | The page refers developers to section 2.3.10 of the Developer Portal User Manual for additional guidance. That manual is not in hand. | G-02 | Recorded |
| F-CL-14 | Rule | Integration Sandbox overview (https://sandbox.zatca.gov.sa/IntegrationSandbox/compliance-csid, sandbox release 2.1.1, read 2026-09-28): the requirements listed for the Compliance CSID API are only "a private/public key pair" and "a signed CSR" — no Fatoora-portal OTP is listed for the sandbox. The page states sandbox documents are for testing only and are not valid with ZATCA. Real portal OTPs apply to simulation/production onboarding. The exact OTP header value the sandbox accepts is not on the overview page (the swagger section did not load); the package's `zatca:onboard compliance` requires `--otp` for every environment, as it did for the B2C EGS. | G-02, P27 (no portal OTP needed for sandbox) | Recorded |
| F-CL-15 | Note | The same page links the Developer Portal User Manual: `/User_Manual_Developer_Portal_Manual_Version_3.pdf` on sandbox.zatca.gov.sa — the document gates G-02/G-13 wait for (§2.3.10, §4.3). Not downloaded yet (needs the user's approval). | G-02, G-13 | Recorded |

## KSA validation rules (source S-KSA)

| ID | Type | Fact | Gate / phase | Status |
|---|---|---|---|---|
| F-SDK-01 | Rule | BR-KSA-06: "The invoice transaction code (KSA-2) must exist and respect the following structure: NNPNESBCG", positions 1–2 being the invoice subtype — `01` for tax invoice, `02` for simplified tax invoice — and positions 3–9 0/1 flags (3rd party, nominal, exports, summary, self-billed, continuous supply, B2G). The rule's test accepts 7, 8 or 9 characters. Hence `0100000` for a Standard document and `0200000` for a Simplified one with no flag set. | P16 (`transactionCodeName()`) | Recorded |
| F-SDK-02 | Rule | BR-KSA-05: the invoice type code (BT-3) must be one of the UN/CEFACT 1001 (D.16B) subset agreed for KSA, per paragraph 11.2.1 of the XML Implementation Standard (not in hand). The rule does not depend on the subtype; the official Standard samples use 388/381/383 exactly as the Simplified ones (F-UP-07). | P16 (`invoiceTypeCode()`) | Recorded |
| F-SDK-03 | Rule | BR-KSA-31: for Simplified tax invoices and their notes (KSA-2 positions 1–2 = `02`), the self-billing flag (position 7) is not allowed. Not used by B2B; the Standard code never sets it either. | none | Recorded |
| F-SDK-04 | Rule | BR-KSA-14: the other buyer ID (BT-46), required only if the buyer is not VAT registered, must carry one of the scheme IDs (BT-46-1) TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS, OTH, and contain only alphanumeric characters. BR-KSA-F-12: the scheme ID must not contain spaces. | G-01 (scheme list — closed), P20, P45 | Recorded |
| F-SDK-05 | Rule | BR-KSA-81: in a tax invoice and its credit/debit notes (KSA-2 positions 1–2 = 01) the other buyer ID (BT-46) must be present where the buyer VAT number (BT-48) is not provided. | G-01, P22, P45 | Recorded |
| F-SDK-06 | Rule | BR-KSA-44: if present, the buyer VAT number (BT-48) has 15 digits, first and last digit 3. | G-01, P45 | Recorded |
| F-SDK-07 | Rule | BR-KSA-63: if the buyer country code (BT-55) is SA, these are mandatory: street name (BT-50), building number (KSA-18), postal code (BT-53), city (BT-52), district (KSA-4), country code (BT-55). The additional number is not in the list. BR-KSA-67: an SA buyer postal code has 5 digits. BR-KSA-10: a non-SA buyer address (Standard only) needs street, city and country code. | G-01 (buyer address — closed for SA), D-40/BR-37 mandatory list, P22, P45, P50 | Recorded |
| F-SDK-08 | Rule | EN 16931 BR-10 (CEN-EN16931-UBL.xsl in the SDK, with ZATCA's note): an invoice shall contain the buyer postal address (BG-8); not applicable to simplified documents (KSA-2 = 02). | G-01, P22 | Recorded |
| F-SDK-09 | Rule | BR-KSA-15: a tax invoice (BT-3 = 388 and KSA-2 starting 01) must contain the supply date (KSA-5). BR-KSA-35/36: a supply end date (KSA-24) requires a supply date and must not precede it. What date to use for a course sale is not stated (G-17 stays open). | G-17 (partial), P22, P23, P54 | Recorded |
| F-SDK-10 | Rule | Buyer field length limits (BR-KSA-F-06-*): buyer name (BT-44) 1–1000 characters; street (BT-50) 1–1000; additional street (BT-51) ≤ 127; city (BT-52) 1–127; district (KSA-4) 1–127. BR-KSA-F-07…F-11 flag implausible TIN/CRN/700/NAT/IQA values (message text only; the exact format is not stated). | G-01, P45 | Recorded |
| F-SDK-11 | Note | Not found in either SDK rules file: a rule making the buyer name (BT-44) mandatory for Standard documents (EN 16931 BR-07 is absent from the shipped CEN file; BR-KSA-25 concerns simplified education/health exemptions only). The buyer-name requirement stays open under G-01 until the XML Implementation Standard is in hand. | G-01 (open part) | Recorded |

## Facts imported from the planning investigation (sources S-UP, S-SDK)

These were read during the planning investigation and are listed in blueprint Appendix C.2 with their citations. They are imported here unchanged and need a reviewer before a phase relies on them.

| ID | Type | Fact | Status |
|---|---|---|---|
| F-UP-01 | Contract | Reporting endpoint `POST /invoices/reporting/single` "Reports a single SIMPLIFIED invoice, credit note, or debit note"; headers `Clearance-Status` (0/1), `Accept-Version: V2`; responses 200/202/400/401/406/409/500. | Recorded (import) |
| F-UP-02 | Contract | The Compliance Invoice API checks Standard invoice/CN/DN and Simplified invoice/CN/DN; its response includes `clearanceStatus`. | Recorded (import) |
| F-UP-03 | Contract | Older schema text: `ClearedInvoiceResultModel {invoiceHash, clearedInvoice, status Cleared/Not Cleared, warnings, erros}`; `InfoModel` describes the message returned when the clearance flag is on or off. Superseded for field names by F-CL-09. | Superseded (F-CL-09) |
| F-UP-04 | Note | The Integration Sandbox does not cover exceptional backend handling such as overriding the clearance process (same statement as F-CL-12). | Recorded (import) |
| F-UP-05 | Example | KSA-2 structure NNPNESB (01 tax invoice, 02 simplified) — from a BR-KSA-06 example error in the Reporting API document. An error example, not the rule text. | Recorded (import) |
| F-UP-06 | Example | A BR-KSA-09 example error in the Compliance Invoice document lists the seller additional number (KSA-23) among seller address fields. Our seller XML omits it (G-18). | Recorded (import) |
| F-UP-07 | Example | S-SDK Standard invoice/CN/DN samples: `0100000`, buyer PostalAddress → PartyTaxScheme → PartyLegalEntity, `cac:Delivery/ActualDeliveryDate`, BillingReference and InstructionNote on notes, QR present, `ProfileID` `reporting:1.0`. | Recorded (import) |
| F-UP-08 | Example | `csr.invoice.type` exists in the S-SDK CSR templates; example value `1100`. Its meaning is not documented in hand (G-02). | Recorded (import) |

## Gate documentary status

| Gate | Documentary part | What is still missing |
|---|---|---|
| G-01 Standard XML rules | **Partly closed 2026-09-28** from the official SDK rule files (F-SDK-04…F-SDK-10): buyer-ID scheme list, BT-46 when no VAT, VAT format, SA buyer address mandatory set, buyer postal address required, supply date required, field lengths. | Buyer-name requirement (F-SDK-11), field semantics (Data Dictionary), PaymentMeansCode (G-22), seller address completeness (G-18), supply-date meaning (G-17). P20 is unblocked (scheme list); P22–P24 still wait for the rest. |
| G-02 second EGS / CSR invoice type | **Open** | Developer Portal User Manual (§4.3, §2.3.10). |
| G-03 Clearance contract | **Closed for the documentary part** (F-CL-01…F-CL-04) | `Accept-Version` value and `uuid` in the body — one sandbox call (P28). |
| G-04 Clearance responses | **Partly closed** (F-CL-06, F-CL-09) | Real response bodies per case (P29); `erroMessages`/`errorMessages` spelling. |
| G-05 next PIH after clearance | **Open** | F-CL-05 says PIH is validated; which hash chains forward after ZATCA re-signs is not documented (P30). |
| G-06 cleared XML stamp / QR | **Partly closed** (F-CL-05) | What exactly changes in the returned XML (P31). |
| G-07 duplicates / timeouts | **Partly closed** (F-CL-07) | Same UUID with a different hash; timeout reconciliation (P29/P30). |
| G-09 WATHQ | **Open** | WATHQ API documentation and contract. |
| G-16 clearance switch-off | **Partly closed** (F-CL-08, F-CL-12) | The official switch-off procedure. |
| G-17 supply date | **Open** | XML Implementation Standard + tax advisor. |
| G-18 seller address completeness | **Open** | XML Implementation Standard (F-UP-06 is an example only). |
| G-22 PaymentMeansCode for card/BNPL | **Open** | Code list in the XML Implementation Standard; F-CL-10 shows `10` in an example only. |

## How to add an entry

Quote or closely paraphrase the source, give the section/page or URL, the version or date, and the gate it serves. Mark examples as *Example*. When two official statements disagree, record both and the gate stays open until the sandbox or ZATCA settles it.
