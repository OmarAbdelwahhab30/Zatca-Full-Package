# ADR-18 — Company invoice source

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A modify existing sources; B decorators that call existing sources and replace buyer/subtype/dates/EGS. Chosen: **B**. Why: pricing logic single-sourced; B2C sources untouched. Risk: EGS-dependent VAT policy inside the inner source → P15 prerequisite.
