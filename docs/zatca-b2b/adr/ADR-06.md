# ADR-06 — Buyer snapshot architecture

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A host `sale_buyers` (immutable per sale) + package `BuyerParty` in snapshot v3; B store buyer only in package snapshot; C reuse `invoices` table. Chosen: **A**. Why: buyer must exist before the gateway redirect (no ZATCA row yet) and survive callbacks; package must stay host-agnostic. Risk: two copies (sale_buyers vs snapshot) — the snapshot is the legal truth after signing. Verify: none external.
