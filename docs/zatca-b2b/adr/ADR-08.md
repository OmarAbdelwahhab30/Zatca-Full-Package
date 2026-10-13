# ADR-08 — WATHQ failure handling

* **Status:** Accepted — D-39 approved 2026-09-28
* **Source:** blueprint §34

Options: A manual entry as an automatic fallback when WATHQ is unavailable/not found; B WATHQ mode blocks company checkout on any WATHQ failure, manual entry exists only as a configured mode when WATHQ is disabled (Case D). Chosen: **B**. Why: user story #33496 (Alt-06/07, AC-06, story BR-08) forbids continuing with unverified company information and forbids silent fallback. Risk: a WATHQ outage blocks company checkouts (mitigated by caching, retry, clear messaging and the admin-assisted path); typos in Case D manual addresses → rejections → reissue workload. Verify: G-09, G-25 (product confirms D-39/D-40).
