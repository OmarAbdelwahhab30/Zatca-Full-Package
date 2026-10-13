# ADR-05 — PIH commit point for Standard

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A commit at signing (existing); B commit after clearance with serialised B2B signing. Chosen: **A provisional**. Evidence: mechanics [CODE]; ZATCA semantics [VERIFY G-05]. Risk: if B is required, throughput limits and redesign of `sign()` for b2b. Verify: P30.
