# Decisions

Approved decisions take precedence over the recommendations in the blueprint. A decision that changes behaviour is also reflected in the blueprint and in the ADR it affects.

## Decision sheet (blueprint §38.2)

| ID | Decision needed | Recommended |
|---|---|---|
| D-01 | Is "student pays, invoice to company" acceptable? | yes, with declaration (advisor) |
| D-02 | BNPL (Tabby/Tamara) allowed for company invoices? | yes if providers agree (G-14) |
| D-03 | Refund of company-invoiced sale to the student's payment method | yes (advisor) |
| D-04 | Company always Standard, even < SAR 1,000 | yes |
| D-05 | Admin company buyer rules (§5.2 table) | approve as written |
| D-06 | Unpaid assignment captures no buyer | yes |
| D-07 | Lock mechanism for new assignment (advisory lock vs student row lock) | advisory lock `GET_LOCK` (no schema change) |
| D-08 | Held company sales resolution options | hold + admin "issue now"; no silent B2C |
| D-09 | B2B settlement issue/supply date = `settled_at` | yes (advisor) |
| D-10 | Guard against tax policy change between payment and generation | warn + block B2B generation until admin confirms |
| D-11 | Saudi companies only in v1 | yes |
| D-12 | Non-VAT companies with CRN allowed | yes |
| D-13 | Exact WATHQ status codes that count as "active" (not found / inactive both block company checkout per #33496 AC-06) | block on anything but the documented active status; list from the WATHQ spec (G-09) |
| D-14 | Student edits of WATHQ-prefilled data | **no** (#33496 Alt-08/AC-07): legal name, CR, status and every address value WATHQ returned are read-only and taken server-side; typed: VAT fields, declaration, invoice email, and address fields WATHQ did not return (D-40) |
| D-15 | Store declarant IP? retention of abandoned buyer rows | IP no (store user+time+text version); purge abandoned after 30 days |
| D-16 | company_profiles in v1 | defer |
| D-17 | Show payer (student) on Standard PDF | yes, as "تم الشراء بواسطة" |
| D-18 | Optional company invoice email | yes, optional field, CC |
| D-19 | Pending clearance when B2B off | **APPROVED 2026-09-28:** continue clearing (drain) — documents already signed while B2B was on keep clearing after `ZATCA_B2B_ENABLED` is switched off; only new company sales stop. |
| D-20 | Settle UI placement; B2C settlement through new endpoint too; dedicated permission | yes; reuse `add_transactions` |
| D-21 | Rejected notes: manual cancel (release) vs auto | manual (consistent with B2C) |
| D-22 | New permission `reissue_zatca_invoices` | yes |
| D-23 | Supply date on notes | original supply date or note date per G-17 |
| D-24 | Host test DB strategy | **APPROVED 2026-09-28 (G-11): SQLite only** — in-memory SQLite with a curated migration set; no MySQL test schema. |
| D-25 | Add Vitest to student SPA | yes (dev-only dependency) — else manual scripts |
| D-26 | Company option for IBAN (student side) | **APPROVED 2026-09-28:** no IBAN for company purchases — with Company selected IBAN is not listed; the buyer type is never changed by the method (#33496 AC-09) |
| D-30 | MySQL trigger for sale_buyers immutability | optional; app-level guard mandatory |
| D-31 | Auto-disable readiness after N validation failures | yes, N=3 |
| D-32 | Connector-level subtype assertion | yes |
| D-33 | Display ZATCA document id on PDF | yes |
| D-34 | Non-tax order summary PDF in B2B confirmation | no attachment in v1 |
| D-35 | Attach cleared XML to tax invoice email | no (download from portal/admin) |
| D-36 | Dev-only sandbox probe command | yes, never registered when `app.env=production` |
| D-37 | Pilot allow-list | yes |
| D-38 | Buyer-type selector (#33496 AC-01/AC-02): mandatory, no default, for **every** student while B2B is available — this adds one click to the B2C checkout. Reuse of an earlier confirmed choice (AC-01 exception)? | **APPROVED 2026-09-28:** no Individual/Company selector when B2B is disabled (checkout identical to today). While B2B is available: mandatory, no default, no reuse in v1. |
| D-39 | **APPROVED 2026-09-28:** no manual company-entry fallback; while WATHQ is enabled any WATHQ failure blocks company verification (and so company checkout). The Case D question (WATHQ disabled) is split out as D-43. Original question — Case D (ZATCA+B2B on, WATHQ off): manual company entry for students. The prompt requires Case D; the story says verification is through WATHQ "where the configured business flow requires" (Main 3) and limits verification to WATHQ (story BR-06) | keep Case D as a configured mode (never an automatic fallback); `cr_source=manual` shown to admins. Alternative B: hide the student Company option when WATHQ is off and keep manual entry admin-only. Product decides (G-25). |
| D-40 | WATHQ verifies the CR but returns no (or an incomplete) national address | **APPROVED 2026-09-28:** do not block company checkout because of ZATCA-optional fields; allow manual completion of missing fields (`address_source=wathq_completed`, `manual_fields`); only ZATCA-mandatory fields are required before invoice generation (BR-37). The mandatory list comes from the official XML Implementation Standard (G-01); until then the phases that need it stop (R-DOC). Supersedes the earlier recommendation A. |
| D-41 | Server behaviour when `buyer_type` is absent while B2B is available | **APPROVED 2026-09-28:** a request without `buyer_type` is Individual; the existing B2C flow is unchanged (backward compatible with older clients and mobile versions); counted as `checkout.buyer_type_absent`. |
| D-42 | Admin manual entry when WATHQ is on but fails or returns no usable address (#33496 is student-only) | **APPROVED 2026-09-28:** admin company-data override with a mandatory reason and an audit trail (`admin_manual` source flags, `manual_override_*` columns, append-only M-H8); escape path for WATHQ outages and Case D |
| D-43 | Case D (ZATCA+B2B on, WATHQ **disabled**): may students type company data manually? | **APPROVED 2026-09-28:** when `WATHQ_ENABLED=false`, company checkout remains available with manual company data entry (`cr_source`/`address_source=manual`). |
| D-44 | With Company selected, is the student IBAN (bank-transfer instructions) method listed? D-26 as restated covers company banking **data**, not this. The student IBAN option only shows transfer instructions and creates no server record [CODE]; the sale is created later by an admin, who can capture the company (§18). | A (recommended, plan default): not listed for Company, note points to the admin team; B: listed, and the admin re-captures the company data at assignment/settlement. |
| D-45 | #33502 contradicts itself: Step 5 says the student "can edit any information"; BR-07 says retrieved information "should not be freely modified" and another company needs another CR; the Data Fields table says "depends on source". (b) If WATHQ verifies a CR but returns no legal name, may the student type it (D-40 "complete missing fields")? | **APPROVED 2026-09-28 (a): read-only** —  BR-07 wins — values retrieved from WATHQ stay read-only (enforced server-side, ADR-28), values the student typed stay editable until confirmation, changing company means changing the CR. **(b):** treat a verified CR without a legal name as `invalid` ("Verification failed"), because the legal name is the identity being verified. (b) stays the plan default. |

## Approved decisions log (blueprint §38.3)

| Date | ID | Approved text (user) | Blueprint effect |
|---|---|---|---|
| 2026-09-28 | D-38 | No Individual/Company selector when B2B is disabled. | ADR-29 option B confirmed; E2E-S19 |
| 2026-09-28 | D-39 | No manual company entry fallback. WATHQ failure blocks company verification when WATHQ is enabled. | ADR-08 option B confirmed; Case D split out as D-43 (open) |
| 2026-09-28 | D-40 | Do not block company checkout because of optional ZATCA fields. Allow manual completion of missing fields. Only ZATCA mandatory fields are required before invoice generation. | BR-24 changed, BR-37 added, M-H1 `wathq_completed`/`manual_fields`, M-H7 outcome `address_unavailable` removed, E2E-W13/W15; mandatory list gated by G-01 |
| 2026-09-28 | D-42 | Allow admin company data override with mandatory reason and audit trail. | BR-38, M-H1 `manual_override_*`, M-H8 |
| 2026-09-28 | D-26 | No IBAN requirement for company purchases. | superseded by the restatement below |
| 2026-09-28 | R-DOC | Before implementing any ZATCA-related phase, verify the requirement against official ZATCA documentation; if unavailable, create a verification gate and stop that phase until confirmed. | §0.2 item 7, §39.0, SDoD (8) |
| 2026-09-28 | D-43 | When WATHQ_ENABLED=false, Company checkout remains available using manual company data entry. | Case D manual mode for students; E2E-S15, G03 |
| 2026-09-28 | D-41 | Any request that does not include `buyer_type` is Individual; the existing B2C flow remains unchanged (older clients/mobile versions). | BR-35; request field renamed `buyer_type` → `buyer_type` |
| 2026-09-28 | D-26 (restated) | Company purchases do not require collecting company IBAN information; no additional banking fields for B2B invoicing; existing payment flows remain unchanged. | no banking columns anywhere; IBAN listing for Company split out as D-44 (open) |
| 2026-09-28 | P02 | Store the B2B governance documentation under `docs/zatca-b2b/` (ADRs, governance rules, verification gates, implementation documentation, technical references). | P02 unblocked |
| 2026-09-28 | G-03 input | Official Clearance API page supplied: https://sandbox.zatca.gov.sa/IntegrationSandbox/clearance-api | read in P01; findings in the Facts Register |
| 2026-09-28 | P16, P17, P25 | Approved to start (first package code, dormant). | P16 done. P25 still waits for its dependencies P23/P24, which are gated by G-01 (no phase or gate is skipped). |
| 2026-09-28 | D-19 | Continue clearing. | ADR-22 option A accepted; G-12 closed; P13 unblocked. |
| 2026-09-28 | #33502 input | Second user story supplied and read (user: "yes read it"). | §0.2 item 5b, §17.7, REQ-39…REQ-41, E2E-W16/W17, D-45 (open); P51/P52/P58 scope updated; no phase done so far is affected. |
| 2026-09-28 | P14, P15 | P14 approved. P15 answer (translated from Arabic): "leave everything as it is in the settings — the setting decides whether it is inclusive or exclusive." | Read as approval of P15's intent: the one existing admin setting decides for every EGS, B2B included; the settings screen is unchanged. |
| 2026-09-28 | D-45 | Yes, read-only. | Retrieved company data stays read-only; another company needs another CR (#33502 BR-07). |
| 2026-09-28 | G-11 / D-24 | SQLite. | ADR-24 changed to SQLite only; P03 builds the harness without a MySQL test schema. Tests that need real row locks (`@group concurrency`/`@group mysql`) cannot run automatically — see interpretation (5). |

Interpretations made while recording (flag if wrong): (1) D-40 "required before invoice generation" is enforced twice — the student must fill the mandatory fields at checkout, and the pre-generation guard (P22) re-checks them; (2) D-26 was restated by the user (company banking data), so the IBAN-method question became D-44; (3) D-39 was read as settling WATHQ-enabled behaviour only, hence D-43, since approved; (4) D-41's approved text names the request field `buyer_type`, so the blueprint uses that name everywhere the earlier draft said `buyer_type`; (5) G-11 "sqlite" was read as SQLite **only**: the MySQL-container job is dropped from P03, and the lock/race properties planned for `@group concurrency` (P71: E2E-C01…C05 and others) are covered by code review, unique constraints and the staging drills instead — the tests are still written and skip themselves on SQLite. Revisit before P71 if automated lock tests are wanted.
