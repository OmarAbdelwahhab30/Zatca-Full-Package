# ADR-16 — Document type representation

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A new enum cases `standard_*`; B keep `simplified_*` + subtype column. Chosen: **A** (+ `submission_model` column). Why: truthful values; unknown cases fail closed in `match` [CODE: exhaustive matches]. Risk: every `=== Invoice` comparison must be generalised (P17 inventory).
