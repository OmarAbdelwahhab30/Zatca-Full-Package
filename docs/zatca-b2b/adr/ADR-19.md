# ADR-19 — Tax policy for multiple EGS

* **Status:** Proposed — final once its "Verify" items close
* **Source:** blueprint §34

Options: A seed b2b row; B resolve through primary EGS for all EGS. Chosen: **B**. Why: policy is a business setting, avoids drift and admin UI change. Evidence: L-11 [CODE]. Risk: touches `VatCalculator`/`ZatcaSettingService` (B2C-identical results, tested).
