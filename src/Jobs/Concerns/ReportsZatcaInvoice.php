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
use Zatca\Jobs\RetryZatcaClearanceJob;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Support\SubmissionRetry;
use Zatca\Support\ZatcaGate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Shared reporting logic for GenerateZatcaInvoiceJob's follow-up
 * (ReportZatcaInvoiceJob) and scheduled retries (RetryZatcaInvoiceJob).
 * Both dispatch the SAME stored invoice_hash/uuid/signed_xml_base64 —
 * signing never happens again here, only the reporting API call.
 */
trait ReportsZatcaInvoice
{
    /**
     * @param  array{type: string, id: int|string}|null  $triggeredBy  Actor that asked for
     *   this submission. Null means the system did (first report, scheduler, requeue) —
     *   it is never guessed or backfilled.
     */
    protected function report(int $zatcaInvoiceId, ConnectorFactory $connectorFactory, ?array $triggeredBy = null): void
    {
        // Backstop for reporting jobs already on the queue when the switch was turned off.
        // Resolved from the container rather than injected, because this trait is shared by
        // two jobs whose handle() signatures are part of their public contract.
        //
        // First statement in the method, before the invoice is even loaded: no read, no
        // status change, no attempt row, and above all no HTTP request to ZATCA. Returning
        // quietly leaves the invoice in Pending Reporting/Retrying with its attempt_count
        // untouched, which is exactly where `zatca:report-pending` will find it once
        // ZATCA is switched back on.
        $gate = app(ZatcaGate::class);

        if ($gate->disabled()) {
            $gate->skip("reporting ZATCA document #{$zatcaInvoiceId}");

            return;
        }

        $invoice = ZatcaInvoice::query()->find($zatcaInvoiceId);

        if (! $invoice || $invoice->status->isTerminalSuccess()) {
            return; // Missing, or already reported — never resend the same invoice.
        }

        // Hard guard: a Standard document is cleared, never reported. However it got here
        // (an old queued job, a status set by hand), hand it to the clearance path instead
        // of posting it to the Reporting endpoint.
        if (SubmissionRetry::isClearance($invoice)) {
            Log::warning("[ZATCA] Document #{$invoice->id} is a Clearance document; rerouted from reporting to clearance.");
            RetryZatcaClearanceJob::dispatch($invoice->id, $triggeredBy);

            return;
        }

        $certificateState = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->value('certificate_state');

        if ($certificateState === ZatcaCertificateState::Expired) {
            // Stop all reporting for this EGS/environment immediately — the invoice stays
            // Pending Reporting/Retrying, never sent with an expired cert. CertificateLifecycleManager
            // resumes it automatically once renewal succeeds.
            Log::warning("[ZATCA] Skipped reporting invoice #{$invoice->id}: production certificate is Expired.");

            return;
        }

        $attemptNumber = $invoice->attempt_count + 1;

        // The network call happens outside any DB lock — it can take seconds and must
        // never hold a row lock open while waiting on ZATCA.
        $result = $connectorFactory->make()->reportInvoice(
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
                return null; // Another worker already finalized this invoice — do not double-report/overwrite.
            }

            $isTransient = $result->httpStatus === 0 || $result->httpStatus >= 500;

            $status = match (true) {
                $result->successful && $result->status === ZatcaResponseStatus::Pass => ZatcaInvoiceStatus::Reported,
                $result->successful && $result->status === ZatcaResponseStatus::Warning => ZatcaInvoiceStatus::ReportedWithWarnings,
                $isTransient && $attemptNumber < Config::get('zatca.retries.reporting.max_attempts', 4) => ZatcaInvoiceStatus::Retrying,
                $isTransient => ZatcaInvoiceStatus::ManualReview,
                // A non-transient ERROR (validation failure) needs a human fix — never auto-retry it.
                default => ZatcaInvoiceStatus::ManualReview,
            };

            $invoice->update([
                'status' => $status,
                'reporting_status' => $result->sanitizedResponse['reportingStatus'] ?? $result->status->value,
                'last_zatca_response' => $result->sanitizedResponse,
                'last_error' => $status === ZatcaInvoiceStatus::ReportedWithWarnings
                    ? (!empty($result->warnings) ? implode('; ', $result->warnings) : null)
                    : ($result->successful ? null : implode('; ', $result->errors)),
                'attempt_count' => $attemptNumber,
                'reported_at' => $status->isTerminalSuccess() ? now() : $invoice->reported_at,
            ]);

            // Credit Status (BR-09) is derived from accepted_at, and nowhere else — a
            // Credit Note that is pending, failed or rejected must never count as
            // credited. Written in the SAME transaction as the terminal status so the
            // two can never observably disagree, and only once (`whereNull`) so a
            // regenerated note's later acceptance cannot overwrite an earlier one.
            if ($status->isTerminalSuccess() && $invoice->credit_note_id !== null) {
                $written = ZatcaCreditNote::query()
                    ->whereKey($invoice->credit_note_id)
                    ->whereNull('accepted_at')
                    ->update(['accepted_at' => now()]);

                // Only when THIS call was the one that wrote it — a regenerated note's
                // later acceptance must not re-fire the event for an already-credited note.
                if ($written > 0) {
                    $acceptedCreditNoteId = $invoice->credit_note_id;
                }
            }

            // The Debit Note counterpart, on exactly the same terms: accepted_at is the
            // ONLY thing that makes a debit note count financially, it is written in the
            // SAME transaction as the terminal status so the two can never observably
            // disagree, and only once (`whereNull`) so a regenerated note's later
            // acceptance cannot overwrite an earlier one.
            //
            // A row carries at most one of credit_note_id/debit_note_id, so these two
            // blocks are mutually exclusive in practice.
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
                // Null when the system triggered this attempt; set only when a human did.
                'triggered_by_type' => $triggeredBy['type'] ?? null,
                'triggered_by_id' => $triggeredBy['id'] ?? null,
                'http_status' => $result->httpStatus,
                'error_code' => $result->successful
                    ? ($result->sanitizedResponse['warningCodes'][0] ?? null)
                    : ($result->sanitizedResponse['errorCodes'][0] ?? null),
                'sanitized_response' => $result->sanitizedResponse,
                'error_message' => $status === ZatcaInvoiceStatus::ReportedWithWarnings
                    ? (!empty($result->warnings) ? implode('; ', $result->warnings) : null)
                    : ($result->successful ? null : implode('; ', $result->errors)),
                'attempted_at' => now(),
            ]);

            return $status;
        });

        // Dispatched after the transaction commits, so a listener that reads the
        // invoice sees the persisted state. Manual review is the one outcome that
        // will never resolve itself — everything else is retried by the scheduler.
        if ($finalStatus === ZatcaInvoiceStatus::ManualReview) {
            $this->announceNeedsAttention($zatcaInvoiceId, $result, $attemptNumber);
        }

        // Same after-commit reasoning as above: a listener that reads the credit note
        // (or dispatches a queued job against it) must see accepted_at already persisted.
        if ($acceptedCreditNoteId !== null) {
            $creditNote = ZatcaCreditNote::query()->find($acceptedCreditNoteId);

            if ($creditNote) {
                Event::dispatch(new ZatcaCreditNoteAccepted($creditNote));
            }
        }

        // Same after-commit reasoning: the host's listener grants access off the
        // back of this, and must see accepted_at already persisted.
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

        $reason = $result->httpStatus === 0 || $result->httpStatus >= 500
            ? "Reporting gave up after {$attemptNumber} attempt(s) against a transient failure (HTTP {$result->httpStatus}): {$errors}"
            : "ZATCA rejected the invoice (HTTP {$result->httpStatus}): {$errors}";

        Log::error("[ZATCA] Invoice #{$invoice->id} needs manual review. {$reason}");

        Event::dispatch(new ZatcaInvoiceNeedsAttention($invoice, $reason));
    }
}
