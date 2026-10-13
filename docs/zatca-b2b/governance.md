# Governance rules

These rules apply to every B2B phase. They come from the original brief, the user's instructions of 2026-09-28 and blueprint §39.0.

## Standing rules

1. **Official-documentation rule (R-DOC).** Before any ZATCA-related phase is implemented, every ZATCA requirement it encodes is checked against official ZATCA documentation and cited in the Verified Facts Register (`facts-register.md`, P01). If the official document is not available, a verification gate is created (next free G-number in [`gates.md`](gates.md)) or the existing one stays open, and **that phase stops** until the requirement is confirmed. No ZATCA rule is written from memory, from blog posts or from examples alone; an example in an official document is recorded as an example, not as a rule.
2. **One phase at a time.** Phases are implemented one by one, never combined. Gates are never skipped: a phase whose gate is open does not start (or stops where the gate applies).
3. **After each phase:** run the required tests, record the changed files, the tests run and their result, and the risks in [`implementation-log.md`](implementation-log.md), and report them.
4. **Approval before risky phases.** A phase is *risky* when it touches a production code path, the database schema, configuration, deployment/operations or an external system. The next risky phase starts only after explicit approval. Test-only and documentation-only phases may follow each other, each still reported on its own.
5. **B2C stays unchanged.** The golden suites (P04, P05) and characterization suites (P06–P08) must pass unchanged at the end of every phase. A golden fixture is only rewritten for an intended, reviewed B2C change (`composer golden:update` in `packages/zatca`, never in CI).
6. **Never run the legacy host suite** (`php artisan test` / the root `phpunit.xml`) — it runs against the configured MySQL database. Host tests for B2B run only through the isolated harness of P03 (`phpunit.b2b.xml`, in-memory SQLite — G-11 decided 2026-09-28: SQLite only), which refuses any other connection. Package tests (`packages/zatca`, in-memory SQLite) are safe.
7. **No commits without instruction.** Never touch `Asas-workspace.code-workspace` or `B2B-User-Stories/`. Run `git status` before any destructive operation.
8. **Line endings.** Files in this repository are LF in git and CRLF in Windows working copies (`core.autocrlf`); keep a file's line endings when editing it.
9. **No secrets or real personal data** in this folder, in fixtures or in evidence captures. Sandbox captures are redacted before they are stored in [`evidence/`](evidence/).
10. **Latent B2C defects** found during B2B work are recorded (blueprint Appendix D) and fixed in separate tickets, never silently inside a B2B phase.

## Conventions for every phase (blueprint §39.0)

* **Every phase uses the same 26 fields in the same order.** "None" means the phase has no impact in that dimension (it is a statement, not an omission).
* **Standard DoD (SDoD)** — applies to every phase in addition to its own DoD: (1) code reviewed by a second engineer; (2) every test listed under *Tests* passes; (3) the B2C golden suites (P04, P05) and characterization suites (P06–P08) pass unchanged; (4) docs updated (package `README.md` / `docs/OPERATIONS.md` / `docs/PROBLEMS_GUIDES.md` where behaviour or operations change); (5) living implementation log updated: phase status, production steps (or "none"), rollback note, changelog line (P02); (6) all gates listed for the phase closed; (7) no TODO without a ticket; (8) **R-DOC** — for a ZATCA-related phase, every ZATCA requirement it encodes cites an official document in the Facts Register (P01); a phase that only freezes today's output (P04–P08) claims no ZATCA requirement and states so.
* **Official-documentation rule (R-DOC, approved 2026-09-28).** Before any ZATCA-related phase starts, its requirements are checked against official ZATCA documentation. If a document is unavailable, a verification gate is created (next free G-number) or the existing gate stays open, and **the phase stops** until it is confirmed. Phases are implemented one at a time, never combined; after each: required tests run, changed files and risks reported, and approval obtained before the next risky phase (any phase that touches production code paths, schema, config, ops or external systems).
* **Standard verification commands (SVC)**
  * Package: `cd packages/zatca && php vendor/bin/phpunit` (full) — filters given per phase.
  * Host harness (after P03): `php vendor/bin/phpunit -c phpunit.b2b.xml` (file name decided in P03).
  * Admin SPA: `cd ../lms-admin-frontend && npm run build && npx vitest run`.
  * Student SPA: `npm run build` (+ `npx vitest run` if D-25).
  * Ops read-only: `php artisan zatca:health-check`, `php artisan zatca:status`, `php artisan zatca:inspect state --egs=…`.
* **Change classes**: *additive* (new files only), *touchpoint* (edit to a shared B2C class listed in Appendix B as TP-xx), *ops* (pipeline/config), *external* (sandbox/documents/people).
* Host files are CRLF on disk (earlier plan note) — preserve line endings.
