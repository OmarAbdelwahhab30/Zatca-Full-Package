# AI Support Prompt — ZATCA Package

A ready-made prompt for getting help from an AI assistant (Claude, ChatGPT, or similar) when something goes wrong with this package in production.

**How to use it**

1. Copy the whole block below (one block, top to bottom).
2. Fill in the `[ ... ]` placeholders in PART 5 with what you know. Leave any you don't know empty — the assistant will ask.
3. Paste it into the assistant as your first message.

**⚠ Before you paste — redact these. Never send them to any AI assistant:**

`APP_KEY` · private keys (`-----BEGIN ... KEY-----`) · the `secret` column · `binary_security_token` / CSID values · `Authorization` headers · OTP codes · customer personal data.

Replace each with `[REDACTED]`. The package's own `ZatcaSanitizer` does this for logged output, but anything you copy by hand from the database or `.env` is **not** sanitized.

**Keep this file up to date.** The prompt describes the package as it is today — if the architecture changes (new API version, new SDK, new tables), update PART 1 or the assistant will reason from stale facts.

---

````text
You are helping me debug a production issue in a Laravel package that integrates with
ZATCA (Saudi Arabia e-invoicing / Fatoora). Read all of this before you answer.

============================================================
PART 1 — THE SYSTEM YOU ARE DEBUGGING
============================================================
Assume you cannot see the source code. These are the facts about the package.
Treat them as ground truth. If you need a file, ask for it by path.

Package: "zatca/zatca", PHP namespace Zatca\, Laravel 9–12 (12 recommended; 9–11 are Legacy Compatibility with known Laravel security advisories), PHP 8.1+ (8.2+ on Laravel 11/12).
Installed as a local Composer path repository inside the host application.

SCOPE — what it does and does NOT do:
  - Reporting model only: POST /invoices/reporting/single, header Clearance-Status: 0.
  - Simplified Tax Invoice (B2C) only.
  - It does NOT implement Clearance. There is no clearance endpoint in the code.
  - It does NOT implement Standard Tax Invoice (B2B), credit notes, or debit notes.
  If my problem is about any of those, say so immediately — it is unimplemented,
  not broken.

HOW AN INVOICE IS PRODUCED:
  1. GenerateZatcaInvoiceJob (queued) -> ProductionInvoiceOrchestrator.
  2. ICV (counter) and PIH (previous invoice hash) are reserved from table
     zatca_egs_states inside a DB transaction with a row lock.
  3. Unsigned UBL XML is built by a hand-written string template
     (XmlGenerators/V2/SimplifiedInvoiceXmlGenerator).
  4. The Java ZATCA SDK signs it. Signing is NEVER retried once it succeeds.
  5. ReportZatcaInvoiceJob sends the stored signed XML to ZATCA.
     RetryZatcaInvoiceJob re-sends the SAME stored bytes — it never re-signs and
     never changes UUID / ICV / PIH / hash.

HOW THE JAVA SDK IS CALLED:
  - Command: java -jar <ZATCA_SDK_EXECUTABLE> --globalVersion <ZATCA_SDK_VERSION> <args>
  - "java" must be on PATH. Required Java: JRE/JDK 11 to 15.
  - The package does NOT use the bundled fatoora / fatoora.bat wrappers and does
    not need jq. Each SDK process receives SDK_CONFIG pointing at that call's
    workspace Configuration/config.json, plus FATOORA_HOME pointing at the
    workspace Apps directory for Linux SDK compatibility.
  - Every call gets a fresh throwaway workspace at
    storage/app/private/zatca/runtime/{uuid} containing its own Configuration/config.json
    and Data/Certificates. It is deleted right after the call.
  - That generated config.json points at four files inside the REAL SDK root:
      Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd
      Data/Rules/Schematrons/CEN-EN16931-UBL.xsl
      Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl
      Configuration/usage.txt
    If a new SDK release renamed any of these, signing and validation break.
  - SDK sub-commands used: -csr, -sign, -generateHash, -validate.
  - Environment flag: sandbox = -nonprod, simulation = -sim, production = no flag.
    The flag is deliberately NOT sent for -sign.
  - The SDK's console output is parsed by regex in
    src/Services/SDK/OutputParsers/V3_4_8/. If ZATCA changes the wording of that
    output, the package throws ZatcaSdkOutputChangedException.
  - The SDK jar's sha256 is pinned in ZATCA_SDK_FINGERPRINT. A mismatch throws
    ZatcaSdkFingerprintMismatchException. An EMPTY value silently disables the check.

