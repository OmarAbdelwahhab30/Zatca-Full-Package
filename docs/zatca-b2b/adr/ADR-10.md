# ADR-10 — Rejected invoice reissue

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A reuse `regenerate()`; B new `reissueWithCorrectedBuyer()`; C edit-XML. Chosen: **B**. Why: A forbids buyer change by design [CODE spec]; C mutates submitted docs (L-05). Verify: G-05/G-07 (chain & duplicates).
