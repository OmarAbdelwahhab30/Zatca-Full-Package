# ADR-17 — Standard XML generator structure

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A parameterise the Simplified abstract; B separate Standard abstract (copying shared rendering). Chosen: **B** in v1, refactor later under golden tests. Why: zero risk to Simplified bytes. Risk: duplication drift (mitigated by shared golden totals tests).
