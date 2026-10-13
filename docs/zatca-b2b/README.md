# ZATCA B2B Standard Tax Invoicing — governance

This folder holds the governance and implementation documentation for adding company (B2B, Standard, Clearance) invoices beside the live B2C (Simplified, Reporting) flow. It is the location approved on 2026-09-28 (phase P02).

Nothing described here is switched on in production. B2C behaviour must stay exactly as it is; every phase proves that before it closes.

## Contents

| File | What it holds | Who updates it |
|---|---|---|
| [`blueprint.md`](blueprint.md) | The approved implementation blueprint (sections 0–42, 78 phases, appendices). Frozen copy; the online version is https://claude.ai/artifact/UnYTRr3g4pVcZZxNLX7Dw2 | Re-exported when the plan changes |
| [`governance.md`](governance.md) | Rules every phase follows (official-documentation rule, one phase at a time, approvals, definition of done) | Tech lead |
| [`gates.md`](gates.md) | Verification gates: question, how it closes, owner, blocked phases, current state | Whoever closes a gate, with evidence |
| [`decisions.md`](decisions.md) | Decision sheet and the log of approved decisions | Product owner / tech lead |
| [`adr/`](adr/) | Architecture decision records ADR-01…ADR-29 | Tech lead |
| [`touchpoint-checklist.md`](touchpoint-checklist.md) | Every shared B2C file B2B may touch, with its regression protection — the review checklist for any PR that edits one | Reviewer |
| [`implementation-log.md`](implementation-log.md) | Per-phase record: status, changed files, tests run, production steps, rollback, risks | The engineer closing the phase |
| [`evidence/`](evidence/) | Sandbox captures and other evidence referenced by gates | Whoever captures it |
| [`references.md`](references.md) | Official documents and external references used | Anyone adding a source |

The Verified Facts Register is [`facts-register.md`](facts-register.md) (phase P01); every ZATCA requirement a phase encodes cites an entry there.

## Phase status

