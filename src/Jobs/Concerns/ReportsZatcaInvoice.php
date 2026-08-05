<?php

namespace Asas\Zatca\Jobs\Concerns;

use Asas\Zatca\Enums\ZatcaCertificateState;
use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaInvoiceStatus;
use Asas\Zatca\Enums\ZatcaResponseStatus;
use Asas\Zatca\Models\ZatcaEgsCredential;
use Asas\Zatca\Models\ZatcaInvoice;
use Asas\Zatca\Models\ZatcaSubmissionAttempt;
use Asas\Zatca\Services\Connectors\ConnectorFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared reporting logic for GenerateZatcaInvoiceJob's follow-up
 * (ReportZatcaInvoiceJob) and scheduled retries (RetryZatcaInvoiceJob).
 * Both dispatch the SAME stored invoice_hash/uuid/signed_xml_base64 —
 * signing never happens again here, only the reporting API call.
 */
trait ReportsZatcaInvoice
{
    protected function report(int $zatcaInvoiceId, ConnectorFactory $connectorFactory): void
    {
        $invoice = ZatcaInvoice::query()->find($zatcaInvoiceId);

        if (! $invoice || $invoice->status->isTerminalSuccess()) {
            return; // Missing, or already reported — never resend the same invoice.
        }

        $certificateState = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->value('certificate_state');

        if ($certificateState === ZatcaCertificateState::Expired->value) {
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

        DB::transaction(function () use ($zatcaInvoiceId, $result, $attemptNumber) {
            $invoice = ZatcaInvoice::query()->lockForUpdate()->find($zatcaInvoiceId);

            if (! $invoice || $invoice->status->isTerminalSuccess()) {
                return; // Another worker already finalized this invoice — do not double-report/overwrite.
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
                'last_error' => $result->successful ? null : implode('; ', $result->errors),
                'attempt_count' => $attemptNumber,
                'reported_at' => $status->isTerminalSuccess() ? now() : $invoice->reported_at,
            ]);

            ZatcaSubmissionAttempt::create([
                'zatca_invoice_id' => $invoice->id,
                'attempt_number' => $attemptNumber,
                'outcome' => $status->isTerminalSuccess() ? $status->value : 'failed',
                'http_status' => $result->httpStatus,
                'sanitized_response' => $result->sanitizedResponse,
                'error_message' => $result->successful ? null : implode('; ', $result->errors),
                'attempted_at' => now(),
            ]);
        });
    }
}
