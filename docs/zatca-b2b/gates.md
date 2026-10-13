# Verification gates

A phase listing a gate cannot be marked Done until the gate is **Closed** with an evidence entry (§31.3) or a signed decision (P02 ADR log).

| Gate | Question | How it is closed | Owner | Blocks |
|---|---|---|---|---|
| G-01 | Exact Standard rules: BT-44/48/46 schemes & priority, BR-KSA-44, BR-KSA-63..70 address, KSA-5 (BR-KSA-15), buyer element order, additional number mapping, note-specific rules — **partly closed 2026-09-28** from the official SDK rule files (Facts Register F-SDK-04…F-SDK-11): scheme list, BT-46 when no VAT, VAT/CR/postal formats, SA buyer address set, supply date required; still open: buyer-name requirement, Data Dictionary semantics, G-17/G-18/G-22 items | obtain & read XML Implementation Standard (current version) + Data Dictionary + Detailed Guidelines; confirm with SDK `validate` on generated Standard XML | Tech lead | P22–P24 done, P70 |
| G-02 | May the taxpayer run a second EGS for Standard documents; which `csr.invoice.type`; which compliance steps it requires | Developer Portal manual §4.3 + sandbox/simulation onboarding (P27) | Tech lead + client admin | P26 done, P27, P74 |
| G-03 | Clearance endpoint path/headers/body/auth — **documentary part closed 2026-09-28** (official Clearance API page, Facts Register F-CL-01…F-CL-10); open: `Accept-Version` value and `uuid` in the body, confirmed by one sandbox call | official Clearance API swagger + sandbox call | Tech lead | P32 |
| G-04 | Clearance response catalogue (CL-01…CL-16) (documented codes now in Facts Register F-CL-06; real bodies still to capture) | P29 capture campaign | QA + Tech lead | P33, P34, P36–P38 |
| G-05 | Next PIH after a cleared document (our hash vs cleared hash); rejected documents in chain | P30 experiments + official statement | Tech lead | P35 final, P68 |
| G-06 | Does cleared XML carry ZATCA stamp/QR; which QR on PDF; what to store (page states ZATCA signs and applies a QR on clearance, F-CL-05; what changes in the XML still to confirm) | P31 + official doc | Tech lead | P63, P64 |
| G-07 | Duplicate UUID / timeout reconciliation behaviour (page documents 208 for a previously submitted hash, F-CL-07; changed-hash/same-UUID and timeout cases still open) | P29/P30 experiments | Tech lead | P37, P68 |
| G-08 | Tax advisor: student pays/company invoiced; refunds to student; BNPL; notes wording; D-04 threshold; supply date; issue date after settlement | written opinion | Client finance | P53+ (host capture), P63 wording |
| G-09 | WATHQ contract, key, pricing, rate limits, CR status codes, address fields, language param | WATHQ developer portal docs + sandbox + contract | Client admin | P48–P52 |
| G-10 | Live student frontend = `coursefer/resources/app` | ops confirms web server serves the Vite build of this repo | Ops | P58, P59 |
| G-11 | Host test DB strategy (**closed 2026-09-28: SQLite only; P03 harness working**) | decision + working harness | Tech lead | P03, all host phases |
| G-12 | Behaviour of pending B2B docs when B2B is turned off (drain vs pause) — **closed 2026-09-28: drain (D-19)** | decision D-19 | Product + ops | P13, P37 |
| G-13 | Real production CSR identity values for b2b EGS | client provides | Client admin | P74 |
| G-14 | BNPL providers' terms allow business purchases by individuals | provider confirmation | Client finance | Tabby/Tamara company option (P57 allow-list) |
| G-15 | PDPL/privacy: declaration IP, retention of abandoned buyer rows, WATHQ excerpts | privacy review | Client legal | P44, P51 |
| G-16 | Official procedure when clearance is switched off (page documents 303 for a Standard invoice while clearance is deactivated, F-CL-08; the switch-off procedure itself is still undocumented) | ZATCA doc | Tech lead | CL-13 beyond manual review |
| G-17 | Supply date rule for courses (invoice & notes) | advisor + G-01 | Client finance | P22, P54 |
| G-18 | Seller address completeness for Standard (e.g., seller additional number KSA-23 appeared in a BR-KSA-09 example error in the Compliance Invoice doc [DOC]) | SDK validate + sandbox compliance with current seller output | Tech lead | P23, P26 |
| G-19 | B2C sandbox regression green before/after each release | E2E-B* evidence | QA | R1/R2/R3 promotion |
| G-20 | Deploy pipeline restarts workers | P11 merged and observed | Ops | R1 |
| G-21 | Decision on B2C callback race hardening (HT-01) before GA | decision | Product + tech lead | P77 |
| G-22 | `PaymentMeansCode` for card/BNPL on Standard (B2C sends `10`) | G-01 docs + advisor | Tech lead | P23 |
| G-23 | Pilot sign-off | pilot report | Product + finance | P77 |
| G-24 | Rollback floor acknowledged (never below R1 with B2B rows) | ops sign-off in runbook | Ops | R1 deploy |
| G-25 | User story #33496 reconciliation — **partly closed 2026-09-28** (D-38, D-39, D-40, D-42, D-26 approved); then D-41, D-43 approved and D-26 restated; still open: D-44 and the two cited documents. **2026-09-28:** #33502 (`Second.txt`) read and traced (§17.7); it adds D-45. Originally: product confirms D-38…D-42 and the WATHQ-mode rules (no manual fallback, read-only verified data), and supplies the two documents the story cites ("Pasted text(20260916-144013)", "lms_business_rules_admin_student") so they can be checked for conflicts | signed product decision + documents read | Product owner | P49, P50, P57, P58 |

## Gate state

A gate is **Open** until the evidence named in "How it is closed" is recorded here with a link to the Facts Register or to [`evidence/`](evidence/). Partly closed gates say which part is closed. New gates created under R-DOC take the next free number (G-26 onward).
