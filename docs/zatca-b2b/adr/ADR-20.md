# ADR-20 — Cross-EGS uniqueness

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Chosen: DB unique `(environment, source_reference)` + orchestrator pre-check. Evidence: L-18/F6 [CODE]. Risk: migration fails if duplicates exist (pre-flight command).