| Phase | Title | Gates | Status |
|---|---|---|---|
| P00 | Stakeholder decision workshop & access provisioning | — | In progress — D-26, D-38…D-43 approved; access requests and remaining decisions open |
| P01 | Official specification acquisition & Verified Facts Register | G-01, G-02, G-03, G-09, G-16, G-17, G-18, G-22 | Partly done — Facts Register created (Clearance API page); blocked on the missing official documents |
| P02 | Living implementation log, ADR log and change-control rules | — | Done (this documentation set) — awaiting review |
| P03 | Isolated host test harness | G-11 | Done — awaiting review (G-11 closed by the working harness) |
| P04 | Golden XML baseline for Simplified documents | — | Done — awaiting review |
| P05 | Golden snapshot & data-contract baseline | — | Done — awaiting review |
| P06 | Package pipeline characterization | — | Done — awaiting review |
| P07 | Host characterization: sources, VAT, dispatch branches, callbacks | G-11 | Done with limitations — awaiting review (callbacks manual; HT-19) |
| P08 | Host characterization: email, local invoices, PDFs, recovery | G-11 | Done — awaiting review (HT-20, HT-21) |
| P09 | Frontend B2C baseline | G-10 | Not started |
| P10 | Sandbox B2C evidence pack | G-19 | Not started |
| P11 | Deploy pipeline: worker restart & post-deploy checks | G-20 | Not started |
| P12 | Alert listeners for existing ZATCA events | — | Done — awaiting review (alerts off until ops picks recipients) |
| P13 | Flag semantics & gates | G-12 | Done — awaiting review (flags dormant, default off) |
| P14 | B2B EGS configuration composition & primary-EGS invariant | G-02 | Done — awaiting review |
| P15 | Business-level tax policy resolution | — | Done — awaiting review (L-11 closed) |
| P16 | Document type & submission-model vocabulary ("dormant enum release") | G-04 | Done — awaiting review |
| P17 | Generalise document-kind comparisons | — | Done — awaiting review (eligibility moved to P41) |
| P18 | `zatca_invoices` additive columns (M-P1) | G-24 | Done — awaiting review (G-24 ops sign-off still open for the R1 deploy) |
| P19 | Cross-EGS source uniqueness | — | Done — awaiting review (run zatca:check-source-uniqueness on production before R1) |
| P20 | `BuyerParty` DTOs & `ZatcaInvoiceData` extension | G-01 | Done — awaiting review (G-01 scheme list closed from the SDK rules) |
| P21 | Snapshot v3 (Standard) | — | Done — awaiting review |
| P22 | Standard invoice data validator | G-01, G-17 | Not started |
| P23 | Standard invoice XML generator | G-01, G-18, G-22 | Not started |
| P24 | Standard credit/debit note XML generators | G-01, G-17 | Not started |
| P25 | Generator factory routing & interface | — | Approved; waits for P23/P24 (G-01) |
| P26 | Standard compliance onboarding (package) | G-02 | Not started |
| P27 | B2B EGS sandbox onboarding & CSR invoice-type experiment | G-02 | Not started |
| P28 | Clearance API contract discovery | G-03 | Not started |
| P29 | Clearance response capture campaign & fixtures | G-04, G-07, G-16 | Not started |
| P30 | Hash-chain & duplicate-UUID experiments | G-05, G-07 | Not started |
| P31 | Cleared XML & QR analysis | G-06 | Not started |
| P32 | Clearance connector method & transport-error classification | G-03, G-04 | Not started |
| P33 | Clearance response normalizer | G-04 | Not started |
| P34 | Clearance status model & transition table | G-04, G-07 | Not started |
| P35 | Reporting choke-point guard, signing status by model, submission router | G-04 | Not started |
| P36 | `ClearZatcaInvoiceJob` & per-document submission claim | G-04, G-07, G-12 | Not started |
| P37 | Clearance retry, in-doubt handling, `zatca:clear-pending` | G-07, G-12 | Not started |
| P38 | Command & policy isolation for clearance documents | — | Not started |
| P39 | Clearance events & host alert listeners | — | Not started |
| P40 | Diagnostics: status, health-check, inspect, readiness | G-02 | Not started |
| P41 | Standard note inheritance | G-01, G-17 | Not started |
| P42 | Standard note clearance lifecycle | G-04 | Not started |
| P43 | Admin note services for Standard originals (host) | G-12 | Not started |
| P44 | `sale_buyers` schema & immutability | G-15 | Not started |
| P45 | Buyer validation & normalisation | G-01 | Done — awaiting review (building/additional number formats not enforced: no ZATCA rule) |
| P46 | `SaleBuyerRecorder`, `SaleBuyerResolver`, `SaleBuyerLockService` | — | Not started |
| P47 | `company_profiles` (optional, D-16) | G-15 | Not started |
| P48 | WATHQ client transport | G-09 | Not started |
| P49 | WATHQ Commercial Registration lookup & status rules | G-09 | Not started |
| P50 | WATHQ National Address lookup & selection | G-01, G-09 | Not started |
| P51 | `CompanyVerificationService`, cache, rate limits, audit | G-09, G-15, G-25 | Not started |
| P52 | Verification endpoints & gating | G-09, G-25 | Not started |
| P53 | Lane resolver & readiness decision | G-12 | Not started |
| P54 | Company invoice sources (decorators) & dates | G-17 | Not started |
| P55 | Dispatch integration: payment listener, admin dispatch, recovery command | — | Not started |
| P56 | Held company sales | — | Not started |
| P57 | Checkout backend buyer capture (Apple Pay / Tabby / Tamara) | G-08, G-14, G-25 | Not started |
| P58 | Student Cart buyer-type selection & company form (`resources/app`) | G-10, G-25 | Not started |
| P59 | Student post-payment messaging & invoice states | — | Not started |
| P60 | Admin new paid company assignment (backend) | — | Not started |
| P61 | Explicit settlement endpoint (unpaid → paid) | G-17 | Not started |
| P62 | Admin assign & settle UI (`lms-admin-frontend`) | G-25 | Not started |
| P63 | Standard PDFs | G-06, G-08 | Not started |
| P64 | B2B delivery: email split + post-clearance tax invoice email | G-06 | Not started |
| P65 | Document resolution (authoritative, EGS-aware) | — | Not started |
| P66 | Admin list, filters, export | — | Not started |
| P67 | Admin details: buyer & clearance cards, cleared XML | G-06, G-07 | Not started |
| P68 | Rejected Standard invoice: correct buyer & reissue | G-05, G-07 | Not started |
| P69 | Settings screen: B2B readiness | — | Not started |
| P70 | Integrated sandbox E2E matrix | G-19 | Not started |
| P71 | Concurrency, chaos & failure-injection campaign | — | Not started |
| P72 | Security, privacy & capacity review | G-15 | Not started |
| P73 | Production dormant releases R1–R3 & B2C verification | G-19, G-20, G-24 | Not started |
| P74 | Production B2B EGS onboarding | G-02, G-13 | Not started |
| P75 | Controlled pilot | G-23 | Not started |
| P76 | WATHQ enablement | G-09 | Not started |
| P77 | General availability & hypercare | G-21, G-23 | Not started |

## Flags and environment

Introduced by P13 (2026-09-28); nothing reads the two new flags yet.

| Variable | Config key | Read through | Default | Meaning |
|---|---|---|---|---|
| `ZATCA_ENABLED` (existing) | `zatca.enabled` | `ZatcaGate::enabled()` | on (fail-open: only an explicit false turns it off) | every ZATCA side effect |
| `ZATCA_B2B_ENABLED` | `zatca.b2b.enabled` | `ZatcaGate::b2bEnabled()` | **off** (fail-closed: only `true`/`1`/`on`/`yes`) | new company (Standard) sales; also needs `ZATCA_ENABLED`. Off stops new company sales only — documents already signed keep clearing (D-19) |
| `WATHQ_ENABLED` | `services.wathq.enabled` | `App\Support\WathqGate::enabled()` | **off** (fail-closed) | WATHQ company verification; needs both flags above. Off with B2B on = manual company entry (D-43) |

`Zatca\Support\B2bReadiness::ready()` answers "not ready" unconditionally until P40. After changing any flag on a server: `php artisan optimize:clear` (config is cached).

## Production steps and rollback

None so far. Every phase records its own production steps (or "none") and rollback note in [`implementation-log.md`](implementation-log.md). The release train and rollback floor are in blueprint §32–§33.

## Changelog

* 2026-09-28 — P01: Facts Register created from the official Clearance API page. P02: governance documentation created. P04: Simplified XML golden fixtures added in `packages/zatca/tests/Unit/Golden/`. P05: snapshot golden fixtures and DTO constructor contract added. P06: package characterization suite added. Approved: P16/P17/P25 to start, D-19 (drain), G-11 (SQLite only). P16: Standard document types, submission model and clearance statuses added (dormant). P03: isolated host test harness (`phpunit.b2b.xml`, in-memory SQLite) — G-11 closed. P17: document-kind comparisons generalised; note eligibility moved to P41.
