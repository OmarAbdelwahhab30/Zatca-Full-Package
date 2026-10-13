# ADR-26 — Submission dispatch routing

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A change every dispatch site; B single `ZatcaSubmission::dispatchFor($doc)` for new code + choke-point guards for legacy sites. Chosen: **B**. Evidence: ≈11 sites [CODE].
