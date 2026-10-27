# Submission statuses and responses

<a id="11-understanding-zatca-responses"></a>
## Understanding ZATCA responses

### HTTP 200 is not the same as "accepted"

A request can succeed while ZATCA still refuses the invoice. Always check the **status** on the `ZatcaInvoice` row, never the HTTP call.

The simple rule is that an invoice is done only when `$invoice->status->isTerminalSuccess()` is `true`.

### All statuses

| Status | Flow | Meaning | What you do |
|---|---|---|---|
| `draft` | both | Built, not signed yet | Nothing. It finishes on retry. |
| `pending_reporting` | B2C | Signed, waiting to be reported | Nothing. The queue or sweep sends it. |
| `reported` | B2C | **Accepted** | Done |
| `reported_with_warnings` | B2C | **Accepted**, with warnings | Done. Read `last_error` and fix the data for next time. |
| `retrying` | B2C | ZATCA down (network or 5xx). Will retry. | Nothing |
| `manual_review` | B2C | Rejected (4xx) or retries used up | A human reads `last_error` |
| `cleared` | B2B | **Accepted and stamped** | Give `cleared_xml_base64` to the buyer |
| `cleared_with_warnings` | B2B | **Accepted**, with warnings | Same |
| `clearance_retrying` | B2B | ZATCA 5xx, will retry | Nothing |
| `clearance_in_doubt` | B2B | Network failure: unknown if ZATCA got it | Wait. The sweep retries it. |
| `clearance_rejected` | B2B | ZATCA said `NOT_CLEARED` | Fix and re-issue. Never resend. |
| `clearance_manual_review` | B2B | Other failure or retries used up | A human looks |
| `pending_clearance` | B2B | Signed, waiting to be cleared | Nothing. The queue or sweep sends it. |
| `failed`, `generated` | – | Defined but not written by the package today | – |

### Error types and where they end up

| Problem | HTTP | Result |
|---|---|---|
| Validation errors (bad totals, missing field…) | 400 | `manual_review` / `clearance_rejected`, with ZATCA's codes in `last_zatca_response.validationErrors` |
| Accepted with warnings | 200/202 | `reported_with_warnings` / `cleared_with_warnings` |
| Authentication (wrong or expired token) | 401 | `manual_review`. Check credentials and certificate. |
| Already reported (same invoice sent again) | 409 | Treated as success **only** if ZATCA's body confirms it |
| ZATCA server error | 5xx | `retrying` / `clearance_retrying`, retried up to `ZATCA_REPORTING_RETRY_ATTEMPTS` |
| Network failure, timeout, connection refused, DNS failure | 0 | B2C: `retrying`. B2B: `clearance_in_doubt`. An attempt row is saved with HTTP status 0. |
| Bad invoice data (your mapper): totals, line math, VAT category, currency, B2B buyer | – | Refused **before** a counter number is used. `ZatcaException` with a clear message, and the job retries then fails. |
| Certificate or signing problem (SDK) | – | `ZatcaSdkProcessException`. The invoice stays `draft` and the retry resumes it. |
| Expired production certificate | – | Sending is paused. Invoices wait in `pending_reporting`. |

### Fake example of a rejected B2C invoice (`last_zatca_response`)

```json
{
  "status": "ERROR",
  "reportingStatus": "NOT_REPORTED",
  "alreadyReported": false,
  "errorCodes": ["BR-KSA-EN16931-11"],
  "validationErrors": [
    { "code": "BR-KSA-EN16931-11", "message": "Invoice line net amount must equal quantity × price." }
  ],
  "validationWarnings": []
}
```

### What is saved for you

Everything you need is already in your database. You don't need to copy it anywhere:

- `zatca_invoices`: status, UUID, ICV, hashes, signed XML, QR, `last_zatca_response`, `last_error`, `reported_at` / `cleared_at`, `cleared_xml_base64`, and a frozen copy of the invoice data.
- `zatca_submission_attempts`: every attempt, with HTTP status, error code and the cleaned response. Secrets are removed before saving.

On **your** order, saving `source_reference` (for example `INV-1001`) is enough to find the document later.

### Retries and duplicates

- A retry **never** signs again. It sends the exact same signed XML, UUID and counter.
- Generating twice for the same `sourceReference` returns the existing document instead of making a new one.
- Accepted documents (`isTerminalSuccess()`) are never sent again.
- Use the same `idempotencyKey` when retrying a credit or debit note request, and you get the same note back.
- A rejected document is fixed by **re-issuing** (a new document with a new UUID and counter), not by resending.

### Exceptions you may catch

All extend `Zatca\Exceptions\ZatcaException`:

| Exception | When |
|---|---|
| `ZatcaException` | Invalid invoice data, missing CSR, a ZATCA onboarding call failed, bad config |
| `ZatcaCredentialsMissingException` | The EGS has no production certificate yet (onboarding not finished) |
| `ZatcaDisabledException` | You called the orchestrator directly while `ZATCA_ENABLED=false` |
| `CrossEgsDuplicateException` | This sale already has a document on another EGS (for example B2C and B2B) |
| `CreditNoteException` | Credit note refused (not eligible, nothing selected, amount changed, zero value…) |
| `DebitNoteException` | Debit note refused (not eligible, no lines, bad line reference…) |
| `ZatcaSdkProcessException` | Java or SDK failed, timed out, or produced no output |
| `ZatcaSdkOutputChangedException` | The SDK printed something the package can't read (different SDK version) |
| `ZatcaSdkFingerprintMismatchException` | The SDK jar changed |
| `ZatcaContractChangedException` | ZATCA returned an unexpected response shape |