CREDENTIALS AND STATE (database, 5 tables, all prefixed zatca_):
  - zatca_egs_credentials — one row per (egs_identifier, environment, credential_type)
    where credential_type is "compliance" or "production". Columns include
    binary_security_token (the CSID), secret, private_key, certificate, csr,
    request_id, certificate_expires_at, certificate_state.
    secret and private_key are encrypted with Laravel's encrypter, i.e. with APP_KEY.
    If APP_KEY changed, they cannot be decrypted and onboarding must be redone.
  - The PRODUCTION certificate reuses the COMPLIANCE private key. The compliance
    request_id is required to obtain the production CSID.
  - zatca_egs_states — ICV counter and PIH per EGS. Compliance and production
    sequences are separate rows. This table never grows; the number inside it does.
  - zatca_invoices — the official invoice record, including signed_xml_base64.
    Never pruned by any command.
  - zatca_submission_attempts — one row per send attempt. The only table that is
    pruned automatically (after 180 days, successful invoices only).
  - zatca_compliance_documents — onboarding test invoices.

CERTIFICATE LIFECYCLE:
  states: valid -> expiring_soon (60 days before expiry) -> expired
          -> renewing -> valid | failed
  When the production certificate is "expired", reporting is SKIPPED entirely:
  invoices are still generated and signed and wait as pending_reporting.
  Automatic renewal is OFF by default (ZATCA_AUTO_RENEWAL_ENABLED=false) because
  ZATCA's real renewal contract has NOT been verified in this codebase.

VERSION SEAMS (important for "it used to work"):
  - ZATCA_API_VERSION       -> chooses src/Services/Connectors/V{n}/ via ConnectorFactory.
  - ZATCA_XML_PROFILE_VERSION -> chooses src/Services/Invoice/XmlGenerators/V{n}/ via XmlGeneratorFactory.
  - ZATCA_SDK_VERSION       -> passed to the jar as --globalVersion ONLY. It does NOT
                              choose the output-parser folder; that is a hardcoded class
                              name inside ZatcaSdkAdapter.
  - Accept-Version is a per-connector constant (V2 connector always sends V2), not
    read from config. A mismatch between config and the connector in use throws
    a ZatcaException before any request is sent.
  - ZATCA_UBL_VERSION, config zatca.compatibility and zatca.credential_encrypter are
    declared in config but READ BY NO CODE. Changing them has no effect.
  - The generated XML has ProfileID = reporting:1.0 and contains NO UBLVersionID and
    NO CustomizationID.

VERSIONING STRATEGY — ADD, NEVER EDIT:
  Supporting a new API/SDK/UBL version means ADDING a new folder next to the old
  one, never modifying the existing one:
    new API version  -> create src/Services/Connectors/V{n}/ (3 files) + one match
                        arm in ConnectorFactory. V2/ stays byte-for-byte unchanged.
    new SDK output   -> create src/Services/SDK/OutputParsers/V{x_y_z}/ + fixtures
                        in tests/Fixtures/SdkOutput/V{x.y.z}/.
    new UBL profile  -> create src/Services/Invoice/XmlGenerators/V{n}/.
  The old folder must keep working so a rollback is just flipping the .env value
  back. The .env pin is only flipped AFTER a verified row exists in
  docs/COMPATIBILITY.md. Never change a default in config/zatca.php without a
  matching row in that table.

WHAT IS SAFE TO DELETE (and what is not):
  - The authoritative signed invoice is the DATABASE column
    zatca_invoices.signed_xml_base64. It is never pruned by any command.
  - Files under storage/app/private/zatca/ (sdk-output/, tmp/, runtime/,
    csr-config-*.properties) are DUPLICATES or leftovers. Nothing reads them after
    the call that created them. zatca:prune-sdk-files deletes them safely.
  - Never propose deleting or hand-editing rows in zatca_invoices,
    zatca_egs_credentials, or zatca_egs_states.

INTEGRATION STATUS (verified 2026-08-05 — RE-CHECK before trusting):
  The package may not be wired into the host application yet. If the symptom is
  "no ZATCA invoice is created at all after a purchase", check these BEFORE
  assuming the package is broken:
    - Is InvoiceSourceContract bound in a service provider? (no default binding
      exists; GenerateZatcaInvoiceJob cannot resolve without it)
    - Is GenerateZatcaInvoiceJob actually dispatched after a successful payment?
    - Is a queue worker running on the "zatca" queue?
    - Are the scheduled commands registered (report-pending, requeue --only-transient,
      certificate:check, prune-submission-attempts, prune-sdk-files)?
  As of the date above, none of these were present in the host app.

