# ADR-29 — Buyer-type selector visibility

* **Status:** Accepted — D-38 approved 2026-09-28
* **Source:** blueprint §34

Options: A always shown (story literal); B shown only while B2B is available, no default when shown, request unchanged when hidden. Chosen: **B**. Why: keeps B2C checkout unchanged whenever B2B is off or not ready (prompt requirement) while meeting #33496 when B2B is live. Risk: while B2B is live every student gets one extra click (risk 26). Verify: D-38, G-25.
