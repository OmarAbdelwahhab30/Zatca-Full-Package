# ADR-04 — Clearance state model

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A reuse B2C statuses + `submission_model` filter everywhere; B distinct clearance statuses + `submission_model` column. Chosen: **B**. Why: existing B2C queries whitelist B2C statuses [CODE], so B2B rows are invisible to them without editing ~10 queries; fail-safe against old workers. Risk: more enum cases in UI/filters; `isTerminalSuccess()` must include cleared statuses (touchpoint). Verify: final status set after G-04.
