<?php

namespace Zatca\Jobs\Concerns;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Events\ZatcaCreditNoteAccepted;
use Zatca\Events\ZatcaDebitNoteAccepted;
use Zatca\Events\ZatcaInvoiceNeedsAttention;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Support\ZatcaGate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Standard (B2B) counterpart of ReportsZatcaInvoice — P25. Calls
 * POST /invoices/clearance/single instead of /invoices/reporting/single and
 * stores ZATCA's own re-signed copy of the document (cleared_xml_base64/
 * cleared_invoice_hash/cleared_at, added in P18) on success. Everything else —
 * the attempt log, credit/debit note acceptance, the needs-attention event —
 * mirrors ReportsZatcaInvoice exactly, deliberately duplicated rather than
 * shared: the two status vocabularies and retry rules are not interchangeable
 * (see ZatcaInvoiceStatus::isClearanceRetryable() vs isRetryable()), and a
 * shared abstraction would risk one profile's rule silently leaking into the
 * other's.
 *
 * A network-level failure (no HTTP response at all) is never treated as
 * "transient, retry it" the way reporting does — it lands in ClearanceInDoubt
 * instead, because clearance is not naturally idempotent the way reporting is
 * (F-CL-07: behaviour for the same UUID with a different hash is not
 * documented, G-07). ClearanceInDoubt IS in ZatcaInvoiceStatus::isClearanceRetryable()'s
 * list, so the scheduled sweep will resend it — but it is still surfaced via
 * ZatcaInvoiceNeedsAttention on every occurrence (not just when retries are
 * exhausted, unlike a plain transient failure), so a human sees it before
 * assuming a resend is safe for that specific document.
 */
