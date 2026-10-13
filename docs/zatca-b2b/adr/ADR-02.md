# ADR-02 — CSR invoice type of the b2b EGS

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: `1000` (standard only, assumed meaning), `1100` (both). Chosen: **undecided**; default hypothesis `1000` if ZATCA permits, else `1100` (then Simplified compliance steps also required for that EGS). Evidence: `1100` valid [DOC]; meaning of digits [VERIFY]. Risks: wrong type = re-onboarding. Verify: Developer Portal manual §4.3 + sandbox experiment P27.
