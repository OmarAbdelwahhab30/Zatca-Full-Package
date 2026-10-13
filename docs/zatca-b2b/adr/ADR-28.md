# ADR-28 — Binding verified company data to the sale

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A the SPA shows WATHQ data read-only and sends it back with the pay request; B the server stores each verification (M-H7, user-bound, TTL) and the pay request carries only a `verification_id`, from which the server copies legal name / CR / status / address. Chosen: **B**. Why: #33496 AC-06/AC-07 require that an unverified CR cannot complete checkout and that verified data cannot be arbitrarily modified; with A, a forged request could change the data. Risk: one more table and an expiry the student can hit (clear message; re-verify is served from the 24 h cache). Update 2026-09-28: D-40 approved — the record also lists the address fields WATHQ did not return; the server accepts client values for those fields only. Verify: G-25.
