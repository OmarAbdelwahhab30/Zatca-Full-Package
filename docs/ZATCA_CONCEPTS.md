# ZATCA Concepts Explained

This page explains **why** ZATCA e-invoicing works the way it does — the ideas behind the words you see in `.env`, in Artisan output, and in ZATCA's own messages. For the practical glossary, the six document types, and the quick setup table, see the README's [ZATCA basics in 5 minutes](../README_SIMPLE.md#understand-the-two-flows). This page is the "why", that page is the "what".

---

## 1. Why ZATCA e-invoicing exists

Saudi Arabia's tax authority, **ZATCA** (Zakat, Tax and Customs Authority), requires VAT-registered businesses to report their invoices electronically instead of only keeping paper or PDF records. This is called **Fatoora**, and the current rules are **Phase 2 (Integration Phase)**.

The goal is simple: every invoice that affects VAT should exist, unmodified, in ZATCA's systems — either because the business sent it (Reporting) or because ZATCA itself approved it before it reached the buyer (Clearance). That is what the rest of this page explains.

## 2. EGS — the "device" that ZATCA trusts

**EGS** stands for **E-invoice Generation Solution**. Conceptually, ZATCA doesn't trust your whole Laravel application — it trusts one specific, registered "device" that generates invoices. In practice that device is this package, configured and onboarded once.

Each EGS has its own:
- **Certificate** (its proof of identity to ZATCA),
- **Counter** (the `ICV`, invoice counter, starting at 1 and only ever going up),
- **Hash chain** (the `PIH`, previous invoice hash — see below).

Why does this matter? Because an EGS is the unit ZATCA reasons about. If you run two EGS (for example one for B2C and one for B2B, as this package supports), ZATCA sees them as two independent, separately-numbered invoice streams. A sale is only ever signed on one EGS — never split or duplicated across both.

## 3. The hash chain — how ZATCA knows nothing was deleted

Every invoice carries the cryptographic hash of the invoice before it (**PIH**, Previous Invoice Hash), plus its own position in the sequence (**ICV**, the counter). This turns your invoices into a **chain**: invoice #42 cryptographically points back at invoice #41, which points back at #40, and so on.

Why: if someone deleted invoice #41 after the fact, invoice #42's stored "previous hash" would no longer match anything, and the chain would be provably broken. This is why the package (and this documentation) treats `icv` and `previous_invoice_hash` as **write-once, never-edit** values — touching them by hand doesn't just corrupt one row, it invalidates every invoice issued afterwards. See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for what "the chain is broken" looks like in practice.

## 4. Certificates — proving who signed the invoice

ZATCA needs to know that an invoice's signature really came from your business's registered EGS, not from anyone who happened to have your VAT number. That's what certificates are for.

| Term | What it actually is |
|---|---|
| **Private key** | A secret cryptographic key generated once, on your server, and never sent anywhere. It signs every invoice. If it leaks, someone else could sign invoices as you. |
| **CSR** (Certificate Signing Request) | A document built from your private key plus your business details (VAT number, CR number, device name...). You hand this to ZATCA, not the private key itself. |
| **Compliance CSID** | A *test* certificate ZATCA issues after your CSR and an OTP. It proves your invoices are shaped correctly, but it is not legally binding — the sandbox environment even accepts a shared one that doesn't match your key at all (see [Environments](#5-three-environments-and-why-they-exist)). |
| **Production CSID** | The *real* certificate, issued only after your compliance certificate has passed all three required compliance checks (invoice, credit note, debit note). This is the one that signs live, legal invoices. |

Both certificates are built on the **same private key** — that's why losing the `zatca_egs_credentials` row (or losing `APP_KEY`, which decrypts it) means starting the whole certificate process over, not just re-requesting a certificate.

## 5. Three environments, and why they exist

ZATCA gives you three separate places to work, and they are **not** just "staging vs production" — they test different things:

| Environment | What it actually proves | Legally binding? |
|---|---|---|
| **Sandbox** (developer portal) | Your XML is shaped correctly and your code runs end to end. It is deliberately lenient — it accepts any OTP and issues a shared production certificate that doesn't match your real key — so it **cannot** prove your real signing works. | No |
| **Simulation** | A full rehearsal with your real company data, a real OTP, and — unlike sandbox — the real certificate/signature check. This is the only environment that proves your production signing is correct before you go live. | No |
| **Production** | The real thing. Every invoice here is a legal tax document. | **Yes** |

Each environment keeps its own certificates, counters and hash chains — moving from one to another means onboarding again from zero, not "promoting" existing data. This is why the package stores credentials and state per-EGS **and** per-environment.

## 6. Compliance checks — proving your invoices before you're trusted with real ones

Before ZATCA gives you a production certificate, it makes you prove — with your compliance certificate — that you can produce a correct invoice, a correct credit note, and a correct debit note. This isn't bureaucracy for its own sake: credit and debit notes have different mandatory fields (for example, a required reason at a specific XML path) than invoices, so passing an invoice test alone doesn't prove the note XML is right too.

That's why `zatca:onboard production` refuses to run until all three `test-*` steps have passed for that EGS — see the README's [ZATCA onboarding](INTEGRATION_REFERENCE.md#8-zatca-onboarding) for the exact command sequence.

## 7. XML and UBL — the actual shape of an e-invoice

Every ZATCA invoice is an **XML** file following the **UBL 2.1** (Universal Business Language) standard — a widely used international format for business documents, not something ZATCA invented. ZATCA then layers its own rules on top (Saudi-specific business rules, like "the VAT number must start and end with 3", or "a credit note must give its reason at this exact path").

You never write or read this XML by hand day to day — the package builds it from the data you give it (via `ZatcaInvoiceInput`) and you read the result through PHP (`$invoice->signed_xml_base64`, or fields like `status` and `qr_code`). It's worth knowing it's there mainly for troubleshooting: when ZATCA rejects an invoice, the error almost always names a specific XML rule (for example `BR-KSA-15`), and `zatca:inspect invoice --id=X` lets you see what was actually sent.

## 8. Digital signatures — why they matter beyond "security"

Signing an invoice with your private key does two things at once:
1. It proves the invoice came from your EGS (authenticity).
2. It proves the invoice content hasn't been changed since it was signed (integrity) — which is also why a rejected invoice can never simply be edited and resent; it has to be regenerated as a new, separately-signed document (see [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md)).

This package never asks you to touch cryptography directly — signing happens inside ZATCA's own Java SDK, which the package calls as a subprocess. That's also why Java must be installed and on the `PATH` of whatever process runs your queue workers (see the README's [Requirements](SERVER_REQUIREMENTS.md#1-requirements)).

## 9. QR codes — what the buyer actually gets

Every ZATCA invoice carries a **QR code** encoded in TLV (Tag-Length-Value) format, containing a small, fixed set of fields: seller name, VAT number, timestamp, invoice total, and VAT total (plus, for B2B, cryptographic fields once simulation/production use is verified). It exists so a buyer — or a ZATCA inspector — can scan the receipt and immediately see the invoice's declared totals, without needing access to ZATCA's systems.

The package generates this for you (`$invoice->qr_code`, a base64 string) — you print it on your own receipt or PDF. The package does not generate PDFs itself; see the README's [What is this package?](../README_SIMPLE.md).

## 10. Where to go next

- The practical glossary, document-type table, and `.env` settings → README [§2](../README_SIMPLE.md#understand-the-two-flows) and [§6](CONFIGURATION.md#6-configuration).
- Which invoice type applies to your sale, and credit vs. debit notes → [INVOICE_TYPES.md](INVOICE_TYPES.md).
- VAT math in detail → [VAT_GUIDE.md](VAT_GUIDE.md).
- The full lifecycle, environments in practice, and certificate renewal → [ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md).
- Working code → README [§9](../README_SIMPLE.md#create-and-read-invoices) and [USAGE_EXAMPLES.md](USAGE_EXAMPLES.md).
