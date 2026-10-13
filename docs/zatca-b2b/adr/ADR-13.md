# ADR-13 — Email timing

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Chosen: confirmation immediately without tax PDF; tax invoice after `ZatcaInvoiceCleared` with delivery claim table. Evidence: B2C sync-sign-then-email pattern [CODE]. Risk: students confused by delay → messaging (P59).
