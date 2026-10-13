# ADR-01 — Separate B2B EGS vs shared EGS

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A separate `b2b` EGS; B single EGS re-onboarded with Standard+Simplified capability. Chosen: **A**. Why: live B2C certificate/chain untouched, independent onboarding/rollback, B2B incidents cannot stall B2C signing. Evidence: per-EGS credentials/state [CODE]. Risks: two certificates to renew; seller identity drift (mitigated P14); ZATCA may not allow/like a Standard-only second EGS. Verify: G-02 (official doc + sandbox onboarding of a second EGS).
