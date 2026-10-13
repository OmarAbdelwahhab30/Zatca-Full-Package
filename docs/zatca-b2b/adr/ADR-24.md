# ADR-24 — Host test harness

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: SQLite in-memory with curated migrations; dedicated MySQL test schema; both (SQLite default, MySQL for concurrency). Chosen: **SQLite only — decided 2026-09-28 (G-11)**. Consequence: real row locks are never exercised by automated tests; `@group concurrency` tests skip on SQLite and the properties are covered by review, unique constraints and staging drills.