VAT (how amounts are produced):
  - The host's InvoiceSourceContract::toInvoiceInput() returns items with prices
    and quantities only. The package's InvoiceCalculator calculates net, VAT and
    totals with config('zatca.vat'): ZATCA_VAT_PERCENTAGE (default 15) and
    ZATCA_VAT_MODE (default "inclusive": prices include VAT; or "exclusive").
    An invoice may override the mode; an item may override the rate.
  - The result is frozen in zatca_invoices.invoice_data_snapshot. Credit notes and
    regeneration use those frozen rates, never the current settings, so changing
    the .env values only affects new documents.
  - A wrong VAT setting fails with a message naming ZATCA_VAT_PERCENTAGE or
    ZATCA_VAT_MODE; zatca:health-check shows the values in use.

API ENDPOINTS USED (all POST, base URL depends on environment):
  /compliance                  (OTP header)          -> compliance CSID
  /compliance/invoices         (basic auth)          -> compliance test invoice
  /production/csids            (basic auth)          -> production CSID
  /production/csids            (basic auth)          -> renewal (UNVERIFIED contract)
  /invoices/reporting/single   (basic auth)          -> report invoice
  A 409 is treated as an idempotent success ONLY when the error text matches
  "already ... report". Any unexpected response shape below HTTP 400 throws
  ZatcaContractChangedException.

DIAGNOSTIC COMMANDS AVAILABLE:
  php artisan zatca:status                    (START HERE: stuck invoices, certificate
                                               expiry, failed jobs. Exit 1 = needs a human.
                                               --json for machine output)
  php artisan zatca:health-check              (SDK jar, fingerprint, credentials, cert state)
  php artisan zatca:inspect credentials|state|invoice --id=N   (read-only, no secrets)
  php artisan zatca:certificate:check
  php artisan zatca:sdk-smoke-test            (real SDK end-to-end; can print SKIPPED and
                                               still exit 0 — read the output, do not
                                               trust the exit code alone)
  php artisan zatca:report-pending            (retries pending/retrying only, never manual_review)
  php artisan zatca:requeue --dry-run         (shows which stuck invoices would be restarted)
  php artisan zatca:recover --id=<id> --dry-run (one sale that broke part-way: shows state and
                                               the planned step; never regenerates, refuses
                                               rejected and clearance_in_doubt documents)
  php artisan zatca:prune-sdk-files --dry-run  (safe: shows leftover files, deletes nothing)
  Docs in the repo: packages/zatca/README.md, packages/zatca/docs/OPERATIONS.md,
  packages/zatca/docs/DATABASE.md, packages/zatca/docs/COMPATIBILITY.md.

============================================================
PART 2 — RULES YOU MUST FOLLOW
============================================================
1. Understand before fixing. Do not propose a code change in your first reply
   unless the cause is unambiguous from what I gave you.
2. Ask clarifying questions until you can name a likely component. Ask them all at
   once, numbered, so I can answer in one message. Do not guess to fill a gap.
3. Never invent ZATCA behavior. If you are not certain how a ZATCA endpoint,
   error code, field, or validation rule behaves, say plainly:
   "This must be verified against the official ZATCA documentation" — and say
   which document or endpoint to check. A confident wrong answer about ZATCA is
   worse than no answer.
4. Separate FACTS from ASSUMPTIONS in every reply, under those two headings.
   A fact is something I told you or something in PART 1. Everything else is an
   assumption and must be labelled as one.
5. Do not assume the code says something. If you need a file, ask for it by path.
6. Respect the scope limits in PART 1. Do not suggest clearance, B2B, or credit-note
   fixes — they are not implemented.
7. Do not ask me to paste private keys, secrets, CSIDs, tokens, OTPs or APP_KEY.
   If you need to see one, ask only for its shape (length, prefix, PEM or base64).
8. Respect ADD-NEVER-EDIT (see PART 1). If your fix involves a new API, SDK or UBL
   version, it must create a new V{n} folder — never modify the existing one, and
   never "just update" V2 in place. If you think editing the existing version is
   the only option, say so explicitly and explain why, rather than doing it quietly.

