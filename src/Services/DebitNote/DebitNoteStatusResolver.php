<?php

namespace Zatca\Services\DebitNote;

use Zatca\Enums\ZatcaDebitNoteDisplayStatus;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;

/**
 * Derives the status shown to an admin for one Debit Note. Line-for-line the same
 * derivation as CreditNoteStatusResolver, and deliberately so: the lifecycle of a
 * note's TECHNICAL document (generate, sign, report, retry, regenerate) is
 * identical for both kinds — only the business meaning of acceptance differs, and
 * that is not a status question.
 *
 * No new ZatcaInvoiceStatus values were added; that enum belongs to the reporting
 * pipeline and is shared with plain invoices. This class is where "Rejected" and
 * "Generation Failed" come from, by reading the same facts RegenerationPolicy
 * reads to tell a transient failure from a rejection.
 */
class DebitNoteStatusResolver
{
    public function resolve(ZatcaDebitNote $debitNote): ZatcaDebitNoteDisplayStatus
    {
        if ($debitNote->isCancelled()) {
            return ZatcaDebitNoteDisplayStatus::Cancelled;
        }

        if ($debitNote->isAccepted()) {
            return ZatcaDebitNoteDisplayStatus::Accepted;
        }

        // Checked before looking at any technical document: a stranded, never-signed
        // Draft can exist alongside a recorded generation failure (the Draft survives
        // exactly so a retry can resume it — see ProductionInvoiceOrchestrator), and
        // that Draft's own status (still "draft") must not read as merely "Pending".
        if ($debitNote->generation_failed_at !== null) {
            return ZatcaDebitNoteDisplayStatus::GenerationFailed;
        }

        /** @var ZatcaInvoice|null $document */
        $document = $debitNote->latestZatcaInvoice;

        if (! $document) {
            return ZatcaDebitNoteDisplayStatus::Pending;
        }

        // Reporting (B2C) and Clearance (B2B) in-flight states side by side: both mean
        // "signed and queued or being retried, nothing definitive from ZATCA yet".
        // ClearanceInDoubt is included here too — a network failure means whether ZATCA
        // received the document is unknown, which is the same "keep waiting" story as a
        // retry, never a rejection.
        if ($document->isGenerationInFlight() || in_array($document->status, [
            ZatcaInvoiceStatus::Draft,
            ZatcaInvoiceStatus::PendingReporting,
            ZatcaInvoiceStatus::Retrying,
            ZatcaInvoiceStatus::PendingClearance,
            ZatcaInvoiceStatus::ClearanceRetrying,
            ZatcaInvoiceStatus::ClearanceInDoubt,
        ], true)) {
            return ZatcaDebitNoteDisplayStatus::Processing;
        }

        // ClearanceRejected IS the definitive ZATCA verdict (NOT_CLEARED) — unlike a
        // Reporting ManualReview, there is no ambiguous HTTP status to weigh: ZATCA read
        // the document and refused it.
        if ($document->status === ZatcaInvoiceStatus::ClearanceRejected) {
            return ZatcaDebitNoteDisplayStatus::Rejected;
        }

        if (! in_array($document->status, [ZatcaInvoiceStatus::Failed, ZatcaInvoiceStatus::ManualReview, ZatcaInvoiceStatus::ClearanceManualReview], true)) {
            return ZatcaDebitNoteDisplayStatus::Pending;
        }

        $httpStatus = $document->latestSubmissionAttempt?->http_status;

        // Same test RegenerationPolicy and ReportsZatcaInvoice use: no attempt, HTTP 0,
        // 5xx, or 401/403 means ZATCA never judged the document — the trip failed, not
        // the document. Anything else in 4xx is a genuine rejection.
        $isTransientOrCredentials = $httpStatus === null
            || (int) $httpStatus === 0
            || (int) $httpStatus >= 500
            || (int) $httpStatus === 401
            || (int) $httpStatus === 403;

        return $isTransientOrCredentials
            ? ZatcaDebitNoteDisplayStatus::Failed
            : ZatcaDebitNoteDisplayStatus::Rejected;
    }
}