trait ClearsZatcaInvoice
{
    /**
     * @param  array{type: string, id: int|string}|null  $triggeredBy  Actor that asked for
     *   this submission. Null means the system did.
     */
    protected function clear(int $zatcaInvoiceId, ConnectorFactory $connectorFactory, ?array $triggeredBy = null): void
    {
        $gate = app(ZatcaGate::class);

        if ($gate->disabled()) {
            $gate->skip("clearing ZATCA document #{$zatcaInvoiceId}");

            return;
        }

        $invoice = ZatcaInvoice::query()->find($zatcaInvoiceId);

        if (! $invoice || $invoice->status->isTerminalSuccess()) {
            return; // Missing, or already cleared — never resend the same document.
        }

        $certificateState = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->value('certificate_state');

        if ($certificateState === ZatcaCertificateState::Expired->value) {
            Log::warning("[ZATCA] Skipped clearing invoice #{$invoice->id}: production certificate is Expired.");

            return;
        }

        $attemptNumber = $invoice->attempt_count + 1;

        $result = $connectorFactory->make()->clearInvoice(
            $invoice->egs_identifier,
            $invoice->invoice_hash,
            $invoice->uuid,
            $invoice->signed_xml_base64,
        );

        $acceptedCreditNoteId = null;
        $acceptedDebitNoteId = null;

        $finalStatus = DB::transaction(function () use ($zatcaInvoiceId, $result, $attemptNumber, $triggeredBy, &$acceptedCreditNoteId, &$acceptedDebitNoteId) {
            $invoice = ZatcaInvoice::query()->lockForUpdate()->find($zatcaInvoiceId);

            if (! $invoice || $invoice->status->isTerminalSuccess()) {
                return null;
            }

            $networkFailure = $result->httpStatus === 0;
            $isTransient = $result->httpStatus >= 500;
            $rejected = ! $result->successful && ($result->payload->clearanceStatus ?? null) === 'NOT_CLEARED';

            $status = match (true) {
                $result->successful && $result->status === ZatcaResponseStatus::Pass => ZatcaInvoiceStatus::Cleared,
                $result->successful && $result->status === ZatcaResponseStatus::Warning => ZatcaInvoiceStatus::ClearedWithWarnings,
                $networkFailure => ZatcaInvoiceStatus::ClearanceInDoubt,
                $isTransient && $attemptNumber < Config::get('zatca.retries.reporting.max_attempts', 4) => ZatcaInvoiceStatus::ClearanceRetrying,
                $isTransient => ZatcaInvoiceStatus::ClearanceManualReview,
                $rejected => ZatcaInvoiceStatus::ClearanceRejected,
                default => ZatcaInvoiceStatus::ClearanceManualReview,
            };

            $invoice->update([
                'status' => $status,
                'reporting_status' => $result->payload->clearanceStatus ?? $result->status->value,
                'clearance_status' => $result->payload->clearanceStatus ?? null,
                'cleared_xml_base64' => $result->payload->clearedInvoiceBase64 ?? null,
                'cleared_invoice_hash' => $status->isTerminalSuccess() ? $invoice->invoice_hash : $invoice->cleared_invoice_hash,
                'last_zatca_response' => $result->sanitizedResponse,
                'last_error' => $status === ZatcaInvoiceStatus::ClearedWithWarnings
                    ? (!empty($result->warnings) ? implode('; ', $result->warnings) : null)
                    : ($result->successful ? null : implode('; ', $result->errors)),
                'attempt_count' => $attemptNumber,
                'cleared_at' => $status->isTerminalSuccess() ? now() : $invoice->cleared_at,
                'in_doubt_since' => $status === ZatcaInvoiceStatus::ClearanceInDoubt ? ($invoice->in_doubt_since ?? now()) : null,
            ]);

            if ($status->isTerminalSuccess() && $invoice->credit_note_id !== null) {
                $written = ZatcaCreditNote::query()
                    ->whereKey($invoice->credit_note_id)
                    ->whereNull('accepted_at')
                    ->update(['accepted_at' => now()]);

                if ($written > 0) {
                    $acceptedCreditNoteId = $invoice->credit_note_id;
                }
            }

            if ($status->isTerminalSuccess() && $invoice->debit_note_id !== null) {
                $written = ZatcaDebitNote::query()
                    ->whereKey($invoice->debit_note_id)
                    ->whereNull('accepted_at')
                    ->update(['accepted_at' => now()]);

                if ($written > 0) {
                    $acceptedDebitNoteId = $invoice->debit_note_id;
                }
            }

            ZatcaSubmissionAttempt::create([
                'zatca_invoice_id' => $invoice->id,
                'attempt_number' => $attemptNumber,
                'outcome' => $status->isTerminalSuccess() ? $status->value : 'failed',
                'triggered_by_type' => $triggeredBy['type'] ?? null,
                'triggered_by_id' => $triggeredBy['id'] ?? null,
                'http_status' => $result->httpStatus,
                'error_code' => $result->successful
                    ? ($result->sanitizedResponse['warningCodes'][0] ?? null)
                    : ($result->sanitizedResponse['errorCodes'][0] ?? null),
                'sanitized_response' => $result->sanitizedResponse,
                'error_message' => $status === ZatcaInvoiceStatus::ClearedWithWarnings
                    ? (!empty($result->warnings) ? implode('; ', $result->warnings) : null)
                    : ($result->successful ? null : implode('; ', $result->errors)),
                'attempted_at' => now(),
            ]);

            return $status;
        });

        if (in_array($finalStatus, [ZatcaInvoiceStatus::ClearanceManualReview, ZatcaInvoiceStatus::ClearanceRejected, ZatcaInvoiceStatus::ClearanceInDoubt], true)) {
            $this->announceNeedsAttention($zatcaInvoiceId, $result, $attemptNumber);
        }

        if ($acceptedCreditNoteId !== null) {
            $creditNote = ZatcaCreditNote::query()->find($acceptedCreditNoteId);

            if ($creditNote) {
                Event::dispatch(new ZatcaCreditNoteAccepted($creditNote));
            }
        }

        if ($acceptedDebitNoteId !== null) {
            $debitNote = ZatcaDebitNote::query()->find($acceptedDebitNoteId);

            if ($debitNote) {
                Event::dispatch(new ZatcaDebitNoteAccepted($debitNote));
            }
        }
    }

    private function announceNeedsAttention(int $zatcaInvoiceId, ZatcaApiResult $result, int $attemptNumber): void
    {
        $invoice = ZatcaInvoice::query()->find($zatcaInvoiceId);

        if (! $invoice) {
            return;
        }

        $errors = $result->errors ? implode('; ', $result->errors) : 'no error message returned';

        $reason = $result->httpStatus === 0
            ? "Clearance is in doubt after {$attemptNumber} attempt(s) — no response from ZATCA (network failure). Do not resubmit blindly: {$errors}"
            : "ZATCA did not clear the invoice (HTTP {$result->httpStatus}): {$errors}";

        Log::error("[ZATCA] Invoice #{$invoice->id} needs manual review (clearance). {$reason}");

        Event::dispatch(new ZatcaInvoiceNeedsAttention($invoice, $reason));
    }
}