============================================================
PART 3 — WHAT TO DO, IN ORDER
============================================================
STEP 1 — Classify the problem into ONE primary component, plus any secondary ones:
   ZATCA API · SDK · Certificates · Production CSID · Compliance CSID ·
   Cryptographic Stamp Identifier (CSID/CSI) · Environment configuration ·
   Java/JDK · CSR generation · XML signing · Invoice validation · Clearance ·
   Reporting · Network/connectivity · Authentication · Version compatibility ·
   Package configuration · Server configuration · Other (name it).
   State why you chose it, and name the one piece of evidence that would
   disprove your choice.

STEP 2 — Ask me for the evidence you still need. Use this checklist and ask only
   for what is actually missing:
   - The exact error message and full stack trace.
   - Laravel log lines around the failure (storage/logs/laravel.log), including
     any line starting with [ZATCA].
   - The failing HTTP request and response: URL, method, status code, headers and
     body — WITH SECRETS REDACTED.
   - Raw SDK console output (stdout and stderr) from the failing command.
   - Output of: php artisan zatca:health-check
   - Output of: php artisan zatca:inspect credentials  and  zatca:inspect state
   - Relevant .env values with secrets removed: ZATCA_ENVIRONMENT, ZATCA_API_VERSION,
     ZATCA_SDK_VERSION, ZATCA_UBL_VERSION, ZATCA_XML_PROFILE_VERSION, whether
     ZATCA_SDK_FINGERPRINT is set (yes/no, not the value), SDK paths.
   - java -version output.
   - Package version and the current row from docs/COMPATIBILITY.md.
   - The invoice row state: status, reporting_status, attempt_count, last_error.
   - What changed recently: deploy, SDK upgrade, PHP/Java upgrade, certificate
     renewal, APP_KEY change, config:cache, server migration.

STEP 3 — Consider a version change as a cause whenever the symptom is
   "it worked before and now it doesn't". Explicitly check and tell me how to verify:
   - Did the SDK jar change? Compare sha256 against ZATCA_SDK_FINGERPRINT and run
     zatca:health-check.
   - Did the SDK's console output wording change? Symptom:
     ZatcaSdkOutputChangedException. Verify by running the SDK by hand and comparing
     with the fixtures in tests/Fixtures/SdkOutput/.
   - Did the SDK folder layout change? Check the four XSD/Schematron/usage.txt paths
     listed in PART 1 still exist.
   - Did the ZATCA API response shape change? Symptom:
     ZatcaContractChangedException. Verify against ZATCA's current published API docs.
   - Is the running combination of package / API / SDK / UBL versions actually a
     verified row in docs/COMPATIBILITY.md?

STEP 4 — If more than one cause is possible, list them RANKED from most likely to
   least likely. For each: what it would explain, what it would NOT explain, and the
   single cheapest test to confirm or eliminate it.

STEP 5 — When you propose a fix:
   - Say which file or setting changes.
   - Explain WHY it solves the problem, connecting the fix to the specific evidence.
   - State the risk and how to roll back.
   - Say whether it needs re-onboarding, a new certificate, a queue restart, or
     config:cache — and whether already-signed invoices are affected.
   - Never suggest re-signing or regenerating an invoice that ZATCA already accepted,
     and never suggest changing ICV or PIH by hand.
   - Never suggest setting ZATCA_AUTO_RENEWAL_ENABLED=true unless I tell you the
     real ZATCA renewal contract has been verified against an official document.
   - Never suggest deleting rows from zatca_invoices, zatca_egs_credentials or
     zatca_egs_states as a workaround.
   - If the fix adds support for a new version, follow ADD-NEVER-EDIT from PART 1
     and tell me which row must be added to docs/COMPATIBILITY.md.

============================================================
PART 4 — HOW TO END EVERY REPLY
============================================================
Finish each reply with exactly this block:

   ROOT CAUSE (so far): one or two sentences.
   COMPONENT: the classification from STEP 1.
   CONFIDENCE: High / Medium / Low — and what would raise it.
   NEEDS ZATCA DOC VERIFICATION: yes/no — if yes, exactly what to verify.
   NEXT ACTION: the single next thing I should do or send.

============================================================
PART 5 — MY SITUATION
============================================================
What is happening:            [ describe the symptom ]
When it started:              [ date / after which change ]
Environment:                  [ sandbox | simulation | production ]
Does it affect all invoices or some:   [ ... ]
Error message:                [ paste, redacted ]
What I already tried:         [ ... ]
zatca:health-check output:    [ paste ]
Package / API / SDK versions: [ ... ]
Recent changes:               [ deploy / SDK upgrade / cert renewal / APP_KEY / none ]
````
